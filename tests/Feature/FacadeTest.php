<?php

use Hasyirin\RunningNumber\Facades\RunningNumber;
use Hasyirin\RunningNumber\Models\RunningNumber as RunningNumberModel;
use Hasyirin\RunningNumber\PendingRunningNumber;

it('generate() is callable statically on the facade', function () {
    RunningNumberModel::factory()->create(['type' => 'invoice', 'padding' => 4]);

    expect(RunningNumber::generate('invoice'))->toBe('0001');
});

it('for() is callable statically and returns a PendingRunningNumber', function () {
    RunningNumberModel::factory()->create(['type' => 'invoice', 'padding' => 4]);

    expect(RunningNumber::for('invoice'))->toBeInstanceOf(PendingRunningNumber::class);
});
