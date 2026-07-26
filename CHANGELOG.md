# Changelog

All notable changes to `laravel-running-number` will be documented in this file.

## v2.0.0 - 2026-07-26

### Breaking changes

Migrations are no longer run automatically. You must now publish and run them explicitly:

```bash
php artisan vendor:publish --tag="laravel-running-number-migrations"
php artisan migrate

```
**Upgrading from v1.x:** run the two commands above before deploying — the package no longer creates its tables for you automatically on `php artisan migrate`.

## v1.0.0 - 2026-07-25

Initial release.

- Sequential, zero-padded running numbers per `type`, generated under a database row lock for gap-safe concurrency
- Configurable reset intervals (daily/weekly/monthly/quarterly/yearly/never) with custom anchors — e.g. a yearly counter that resets every Dec 14th instead of Jan 1st
- Database-backed skip and undo, so reserved/voided numbers can be stepped over or reclaimed without breaking the sequence
- `php artisan running-number:sync` to upsert config-declared types
- `RunningNumber::fake()` testing helper with `assertGenerated()`, `assertNotGenerated()`, and `assertRegistered()`
