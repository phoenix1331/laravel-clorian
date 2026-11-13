# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.x     | Latest minor only |

While Clorian for Laravel is pre-1.0, fixes are released on the latest minor version only.

## Reporting a Vulnerability

Please do not open a public GitHub issue for security vulnerabilities.

Report security issues by emailing **darren@phoenix1331.co.uk**. Include:

- A description of the vulnerability and its potential impact
- Steps to reproduce or a proof-of-concept
- The package version affected, plus your Laravel and PHP versions

You will receive an acknowledgement within 48 hours. If the issue is confirmed, a fix will be released as soon as possible and you will be credited in the changelog unless you prefer to remain anonymous.

## Scope

Clorian for Laravel sends content from your application to TypeSafe's Jev API to answer the questions in your policies, and records every answer. This section sets out what goes where, what is kept, and the defaults that matter.

### What is sent to TypeSafe

| Sent | When |
|---|---|
| The `#[Weigh]` fields of the model, as field name to value, **after `Policy::redact()`** | A weighed model is created, or one of its `#[Weigh]` fields changes; `weighNow()`; `clorian:rescan` |
| The input passed to `Clorian::now()`, after `Policy::redact()` | Each call |
| `Policy::context()`, also after `Policy::redact()` | With the input above |
| The policy's question instructions, option names and descriptions, and the model name (`JEV_MODEL`) | Every weighing |
| One fixed sample sentence | `clorian:ping` |

Requests go through Laravel's HTTP client to `JEV_BASE_URL` (default `https://api.typesafe.ai/v1`), with `JEV_API_KEY` in the `Authorization` header only. Nothing else about the model is sent. With the `fake` driver nothing leaves your application.

The default `redact()` replaces email addresses, phone numbers and long digit runs (card, account and order numbers) with `[email]`, `[phone]` and `[number]`. It is a floor, not a guarantee: names, addresses and anything specific to your domain need your policy's own `redact()`.

TypeSafe states that Jev is not trained on customer requests or responses; its data processing agreement and zero data retention terms are at [docs.typesafe.ai/legal](https://docs.typesafe.ai/legal). Check them against your own obligations before sending personal data.

### What is stored

`clorian_weighings`, one row per weighing:

| Column | Holds |
|---|---|
| `subject_type`, `subject_id` | Which model was weighed (null for `Clorian::now()` without a model) |
| `policy`, `driver`, `model_version`, `status` | The policy class, the driver, the Jev version that answered, complete or failed |
| `content_hash` | sha256 of the input, before redaction, so unchanged content is not weighed again |
| `input` | The **redacted** input, **encrypted** with your `APP_KEY`, only as `store_input` allows: `subjectless` (the default) for weighings without a model, `none` never, `always` for every weighing |
| `error` | The driver's reason for a failed weighing (never the content) |
| `meta` | Latency, input and output tokens, Jev's request id, and whether long input was trimmed |

`clorian_answers`, one row per question: the option Jev picked, its confidence, every option's probability, the thresholds in force, the status, and once someone reviews it, the decision, the reviewer (`reviewer_type`, `reviewer_id`) and the time. `clorian_thresholds` holds tuned thresholds and who tuned them.

Tests send a marked email, card number and API key through every Jev outcome (success, each error status, timeouts, a refused connection) and fail if any of them reaches an exception, a log entry, an event, a queued job payload or a plaintext database column.

### Potential concerns

**Content goes to a third party** - Choose `#[Weigh]` fields deliberately: every listed field is sent on every change, after redaction. Keep `context()` short; it is sent with every weighing.

**Accepted answers apply without a person** - An answer at or above its accept threshold is applied by your policy's `apply()` straight away. For options where a wrong call is costly, use `alwaysReview()` so a person confirms every one. Tuning a whole question overrides per-option settings; `clorian:tune` warns when an option would stop going to review.

**Failures fall back to defaults** - When Jev fails or times out (2 seconds for sync calls), the weighing is stored as failed and your policy's `defaults()` apply. Make the defaults the safe choice.

**The content hash is not anonymous** - `content_hash` is an unsalted sha256 of the unredacted input. It cannot be reversed, but anyone with the table can confirm whether a weighing matches text they already have, and short or predictable text can be guessed.

**Stored input depends on `APP_KEY`** - Input is encrypted with Laravel's encrypter. Rotating `APP_KEY` without listing the old key in `APP_PREVIOUS_KEYS` makes stored input unreadable. Set `store_input` to `none` if you do not need it.

**Reviewers are personal data** - `reviewer_type` and `reviewer_id` identify who decided. Include them in your retention policy.

**Retention and erasure** - `clorian:prune` deletes weighings older than `prune.weighings_after_days` (90 by default), clears stored input older than `prune.inputs_after_days` (30), and deletes weighings whose model no longer exists. Schedule it daily. To erase one model's data at once, delete its weighings; their answers go with them:

```php
use Phoenix1331\Clorian\Models\Weighing;

Weighing::query()->whereMorphedTo('subject', $ticket)->each->delete();
```

**Content saved without model events is never weighed** - Weighing runs on Eloquent's `created` and `updated` events. `saveQuietly()`, `Model::withoutEvents()`, seeders using `WithoutModelEvents` (Laravel's default `DatabaseSeeder` does), raw `DB::table()` inserts and imports bypass it. Run `clorian:rescan` after bulk imports.

**API key** - Read from `JEV_API_KEY` in `config/clorian.php` only. It is never logged, stored, put in events, or printed (`clorian:ping` shows "set" or "missing"). Connection errors are not chained to the HTTP client's exception, which holds the request and its `Authorization` header.

**Exception traces** - With PHP's `zend.exception_ignore_args` set to `0`, every exception's trace keeps the real arguments of each call, which can include the content being weighed and the HTTP request. PHP's production `php.ini` sets it to `1`; keep it that way in production so error reporters never receive them.

**Events** - `AnswerAccepted`, `AnswerNeedsReview`, `AnswerReviewed` and `WeighingFailed` carry ids, the policy, question, value, confidence and the subject's type and id. Never the content.

**No logging** - The package writes nothing to your logs.

**Dependency chain** - Runtime dependencies are `illuminate/*` components and `laravel/prompts` only. Keep `composer.lock` up to date and monitor it with `composer audit` or `osv-scanner`.

## Disclosure Policy

Once a fix is released, the vulnerability will be documented in `CHANGELOG.md` under the relevant version. We follow coordinated disclosure and will not publish details until a fix is available.
