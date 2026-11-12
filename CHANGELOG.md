# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `config/clorian.php` with driver, mode, input storage, default threshold and pruning settings
- Migrations for `clorian_weighings`, `clorian_answers` and `clorian_thresholds`, published with the `clorian-migrations` tag
- `Weighing`, `WeighingAnswer` and `TunedThreshold` models with factories, and the `Status`, `ReviewAction` and `WeighingStatus` enums

[Unreleased]: https://github.com/phoenix1331/laravel-clorian/commits/main
