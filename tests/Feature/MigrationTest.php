<?php

use Hasyirin\RunningNumber\RunningNumberServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\LaravelPackageTools\Package;

it('publishes the migrations under the running-number-migrations tag, parent table first', function () {
    $paths = ServiceProvider::pathsToPublish(RunningNumberServiceProvider::class, 'running-number-migrations');

    $published = collect($paths)->sort()->map(fn (string $target) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($target)));

    expect($published->values()->all())->toBe([
        'create_running_numbers_table.php',
        'create_running_number_skips_table.php',
        'create_running_number_undos_table.php',
    ]);
    expect(collect($paths)->keys()->every(fn (string $source) => is_file($source)))->toBeTrue();
});

it('publishes the config file under the running-number-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(RunningNumberServiceProvider::class, 'running-number-config');

    expect(collect($paths)->map(fn (string $target) => basename($target))->values()->all())->toBe(['running-number.php']);
});

it('keeps every index name within the 64-character MySQL limit', function () {
    $tooLong = collect(['running_numbers', 'running_number_skips', 'running_number_undos'])
        ->flatMap(fn (string $table) => array_column(Schema::getIndexes($table), 'name'))
        ->filter(fn (string $name) => strlen($name) > 64);

    expect($tooLong->values()->all())->toBe([]);
});

it('indexes skips by type, cycle and number, the order generate() reads them in', function () {
    $indexes = collect(Schema::getIndexes('running_number_skips'))->reject(fn (array $index) => $index['primary']);

    expect($indexes->map(fn (array $index) => [$index['name'], $index['columns'], $index['unique']])->values()->all())
        ->toBe([['running_number_skips_number_unique', ['running_number_id', 'reset_cycle', 'number'], true]]);
});

it('does not auto-run its migrations, requiring the host app to publish and run them explicitly', function () {
    $package = new Package;

    (new RunningNumberServiceProvider($this->app))->configurePackage($package);

    expect($package->runsMigrations)->toBeFalse();
});

it('creates all three running number tables with the expected columns', function () {
    expect(Schema::hasColumns('running_numbers', [
        'id', 'type', 'reset_interval', 'reset_month', 'reset_day', 'reset_weekday',
        'reset_hour', 'timezone', 'reset_cycle', 'undo_mode', 'format', 'number',
        'padding', 'last_reset_at', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('running_number_skips', [
        'id', 'running_number_id', 'number', 'formatted_number', 'reason', 'reset_cycle',
        'used_at', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('running_number_undos', [
        'id', 'running_number_id', 'number', 'formatted_number', 'mode', 'reason',
        'reset_cycle', 'reclaimed_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});
