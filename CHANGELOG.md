# Changelog

All notable changes to `laravel-running-number` will be documented in this file.

## v2.1.0 - 2026-10-28

### Dependencies

- The minimum `spatie/laravel-package-tools` version is now `^1.93.3` (was `^1.16`). It still supports every Laravel version this package supports (11, 12 and 13).
- The test suite now supports Pest 5 on Laravel 13. Laravel 12 keeps Pest 4, because Pest 5 does not install on Laravel 12.
- All dev dependency minimums are raised to their latest releases.

No code changes. No upgrade steps are needed.

## v2.0.0 - 2026-07-26

### Breaking changes

Migrations are no longer run automatically. You must now publish and run them explicitly:

```bash
php artisan vendor:publish --tag="running-number-migrations"
php artisan migrate
```

**Upgrading from v1.x:** v1 already created the tables, so `php artisan migrate` fails with "table already exists" on the published migrations. Publish them, then mark them as run before you migrate. v1 saved them in the `migrations` table without a date prefix, so rename those rows to the published file names:

```php
// php artisan tinker
foreach (['create_running_numbers_table', 'create_running_number_skips_table', 'create_running_number_undos_table'] as $name) {
    $published = glob(database_path("migrations/*_{$name}.php"))[0] ?? throw new RuntimeException("Publish the migrations first: {$name} is missing.");
    DB::table('migrations')->where('migration', $name)->update(['migration' => basename($published, '.php')]);
}
```

## v1.0.0 - 2026-07-25

Initial release.

- Sequential, zero-padded running numbers per `type`, generated under a database row lock for gap-safe concurrency
- Configurable reset intervals (daily/weekly/monthly/quarterly/yearly/never) with custom anchors — e.g. a yearly counter that resets every Dec 14th instead of Jan 1st
- Database-backed skip and undo, so reserved/voided numbers can be stepped over or reclaimed without breaking the sequence
- `php artisan running-number:sync` to upsert config-declared types
- `RunningNumber::fake()` testing helper with `assertGenerated()`, `assertNotGenerated()`, and `assertRegistered()`
