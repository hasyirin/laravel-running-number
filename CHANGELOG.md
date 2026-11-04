# Changelog

All notable changes to `laravel-running-number` will be documented in this file.

## v3.1.1 - 2026-11-04

### Changes

- The public API has stricter PHPDoc types: array shapes and generics on the contract, the manager, the facade and `RunningNumber::fake()`. If your code passes wrong types, your static analysis can now report errors. The package code works the same.

### Notes

- Contributors: PHPStan now runs at level 10. `larastan/larastan` is `^3.14.0` and `pestphp/pest` is `^5.3.5`.

## v3.1.0 - 2026-11-01

### Breaking changes

- Requires Laravel 13.39 or later. On Laravel 13.30 to 13.38, Composer stays on v3.0.0.

### Docs

- SQLite: with Laravel's default `transaction_mode`, calls can fail with `database is locked` when several processes use the database at the same moment. They do not issue a wrong number. To prevent the error, set `'transaction_mode' => 'IMMEDIATE'` on the `sqlite` connection. See "Concurrency and gaps" in the README.
- The v3.0.0 upgrade steps now drop the child tables first. The old order failed on foreign keys.

### Notes

- Contributors: the Pest 4 constraints are removed. The package code is the same as in v3.0.0.

## v3.0.0 - 2026-10-31

### Breaking changes

- Requires Laravel 13.30 or later. Laravel 11, Laravel 12 and earlier 13.x releases are no longer supported.
- There is no upgrade path from v1 or v2. See "Upgrading" below.
- `last_reset_at` is now stored in UTC. The model still returns it in app time. A write that skips the model, for example `DB::table()->update()`, must use UTC.
- All time columns are now `datetime` instead of `timestamp`, and `last_reset_at` has no database default. The model sets it when it creates a row, also when model events are off. An insert that skips the model, for example `DB::table()->insert()`, must set it.
- `register()` on an existing type now changes only the settings you pass. `running-number:sync` still replaces all settings.
- For a new type, `register()` now starts from the type's settings in `running-number.types`, and `preview()` uses them for a type that has no row yet. Before, both used the defaults.
- After a type switches from `UndoMode::RECLAIM` to `UndoMode::RECORD`, `generate()` no longer reissues numbers that were undone before the switch.
- `running-number:sync` fails, and syncs nothing, on an unknown setting key or on a type whose settings are not an array. Before, a list such as `['invoice', 'permit']` registered types named `0` and `1`.
- `RunningNumber::fake()` now throws the same exceptions as the real manager and supports `UndoMode::RECORD`. Register types in your tests, or declare them in `running-number.types`.
- `skip()`, `useSkipped()` and `undo()` apply a due reset first. `undo()` rejects 0 and negative numbers. `register()` rejects unknown timezones and negative padding, and `->padding()` rejects negative values.
- With `auto_register` on, a type declared in `running-number.types` is registered with its declared settings instead of the defaults.
- `register()` and `auto_register` create new types with an upsert, so Eloquent `creating`/`created` events do not fire for them.
- `Contracts\RunningNumber::registerFor()` has default values and a new `bool $replace = false` parameter.

### Fixes

- Migrations now work on MySQL and MariaDB. Publish them with `--tag="running-number-migrations"`.
- On MySQL and MariaDB, calls inside a longer transaction could read old skip and undo data and issue a number twice. They now lock what they read.
- The package retries its own transaction after a deadlock, with up to 3 attempts in total. For example, on MySQL and MariaDB, `skip()` or `undo()` on two types at the same moment can deadlock.
- A `register()` call at the same moment as `running-number:sync` or another `register()` no longer overwrites the new type's settings with the defaults.
- A per-type timezone no longer shifts the saved reset time, which could issue duplicate numbers or skip a reset.
- A type created through the model, for example with `updateOrCreate()`, no longer gets the database clock's time as its last reset time.
- An app timezone with daylight saving time no longer resets a counter on every call in the hour that repeats.
- Daylight saving time changes no longer reset a counter twice in one day.
- Changing a reset schedule no longer restarts the count, or undoes a setting that another request changed at the same time.
- `current()` shows the label a number was issued with when a reset is due but has not run yet.
- A reclaimed number can be undone again after `generate()` reissues it. Numbers issued with `useSkipped()` can be undone.
- `running-number:sync` is all-or-nothing.
- `auto_register` is safe when several requests use a new type at the same time.
- The reset decision and date tokens use one clock reading.
- `generate()` reads skipped numbers 1,000 at a time. Before, it ran one query for each skipped number in a row.

### New

- `{period:...}` format tokens, for example `{number}/{period:Y-m}` for a monthly or quarterly reset.

### Upgrading

There is no upgrade path from v1 or v2. Drop the `running_number_undos`, `running_number_skips` and `running_numbers` tables, in that order, and delete their rows in the `migrations` table. Then publish the migrations again with `--force` and run `php artisan migrate`. Every counter starts again from 0.

### Notes

- On MySQL and MariaDB, keep transactions around `generate()` short. If you call the package inside your own transaction, it cannot retry a deadlock for you: retry your transaction, for example with `DB::transaction($callback, attempts: 3)`.
- Contributors: to run the tests on MySQL, MariaDB or PostgreSQL, set `RUNNING_NUMBER_TEST_CONNECTION`. The tests always use a database named `running_number_testing`.

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
