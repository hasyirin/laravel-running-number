# Changelog

All notable changes to `laravel-running-number` will be documented in this file.

## v1.0.0 - 2026-07-25

Initial release.

- Sequential, zero-padded running numbers per `type`, generated under a database row lock for gap-safe concurrency
- Configurable reset intervals (daily/weekly/monthly/quarterly/yearly/never) with custom anchors — e.g. a yearly counter that resets every Dec 14th instead of Jan 1st
- Database-backed skip and undo, so reserved/voided numbers can be stepped over or reclaimed without breaking the sequence
- `php artisan running-number:sync` to upsert config-declared types
- `RunningNumber::fake()` testing helper with `assertGenerated()`, `assertNotGenerated()`, and `assertRegistered()`
