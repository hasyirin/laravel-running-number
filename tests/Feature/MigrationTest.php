<?php

use Hasyirin\RunningNumber\RunningNumberServiceProvider;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelPackageTools\Package;

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
