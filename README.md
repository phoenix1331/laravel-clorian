<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/phoenix1331/laravel-clorian/main/docs/images/clorian-logo-light.webp">
  <img alt="Clorian" src="https://raw.githubusercontent.com/phoenix1331/laravel-clorian/main/docs/images/clorian-logo-dark.webp" width="320">
</picture>

Clorian is Welsh for scales.

**Confidence-aware typed decisions for Laravel: let Jev make the obvious calls, send the unsure ones to a person, and measure when to trust it more.**

[![Tests](https://github.com/phoenix1331/laravel-clorian/actions/workflows/tests.yml/badge.svg)](https://github.com/phoenix1331/laravel-clorian/actions/workflows/tests.yml)
[![Static analysis](https://github.com/phoenix1331/laravel-clorian/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/phoenix1331/laravel-clorian/actions/workflows/static-analysis.yml)
[![Release](https://img.shields.io/github/v/release/phoenix1331/laravel-clorian.svg)](https://packagist.org/packages/phoenix1331/laravel-clorian)
[![Licence](https://img.shields.io/badge/licence-MIT-blue.svg)](https://github.com/phoenix1331/laravel-clorian/blob/main/LICENSE.md)

Plenty of decisions in an app come down to reading some text. Clorian turns that text into typed answers your code can act on, for any Eloquent model or any input:

- **Support tickets:** which team, how urgent, does a person need to reply
- **Marketplace listings:** which category, might it be prohibited
- **Reviews and comments:** spam or not, what the customer is unhappy about
- **Refunds and claims:** what the reason is, does a manager need to see it
- **Contact forms and leads:** sales, support or spam, answered while the visitor waits
- **Content tagging:** topic and audience for posts, documents or products

A policy declares the questions. [Jev](https://typesafe.ai), TypeSafe's typed decision model, answers all of them in one call with a probability for every option. Each answer is checked against its own thresholds: confident answers apply themselves, unsure ones wait for a person, the rest fall back to your defaults. Every human review is recorded, so Clorian can tell you when an answer has earned more trust.

Full documentation: **[phoenix1331.github.io/laravel-clorian](https://phoenix1331.github.io/laravel-clorian)**, including [a worked example](https://phoenix1331.github.io/laravel-clorian/#example) built on it.

## Why

A model can read text, but some of its answers will be wrong, and a label alone does not tell you which. Clorian puts a threshold on every answer and keeps score.

- **Thresholds per question and per option.** Auto-accepting "critical" urgency wakes someone up, so it can always go to a person while "low" is accepted sooner.
- **Calibration from real reviews.** `clorian:stats` shows how often people agreed with Jev at each confidence, and suggests where a threshold can move.
- **Model events, not middleware.** Models saved from jobs, APIs, Livewire and console commands are weighed too, not just web requests.
- **Private by default.** Emails, phone numbers and long digit runs are redacted before anything leaves your app, and stored input is encrypted.
- **Headless and swappable.** No views or routes. Jev sits behind a driver contract, with a fake so your tests never call the API.

## Installation

```bash
composer require phoenix1331/laravel-clorian
php artisan clorian:install   # publishes config and migrations, asks queued or sync, runs migrate
```

Add your key to `.env`, then check it:

```env
JEV_API_KEY=                       # your TypeSafe API key
JEV_MODEL=jev-latest               # pin the version clorian:ping reports, e.g. jev-1.14.0
CLORIAN_MODE=queued                # queued (in a job after the save) or sync (during the save)
```

```bash
php artisan clorian:ping
```

Pin `JEV_MODEL` to the version `clorian:ping` reports: `jev-latest` moves without notice, which shifts confidences and invalidates calibration.

## How it works

The flow is the same for any model. Here it is with a support ticket:

1. **The ticket is saved.** The `HasWeighings` trait has already registered Clorian's model listener; there is nothing to wire up.
2. **Clorian queues a job** once the save commits (in sync mode it weighs during the save), redacts the `#[Weigh]` fields and asks Jev every question in the policy at once.
3. **Each answer is checked against its thresholds.** Billing at 0.98 is accepted; normal urgency at 0.71 waits for a person.
4. **Your policy's `apply()` runs** with the accepted answers and your defaults for the rest, and events fire for anything listening.
5. **A person reviews the urgency.** `apply()` runs again with their decision, and the review counts towards calibration.

| You want to | Write | Who calls it |
|---|---|---|
| Weigh a model when it is saved | `#[Weigh(...)]` and `use HasWeighings` | Clorian, on create and when those fields change |
| Change the model from the answers | Your policy's `apply()` | Clorian, after every weighing and every review |
| Notify, page someone, send a reply | An event listener (optional) | Laravel, when Clorian fires the event |
| Decide about data with no model | `Clorian::now()` | You, where the data arrives |
| Let people review answers | Your own screen, calling `Clorian::review()` | You: Clorian is headless |

`apply()` runs after every weighing and every review, and again on `weighNow()` and `clorian:rescan`, so make it set values (a queue, a priority) that are safe to set twice. Put anything that must happen once, such as an email, in a listener.

## Usage

The worked example routes support tickets. Mark the model with the policy that weighs it and the fields to send:

```php
use Phoenix1331\Clorian\Attributes\Weigh;
use Phoenix1331\Clorian\Concerns\HasWeighings;

#[Weigh(TicketTriage::class, fields: ['subject', 'body'])]
class Ticket extends Model
{
    use HasWeighings;
}
```

The policy declares the questions, the defaults for answers that cannot be trusted, and what to do with the result:

```php
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Policies\Policy;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Resolution\Answers;

class TicketTriage extends Policy
{
    public function questions(): array
    {
        return [
            // Config defaults: accept 0.90, review 0.60.
            Question::choice('category', [
                'billing' => 'Invoices, payments, refunds',
                'bug' => 'Something in the product is broken',
                'account' => 'Logging in, users, access',
                'feature' => 'Asking for something new',
            ])->instructions('Which team should handle the ticket in `content`?'),

            Question::choice('urgency', ['low', 'normal', 'high', 'critical'])
                ->accept(0.90)->review(0.60)
                ->option('critical', fn (ThresholdOverrides $o) => $o->alwaysReview())
                ->option('low', fn (ThresholdOverrides $o) => $o->accept(0.75)),

            // An accept threshold must be above the review threshold (0.60 by default).
            Question::boolean('needs_human')->accept(0.70),
        ];
    }

    public function defaults(): array
    {
        return ['category' => null, 'urgency' => 'normal', 'needs_human' => false];
    }

    public function apply(?Model $subject, Answers $answers): void
    {
        $subject?->update([
            'queue' => $answers->get('category') ?? 'triage',
            'priority' => $answers->get('urgency'),
        ]);
    }
}
```

The model then gives you its history and a review queue:

```php
$ticket->latestWeighing;          // the most recent weighing, with its answers
$ticket->answer('urgency');       // the latest answer to one question
$ticket->weighNow();              // weigh again, inline
Ticket::pendingReview()->get();   // models with an answer waiting for a person
```

For input with no model, weigh it while the visitor waits. Sync calls time out after 2 seconds and fall back to the defaults:

```php
use Phoenix1331\Clorian\Facades\Clorian;

$weighing = Clorian::now(ContactIntent::class, $request->message);

if ($weighing->accepted('intent', 'account_access')) {
    return redirect()->route('help.password');
}
```

React to outcomes with ordinary listeners. `AnswerAccepted`, `AnswerNeedsReview`, `AnswerReviewed` and `WeighingFailed` fire after the save commits and carry ids and values, never the content.

## Review and calibration

Record a human decision from your own UI; `apply()` runs again with it in place:

```php
use Phoenix1331\Clorian\Reviews\ReviewAction;

Clorian::review($answer, ReviewAction::Upheld, null, $request->user());       // Jev was right
Clorian::review($answer, ReviewAction::Overturned, 'high', $request->user()); // the right option
Clorian::review($answer, ReviewAction::Missed, null, $request->user());       // no option fits
```

`clorian:stats` shows how often people agreed with Jev, by confidence band, and suggests an accept threshold once at least 30 reviews from a band upwards are upheld 95% of the time:

![clorian:stats printing answer counts, upheld, overturned and missed rates, and upheld rates by confidence band](https://raw.githubusercontent.com/phoenix1331/laravel-clorian/main/docs/images/stats.gif)

`clorian:tune` applies a threshold, showing the values before and after and asking first:

![clorian:tune raising the accept threshold for low urgency from 0.75 to 0.85 after a confirm](https://raw.githubusercontent.com/phoenix1331/laravel-clorian/main/docs/images/tune.gif)

## Commands

| Command | What it does |
|---|---|
| `clorian:install` | Publishes the config and any missing migrations, asks for the mode, prints the `.env` keys. Safe to run again |
| `clorian:ping` | One sample call: whether the key is set, the version that answered, latency |
| `clorian:stats {policy?} --days=30` | Counts, review rates, rates by confidence band, suggested thresholds |
| `clorian:tune {policy} {question} --option= --accept= --review=` | Changes a threshold after showing before and after; `--accept=never` always reviews |
| `clorian:rescan {model} --since= --chunk=500 --sync` | Weighs existing models again, keeping human decisions on unchanged content |
| `clorian:prune --pretend` | Deletes old weighings and those of deleted models, clears old stored input. Schedule it daily |
| `clorian:add-column {table}` | Writes a migration for an indexed `clorian_status` column that `pendingReview()` reads, for busy apps |

## Configuration

`config/clorian.php`, published by `clorian:install`:

| Key | Default | Environment variable |
|---|---|---|
| `driver` | `jev` | `CLORIAN_DRIVER` (`jev`, `fake` or your own) |
| `drivers.jev.key` | none | `JEV_API_KEY` |
| `drivers.jev.model` | `jev-latest` | `JEV_MODEL` |
| `mode` | `queued` | `CLORIAN_MODE` (`queued` or `sync`) |
| `queue` | `default` | `CLORIAN_QUEUE` |
| `sync_timeout` | `2.0` seconds | |
| `store_input` | `subjectless` | `CLORIAN_STORE_INPUT` (`none`, `subjectless` or `always`) |
| `default_thresholds` | accept `0.90`, review `0.60` | |
| `calibration` | target `0.95`, min_sample `30` | |
| `prune` | weighings after 90 days, input after 30 | |

The [documentation](https://phoenix1331.github.io/laravel-clorian/#configuration) lists every key.

## Testing your app

`Clorian::fake()` swaps in a scripted driver. Weighings still run for real (thresholds, storage, `apply()`), nothing leaves your app, and every weighing is recorded:

```php
Clorian::fake()
    ->answer('category', 'billing', 0.95)
    ->answer('urgency', 'critical', 0.97);

$ticket = Ticket::create(['subject' => 'Charged twice', 'body' => 'Refund please']);

Clorian::assertWeighed(TicketTriage::class, $ticket);
```

## Data and privacy

Clorian sends the `#[Weigh]` fields (or the input to `Clorian::now()`) and your policy's `context()` to TypeSafe, after redaction, with your key in the `Authorization` header only. It stores answers, confidences, thresholds and reviews; the input itself only as `store_input` allows, redacted and encrypted. Events, exceptions, logs and queued jobs never carry content. [SECURITY.md](https://github.com/phoenix1331/laravel-clorian/blob/main/SECURITY.md) sets out exactly what is sent and stored.

## Compatibility

| | Supported |
|---|---|
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 12, 13 |
| Databases | SQLite, PostgreSQL, MySQL, MariaDB |
| Jev | Tested with `jev-1.14.0` (what `jev-latest` points to as of November 2026) |

## Contributing

```bash
composer install
composer check   # Pint, Larastan at max level, Pest with a 90% coverage gate
```

See [CONTRIBUTING.md](https://github.com/phoenix1331/laravel-clorian/blob/main/CONTRIBUTING.md), [CHANGELOG.md](https://github.com/phoenix1331/laravel-clorian/blob/main/CHANGELOG.md) and [SECURITY.md](https://github.com/phoenix1331/laravel-clorian/blob/main/SECURITY.md). Clorian is a sibling of [laravel-jev-moderation](https://github.com/phoenix1331/laravel-jev-moderation) and shares its `JEV_API_KEY` and `JEV_MODEL`.

## Licence

MIT. See [LICENSE.md](https://github.com/phoenix1331/laravel-clorian/blob/main/LICENSE.md).
