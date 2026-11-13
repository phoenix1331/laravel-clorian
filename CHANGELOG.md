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
- `Weighing::accepted(question, value)`: true for an accepted answer, or for the value a human confirmed or corrected
- `ThresholdResolver`: turns scores into an `Answer` per question (option, value, confidence, scores, thresholds in force, status), with threshold precedence tuned option, tuned question, policy option, policy question, config default; equality counts as reaching a threshold
- `ResolvedAnswers` with property access, `accepted()`, `needingReview()`, `withStatus()` and `values(defaults)`, the accepted values over the policy defaults for `apply()`
- `TunedThresholds` lookup contract, with `EloquentTunedThresholds` reading `clorian_thresholds` in one query and `NoTunedThresholds` for code without a database
- `DriverException` with a retryable flag, status, request id and `retry-after`
- `InvalidThresholds`, naming the policy, question and option, for out-of-range or crossed thresholds, 2 to 255 options, duplicate or unknown options and over-long names
- `Weighing`, `WeighingAnswer` and `TunedThreshold` models with factories, and the `Status`, `ReviewAction` and `WeighingStatus` enums

[Unreleased]: https://github.com/phoenix1331/laravel-clorian/commits/main
