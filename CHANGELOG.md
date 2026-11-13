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
- `DriverException` with a retryable flag, status, request id and `retry-after`
- `InvalidThresholds`, naming the policy, question and option, for out-of-range or crossed thresholds, 2 to 255 options, duplicate or unknown options and over-long names
- `Weighing`, `WeighingAnswer` and `TunedThreshold` models with factories, and the `Status`, `ReviewAction` and `WeighingStatus` enums

[Unreleased]: https://github.com/phoenix1331/laravel-clorian/commits/main
