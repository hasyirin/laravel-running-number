# Laravel Running Number

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hasyirin/laravel-running-number.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-running-number)
[![GitHub Tests Action Status](https://github.com/hasyirin/laravel-running-number/actions/workflows/run-tests.yml/badge.svg)](https://github.com/hasyirin/laravel-running-number/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/hasyirin/laravel-running-number/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/hasyirin/laravel-running-number/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/hasyirin/laravel-running-number.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-running-number)

A Laravel package for generating sequential, zero-padded running numbers — invoice numbers, permit numbers, or any other per-type counter that needs to reset on a schedule. Numbers are generated under a database row lock, so concurrent requests never get the same number, and issued or reserved numbers can be skipped or undone without breaking the sequence.

## Requirements

- PHP 8.4 or later
- Laravel 13.30 or later
- MySQL, MariaDB or PostgreSQL in production. SQLite works, but it does not lock rows.

## Installation

You can install the package via composer:

```bash
composer require hasyirin/laravel-running-number
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="running-number-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="running-number-config"
```

This is the contents of the published config file:

```php
<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;

return [

    // If true, generate() silently registers an unknown type instead of throwing
    // RunningNumberTypeNotRegistered: with its settings in 'types' below, or with the defaults.
    'auto_register' => false,

    'default_padding' => 5,

    'default_interval' => ResetInterval::NEVER,

    'default_format' => '{number}',

    // null = fall back to config('app.timezone').
    'default_timezone' => null,

    'default_undo_mode' => UndoMode::RECLAIM,

    // Statically-declared types, upserted into the database by:
    //   php artisan running-number:sync
    'types' => [
        // 'invoice' => [
        //     'padding' => 4,
        //     'interval' => ResetInterval::YEARLY,
        //     'resetMonth' => 12,
        //     'resetDay' => 14,
        // ],
    ],

];
```

## Usage

### Registering types

Every type (for example `'invoice'` or `'permit'`) must be registered before you can generate numbers for it. Register a type at runtime:

```php
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Facades\RunningNumber;

RunningNumber::for('invoice')->register(interval: ResetInterval::YEARLY, padding: 4);
```

Or declare it in the `types` config array and run:

```bash
php artisan running-number:sync
```

The two ways update an existing type differently:

- `register()` changes only the settings you pass. `register(padding: 6)` keeps the interval, anchors, format, timezone and undo mode. If you pass a different interval, the old anchors are cleared.
- `running-number:sync` makes each listed type match the config exactly. A setting that you remove from the config goes back to its default. An unknown key (for example `reset_interval` instead of `interval`) fails the command, and nothing is synced.

For a new type, `register()` starts from the type's settings in `running-number.types`, or from the defaults if the type is not there.

The config keys are `interval`, `padding`, `format`, `resetMonth`, `resetDay`, `resetWeekday`, `resetHour`, `undoMode` and `timezone`. They are the same as the `register()` arguments.

Each key in `types` is a type name, used exactly as written. For a model with a morph map alias, write the alias (for example `'invoice'`), not the model class.

`register()` cannot set a setting back to `null`, because it skips the arguments you do not pass. To clear a setting, pass the value that `null` stands for (for example `resetHour: 0`), or remove the setting from the config and run `running-number:sync`.

Registering keeps the current number, with one exception: if you change the reset schedule (`interval`, an anchor or `timezone`) of a type that is in use, a reset that is already due under the old schedule runs first. The new schedule then starts at that moment, so the count continues until the next boundary of the new schedule.

A schedule change can repeat `{period:...}` labels. For example, if you move a Dec-14 yearly reset to Dec 17 on Dec 16, the numbers of Dec 16 get the old year again, and the reset on Dec 17 starts the new year a second time. To keep the numbers unique, change the format at the same time, for example with a new prefix.

If you set `auto_register` to `true`, `generate()` registers an unknown type instead of throwing `RunningNumberTypeNotRegistered`. It uses the type's settings in `running-number.types`, or the defaults if the type is not there. All other methods still need a registered type.

### Generating

```php
RunningNumber::generate('invoice'); // "0001"
RunningNumber::generate('invoice'); // "0002"

RunningNumber::for('invoice')->generate(); // "0003"
```

A type does not have to be a string. Backed enums resolve to their value, models resolve to their morph class, and arrays or collections are joined with `:`. This gives each branch its own sequence:

```php
RunningNumber::generate([PermitType::Residential, $branch, $branch->id]); // type "residential:branch:5" with a "branch" morph map alias
```

A model on its own always resolves to its class, never to one record. Add the key yourself, as above, if you want one sequence per record.

On MySQL and MariaDB, type names compare with the column collation. Laravel's default `utf8mb4_unicode_ci` ignores case, so `'Invoice'` and `'invoice'` share one counter there, while PostgreSQL and SQLite treat them as two types. Use one spelling for each type.

### Formatting

A type's `format` is a template:

| Token | Renders |
|---|---|
| `{number}` | The number, zero-padded to the type's `padding` |
| `{Y}`, `{m}`, `{Y-m-d}`, … | Any [PHP date format](https://www.php.net/manual/en/datetime.format.php), for the current time in the type's timezone |
| `{period:Y}`, `{period:Y-m}`, … | The same date format, for the last day of the current reset period |

```php
RunningNumber::for('receipt')->register(interval: ResetInterval::YEARLY, format: 'RCP/{Y}/{number}');

RunningNumber::generate('receipt'); // "RCP/2026/00001"
```

**Choose date tokens that change exactly when the counter resets**, otherwise the same formatted number can appear twice. A Jan-1 yearly reset with `{Y}` is safe, and so is a monthly reset with `{Y}{m}`. A yearly reset on Dec 14 with `{Y}` is not safe: the counter restarts on Dec 14, but `{Y}` still shows the old year until Dec 31, so December repeats numbers that January already used. A quarterly reset with `{Y}` repeats every quarter.

Use `{period:...}` for these cases. It formats the last day of the current period. Choose characters that differ between two periods: `{period:Y}` for a yearly reset, `{period:Y-m}` for a monthly or quarterly reset (PHP has no quarter character), and `{period:Y-m-d}` for a daily or weekly reset. For a Dec-14 yearly reset, `{period:Y}` shows the year in which the period ends: a number issued on Dec 20, 2026 gets 2027. For a Jan-1 yearly reset, `{period:Y}` and `{Y}` are the same. For a type that never resets, `{period:...}` uses the current date.

You can override the format, padding or timezone for one call. Overrides only change the output; they are not saved:

```php
RunningNumber::for('invoice')->format('{Y}-{number}')->padding(2)->timezone('Asia/Kuala_Lumpur')->generate();
```

`{period:...}` always uses the type's own timezone, because the period belongs to the type's reset schedule.

### Reading without issuing a number

```php
RunningNumber::for('invoice')->current();        // the counter, formatted
RunningNumber::for('invoice')->previewCurrent(); // the same as current()
RunningNumber::for('invoice')->preview();        // "XXXX" — a placeholder for the number
RunningNumber::for('invoice')->preview(42);      // "0042"
```

`current()` shows the counter: the highest number that `generate()` issued in this period, or 0 after a reset. It does not change when `generate()` reissues an undone number or when you use a skipped number. `current()` and `previewCurrent()` throw `RunningNumberTypeNotRegistered` for an unknown type, even with `auto_register` on. `preview()` also works for a type that has no row yet. It uses the type's settings in `running-number.types`, or the defaults.

### Resets

| Interval | Anchors you can set | Default boundary |
|---|---|---|
| `ResetInterval::NEVER` | none | — |
| `ResetInterval::DAILY` | `resetHour` | midnight |
| `ResetInterval::WEEKLY` | `resetWeekday` (0 = Sunday … 6 = Saturday), `resetHour` | Monday, midnight |
| `ResetInterval::MONTHLY` | `resetDay`, `resetHour` | the 1st, midnight |
| `ResetInterval::QUARTERLY` | `resetMonth` (phase), `resetDay`, `resetHour` | Jan/Apr/Jul/Oct 1st, midnight |
| `ResetInterval::YEARLY` | `resetMonth`, `resetDay`, `resetHour` | Jan 1st, midnight |

For example, a yearly counter that resets every December 14th:

```php
RunningNumber::for('permit')->register(
    interval: ResetInterval::YEARLY,
    resetMonth: 12,
    resetDay: 14,
    format: 'PSB {number}/{period:Y}',
);
```

`register()` throws `InvalidResetAnchor` for an anchor that does not belong to the interval, or for a value out of range. A `resetDay` past the end of a month (for example 31 in February) moves to the month's last day.

Resets are lazy. Nothing runs at midnight. The next `generate()`, `skip()`, `useSkipped()` or `undo()` call after a boundary resets the counter to 0 first. Until then, `current()` still shows the previous period's number, formatted as at the end of that period.

`RunningNumber::for('invoice')->reset()` restarts the counter at 0 immediately. The period does not change, so date tokens can repeat after a manual reset.

#### Timezones

A type's `timezone` sets where midnight is for that type. If it is `null` (the default), the type uses `config('app.timezone')`. You only need it when the app clock and the business clock differ, for example an app that runs in UTC while its invoices must reset at midnight in Kuala Lumpur:

```php
RunningNumber::for('invoice')->register(interval: ResetInterval::YEARLY, timezone: 'Asia/Kuala_Lumpur');
```

`register()` throws PHP's `DateInvalidTimeZoneException` for an unknown timezone. If a daylight saving time change skips the reset time on a day, the reset happens when the clock jumps (for example at 03:00 instead of 02:00). If the reset time happens twice on a day, the reset happens at the first one.

The package stores `last_reset_at` in UTC, and the model returns it in app time. If you write it without the model, for example with `DB::table()` or a query builder `update()`, write it in UTC.

### Skipping numbers

`skip()` reserves numbers that are not yet issued, so `generate()` steps over them. `useSkipped()` issues one reserved number, once:

```php
$pending = RunningNumber::for('invoice');

$pending->skip([28, 29, 30], 'misprinted batch');
$pending->useSkipped(29); // "0029"
```

`skip()` throws `RunningNumberAlreadyIssued` for a number that is not above the current number. `useSkipped()` throws `RunningNumberNotSkipped` for a number that is not reserved, or that was already used.

### Undoing numbers

`undo()` records that an issued number was voided. What happens next depends on the type's `undoMode`:

- `UndoMode::RECLAIM` (default): the next `generate()` reissues the lowest undone number before it issues a new one.
- `UndoMode::RECORD`: the undo is only recorded. The number is never issued again. Use this for documents that must stay strictly sequential, such as tax invoices.

If you switch a type from `UndoMode::RECLAIM` to `UndoMode::RECORD`, `generate()` stops reissuing the numbers that were undone before the switch.

```php
use Hasyirin\RunningNumber\Enums\UndoMode;

RunningNumber::for('invoice')->register(undoMode: UndoMode::RECLAIM);

$pending = RunningNumber::for('invoice');

$pending->undo(2, 'voided');
$pending->generate(); // "0002" — reissued before a new number
```

You can undo any number that `generate()` or `useSkipped()` issued in the current period. `undo()` throws `RunningNumberNotUndoable` for a number that is not issued yet, a reserved number that was never used, or a number that is undone and not issued again.

In `UndoMode::RECLAIM`, you can undo a number again after `generate()` reissues it. Each number has one undo record per period, so the record then keeps only the latest reason.

Skips and undos belong to one period. After a reset, the previous period's skips and undos no longer apply, and its numbers can no longer be undone.

### Concurrency and gaps

`generate()`, `skip()`, `useSkipped()`, `undo()` and `reset()` lock the type's row (`SELECT ... FOR UPDATE`), so parallel `generate()` calls never return the same number.

Call `generate()` inside the same database transaction that saves the record that uses the number. If that transaction rolls back, the number rolls back too, so it leaves no gap. The row stays locked until the transaction commits, so keep the transaction short:

```php
DB::transaction(function () use ($data) {
    Invoice::create([...$data, 'number' => RunningNumber::generate('invoice')]);
});
```

If you call `generate()` outside a transaction, the number is saved at once. A later failure then leaves a gap.

On MySQL and MariaDB, these calls also lock the skipped and undone numbers that they read, because a plain read in a transaction can return old data there. InnoDB locks ranges of the index, so while one transaction holds `generate()`, a `skip()` or `undo()` on another type can wait. Keep the transactions short, or use the `READ COMMITTED` isolation level, which locks less.

These locks can also cause a `DeadlockException`. For example, two `skip()` or `undo()` calls on different types can deadlock when they run at the same moment, and on MariaDB 11.6 and later, two requests that use the same skipped or undone number can fail. The package then retries its own transaction, with up to 3 attempts in total. A lock wait timeout on MySQL or MariaDB also counts, so one call can wait up to 3 times `innodb_lock_wait_timeout` before it fails. If you call these methods inside your own transaction, the package cannot retry, because Laravel retries only the outermost transaction. Retry your transaction instead, for example with `DB::transaction($callback, attempts: 3)`.

### Testing with `fake()`

Swap the manager for an in-memory fake so your tests don't touch the database, then assert on what was generated:

```php
RunningNumber::fake();
RunningNumber::for('invoice')->register(); // not needed if 'invoice' is in running-number.types

$result = RunningNumber::generate('invoice');

RunningNumber::assertGenerated('invoice', times: 1);
```

The fake throws the same exceptions as the real manager. A type counts as registered if you call `register()` after `fake()`, declare the type in `running-number.types` (the fake assumes that `running-number:sync` has run), or turn on `auto_register`, which lets `generate()` register it, as in the real manager. A type that your app registers only at boot, before `fake()`, must be registered again in the test.

The available assertions are `assertGenerated()`, `assertNotGenerated()`, `assertSkipped()`, `assertUsedSkipped()`, `assertUndone()`, `assertRegistered()` and `assertReset()`. `generated($type)` returns the raw log.

The fake does not:

- render date tokens or `{period:...}` tokens (it leaves them as literal text),
- apply time-based resets.

To test those, use the real manager with a test database.

## Testing

```bash
composer test
```

The tests use an in-memory SQLite database by default. To run them against MySQL, MariaDB or PostgreSQL, set `RUNNING_NUMBER_TEST_CONNECTION` to the connection name, and set the server with the `DB_*` variables:

```bash
RUNNING_NUMBER_TEST_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=secret composer test
```

The tests drop every table in their database, so they always use a database named `running_number_testing`, whatever `DB_DATABASE` or `DB_URL` says. Create it first. The tests ignore `DB_CONNECTION`.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Hasyirin Fakhriy](https://github.com/hasyirin)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
