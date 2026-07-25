# Laravel Running Number

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hasyirin/laravel-running-number.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-running-number)
[![GitHub Tests Action Status](https://github.com/hasyirin/laravel-running-number/actions/workflows/run-tests.yml/badge.svg)](https://github.com/hasyirin/laravel-running-number/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/hasyirin/laravel-running-number/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/hasyirin/laravel-running-number/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/hasyirin/laravel-running-number.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-running-number)

A Laravel package for generating sequential, zero-padded running numbers — invoice numbers, permit numbers, or any other per-type counter that needs to reset on a schedule. Numbers are generated under a database row lock for gap-safe concurrency, and issued or reserved numbers can be skipped or undone without breaking the sequence.

## Installation

You can install the package via composer:

```bash
composer require hasyirin/laravel-running-number
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-running-number-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-running-number-config"
```

This is the contents of the published config file:

```php
<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;

return [

    // If true, generate() silently registers an unknown type using the defaults below
    // instead of throwing RunningNumberTypeNotRegistered.
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

Every `type` (e.g. `'invoice'`, `'permit'`) must be registered before you can generate
numbers for it — either at runtime with `register()`, or declaratively via the
`running-number.types` config array kept in sync with `php artisan running-number:sync`.

### Generating

```php
use Hasyirin\RunningNumber\Facades\RunningNumber;
use Hasyirin\RunningNumber\Enums\ResetInterval;

RunningNumber::for('invoice')->register(interval: ResetInterval::YEARLY, padding: 4);

RunningNumber::generate('invoice'); // "0001"
RunningNumber::generate('invoice'); // "0002"
```

### Anchored resets

Reset boundaries aren't limited to calendar year/month/week starts. Pass `resetMonth` /
`resetDay` (or `resetWeekday` / `resetHour` for weekly/daily intervals) to anchor the
reset to any recurring date — for example, a yearly counter that resets every December
14th instead of January 1st:

```php
RunningNumber::for('invoice')->register(
    interval: ResetInterval::YEARLY,
    resetMonth: 12,
    resetDay: 14,
    padding: 4,
);
```

### Skipping and undoing numbers

`skip()` reserves numbers so `generate()` steps over them instead of reissuing them;
`useSkipped()` later formats one of those reserved numbers for actual use. `undo()`
reclaims an already-issued number so the next `generate()` call reuses it before
incrementing further.

```php
$pending = RunningNumber::for('invoice');

$pending->skip(6, 'test');
$pending->useSkipped(6); // "0006"

$pending->undo(2, 'voided');
$pending->generate(); // "0002" — reclaimed before a fresh number is issued
```

### Testing with `fake()`

Swap the manager for an in-memory fake so your tests don't touch the database, then
assert on what was generated:

```php
RunningNumber::fake();

$result = RunningNumber::generate('invoice');

RunningNumber::assertGenerated('invoice', times: 1);
```

## Testing

```bash
composer test
```

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
