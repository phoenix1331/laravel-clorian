# Contributing to Clorian for Laravel

Thanks for helping. Bug reports, fixes and new features are all welcome. Questions are best asked in [GitHub Discussions](https://github.com/phoenix1331/laravel-clorian/discussions) rather than issues.

## Setup

Prerequisites: PHP 8.3+ with `mbstring`, `intl` and `pdo_sqlite`, Composer 2, Node and npm. `osv-scanner` is required by the pre-commit hook (`go install github.com/google/osv-scanner/cmd/osv-scanner@latest`).

On Ubuntu or WSL:

```bash
sudo apt install php8.4-cli php8.4-mbstring php8.4-intl php8.4-sqlite3 php8.4-xml php8.4-curl php8.4-pcov
```

On macOS, with [Homebrew](https://brew.sh) (its PHP already includes `mbstring`, `intl` and SQLite):

```bash
brew install php composer node osv-scanner
pecl install pcov   # coverage driver for composer test:coverage
```

[Laravel Herd](https://herd.laravel.com) works too if you already use it for PHP; add a coverage driver (Herd Pro's Xdebug, or `pcov` through `pecl`) before running `composer test:coverage`.

Everything runs on the host: there is no Docker setup and no database server to start.

```bash
git clone https://github.com/phoenix1331/laravel-clorian
cd laravel-clorian
composer install
npm install   # activates the git hooks
```

## Running checks

| Command | What it does |
|---|---|
| `composer test` | The Pest suite. Never calls a real API |
| `composer test:coverage` | Tests with coverage; fails below 90% on `src/` (needs `pcov` or `xdebug`) |
| `composer lint` | Fixes code style with Pint |
| `composer lint:test` | Checks code style without changing files |
| `composer analyse` | Larastan at max level |
| `composer check` | Style check, static analysis and the coverage gate, in that order |

The tests use Pest with Orchestra Testbench and an in-memory SQLite database, so nothing else needs to be running. Every PHP file declares `strict_types`; Pint adds it if you forget.

CI also runs the suite on PostgreSQL, MySQL and MariaDB, because Clorian owns tables. To try one locally, point the tests at a running server:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=secret composer test
```

Write database tests that pass everywhere: do not assert JSON key order (MySQL sorts keys), wrap a deliberate constraint violation in `DB::transaction()` (PostgreSQL aborts the test's transaction otherwise), and never alter a table inside a test (MySQL commits on `ALTER TABLE`).

## Trying it in a real app

The tests never call Jev. To see Clorian end to end, build a local `test-app/` (gitignored and never shipped): a Laravel app with the Vue starter kit that installs this package from `../` as a symlink, so your edits show up at once.

```bash
composer create-project laravel/vue-starter-kit:dev-main test-app
cd test-app
composer config repositories.clorian '{"type":"path","url":"../","options":{"symlink":true}}'
composer require "phoenix1331/laravel-clorian:@dev"
php artisan clorian:install
```

Add `JEV_API_KEY` to `test-app/.env` (never commit it), then `php artisan clorian:ping`. Set `CLORIAN_DRIVER=fake` to work offline.

## Recordings

The terminal GIFs in `docs/images/` come from the VHS tapes in `art/tapes/`. With [VHS](https://github.com/charmbracelet/vhs) installed (`brew install vhs` on macOS, which brings `ttyd` and `ffmpeg`; `go install github.com/charmbracelet/vhs@latest` plus both of those on Linux) and a seeded `test-app/` that has a Jev key, re-record them all with:

```bash
composer record
```

## Git hooks

`npm install` installs two Husky hooks:

- `commit-msg` - commit messages must be entirely lowercase and follow [Conventional Commits](https://www.conventionalcommits.org/) without a scope, e.g. `feat: add report command`. `chore(deps): ...` is rejected
- `pre-commit` - runs `pint --test` and an `osv-scanner` audit of `composer.lock`

## Changelog

Every user-facing change adds a line under `## [Unreleased]` in `CHANGELOG.md`, in the same pull request, using the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) headings (`Added`, `Changed`, `Fixed`, `Removed`, `Deprecated`, `Security`). Internal refactors and test-only changes do not need an entry.

## Pull requests

- Keep each pull request to one change
- Include tests; coverage must stay at or above 90%
- `composer check` must pass
- Fill in the pull request checklist

Releases are covered in [RELEASING.md](RELEASING.md).
