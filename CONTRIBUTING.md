# Contributing

Contributions are welcome and will be fully credited.

## Pull Requests

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding style — run `composer format` (Laravel Pint) before submitting.
- Add or update tests for any behavior change — run `composer test` (Pest).
- Static analysis must pass — run `composer analyse` (PHPStan/Larastan).
- Keep pull requests focused on a single change.

## Running Tests

```bash
composer test
composer test-coverage
composer analyse
composer format
```

## Reporting Issues

Please open an issue on [GitHub](https://github.com/hasyirin/laravel-running-number/issues), including steps to reproduce and the package/PHP/Laravel versions involved.
