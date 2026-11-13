# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `config/clorian.php` with driver, mode, input storage, default threshold and pruning settings
- Migrations for `clorian_weighings`, `clorian_answers` and `clorian_thresholds`, published with the `clorian-migrations` tag
- `Question::choice()` (option names or name => description) and `Question::boolean()` with fluent `instructions()`, `accept()`, `review()`, `alwaysReview()`, `option()` and, for booleans, `when()`
- `Policy` base class: `questions()`, `defaults()`, `context()`, `redact()` and `apply()`, resolved from the container and validated on first use (thresholds over the config defaults, unique question names, defaults that match an option or a boolean)
- `Driver` contract (`score(questions, content, context, ?timeout): Scores`) for plugging in other providers, and `Scores` with probabilities, resolved model version, latency, tokens and request id
- `FakeDriver` for tests and offline demos: `answer()`, `distribution()`, `using()`, `fail()`, recorded `calls()`, even spread for unscripted questions
- `JevDriver`: scores every question in one `POST /systemone` call, maps booleans to `true`/`false`, records the resolved model version, latency, tokens and request id, trims input to `max_context_tokens` (context first), and turns timeouts, rate limits and error responses into `DriverException`s without the key or the content sent
- `drivers.jev.max_context_tokens` config key (default 24000) and a `truncated` flag on `Scores`
- `Clorian::now(policy, input, ?model)`: weighs in one driver call, stores the weighing and every answer with the thresholds in force, and runs `apply()` with only accepted answers over the defaults; any driver failure or `sync_timeout` stores a failed weighing and applies the defaults, so the caller is never blocked
- `Clorian::driver()` resolves `clorian.driver` (`jev`, `fake` or your own through `Clorian::extend()`), and the `Clorian` facade
- `Clorian::fake()` for app tests: scripted `answer()`, `distribution()` and `fail()`, weighings still stored and policies applied, and `assertWeighed()`, `assertNotWeighed()`, `assertWeighedTimes()` (each for a subject or a callback), `assertNothingWeighed()` and `weighings()`
- `#[Weigh(Policy::class, fields: [...])]` model attribute (inherited by subclasses) and `MissingWeighAttribute` with a fix-it message
- `HasWeighings` trait: `weighings`, `latestWeighing`, `answer(question)`, `weighNow()`, `weighInput()`, `weighPolicy()` and the `pendingReview()` scope (models whose latest weighing has an unreviewed answer in review)
- Models with `HasWeighings` are weighed when created or when a `#[Weigh]` field changes: queued (`WeighSubject` after the transaction commits, on `clorian.queue`) or inline when `clorian.mode` is `sync`; content already weighed successfully is skipped, and `clorian.enabled` turns it off
- `WeighSubject` job: 3 tries, backoff of 10 then 60 seconds (or the driver's `retry-after`), permanent driver errors fail at once; after the last attempt the weighing is stored as failed and the policy defaults apply
- `Policy::redact()` runs on the input and the policy context before the driver sees them; the default `Redactor` replaces email addresses, phone numbers and long digit runs with `[email]`, `[phone]` and `[number]`
- `clorian.store_input` (`none`, `subjectless` by default, `always`) keeps the redacted input, encrypted, on each weighing, including failed ones
- `Clorian::review(answer, action, value, by)`: records an upheld, overturned or missed decision with the reviewer and time, rejects values that do not fit the question or action (`InvalidReview`), runs the policy's `apply()` again with the human decisions in place, and fires `AnswerReviewed`; reviewing again replaces the earlier decision
- `Calibrator`: per policy, question and option, answer counts by status, upheld, overturned and missed rates, rates by confidence band (0.60-0.70, 0.70-0.80, 0.80-0.90, 0.90+) and a suggested accept threshold once enough reviews meet the target; `clorian.calibration.target` (0.95) and `clorian.calibration.min_sample` (30)
- `Observations` contract with a database reader that streams stored answers in chunks, by policy and since a date
- `clorian:install`: publishes the config and migrations, asks for the mode and whether to migrate (skipped with `--no-interaction`) and prints the `.env` keys
- `clorian:ping`: one sample weighing through the configured driver, reporting the key (set or missing, never its value), the resolved model version, latency and request id, with a warning to pin `jev-latest`
- `clorian:add-column {table}`: generates a migration for an indexed `clorian_status` column (`complete`, `review` or `failed`); with `clorian.status_column.enabled` (`CLORIAN_STATUS_COLUMN`, off by default) it is kept in sync after every weighing and review, without model events, and `pendingReview()` reads it
- `clorian:rescan {model} {--since=} {--chunk=500} {--sync}`: weighs existing models again, queued or inline, in chunks
- `clorian:prune {--pretend}`: deletes weighings past `weighings_after_days` and those of deleted subjects, and clears stored input past `inputs_after_days` while answers and stats stay; schedule it daily
- Re-weighing unchanged content (`clorian:rescan`, `weighNow()`) keeps human review decisions: they carry onto the new answers, judged against the new answer, and `apply()` keeps using them
- `clorian:stats {policy?} {--days=30}`: per policy, answer counts, review rates, the accept threshold in force and the suggested one, plus upheld rates by confidence band
- `clorian:tune {policy} {question} {--option=} {--accept=} {--review=}`: validates the new thresholds, shows them before and after, warns when an option would stop always going to review, asks before saving (or saves under `--no-interaction`) and records who tuned it when someone is signed in; `--accept=never` means always review
- Events `AnswerAccepted`, `AnswerNeedsReview`, `AnswerReviewed` and `WeighingFailed`: ids and scalars only, never the weighed content, dispatched after the surrounding transaction commits
- `Weighing::accepted(question, value)`: true for an accepted answer, or for the value a human confirmed or corrected
- `ThresholdResolver`: turns scores into an `Answer` per question (option, value, confidence, scores, thresholds in force, status), with threshold precedence tuned option, tuned question, policy option, policy question, config default; equality counts as reaching a threshold
- `ResolvedAnswers` with property access, `accepted()`, `needingReview()`, `withStatus()` and `values(defaults)`, the accepted values over the policy defaults for `apply()`
- `TunedThresholds` lookup contract, with `EloquentTunedThresholds` reading `clorian_thresholds` in one query and `NoTunedThresholds` for code without a database
- `DriverException` with a retryable flag, status, request id and `retry-after`
- `InvalidThresholds`, naming the policy, question and option, for out-of-range or crossed thresholds, 2 to 255 options, duplicate or unknown options and over-long names
- `Weighing`, `WeighingAnswer` and `TunedThreshold` models with factories, and the `Status`, `ReviewAction` and `WeighingStatus` enums

### Security

- Jev connection errors are not chained to the HTTP client's exception, which holds the request with the API key and the content

[Unreleased]: https://github.com/phoenix1331/laravel-clorian/commits/main
