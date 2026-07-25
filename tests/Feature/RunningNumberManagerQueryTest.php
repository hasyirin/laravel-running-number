<?php

use Hasyirin\RunningNumber\Exceptions\RunningNumberTypeNotRegistered;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;

it('current() returns the stored number formatted, read-only', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 7, 'padding' => 4]);

    expect((new RunningNumberManager)->currentFor('invoice', null, null, null))->toBe('0007');
    expect(RunningNumber::query()->type('invoice')->first()->number)->toBe(7); // unchanged
});

it('current() always throws for an unregistered type, even with auto_register on', function () {
    config(['running-number.auto_register' => true]);

    expect(fn () => (new RunningNumberManager)->currentFor('invoice', null, null, null))
        ->toThrow(RunningNumberTypeNotRegistered::class);
    expect(RunningNumber::query()->type('invoice')->exists())->toBeFalse();
});

it('preview() renders a placeholder of X characters for the row\'s padding, without creating a row', function () {
    config(['running-number.default_padding' => 5]);

    expect((new RunningNumberManager)->previewFor('invoice', null, null, null, null))->toBe('XXXXX');
    expect(RunningNumber::query()->type('invoice')->exists())->toBeFalse();
});

it('preview(int) renders the given number padded instead of a placeholder', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'padding' => 4]);

    expect((new RunningNumberManager)->previewFor('invoice', 64, null, null, null))->toBe('0064');
});

it('previewCurrent() behaves identically to current() (an alias for API discoverability)', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 7, 'padding' => 4]);

    $manager = new RunningNumberManager;
    expect($manager->previewCurrentFor('invoice', null, null, null))->toBe($manager->currentFor('invoice', null, null, null));
});

it('reset() forces number back to 0 and bumps reset_cycle regardless of schedule', function () {
    $row = RunningNumber::factory()->create(['type' => 'invoice', 'number' => 42, 'reset_cycle' => 1]);

    (new RunningNumberManager)->resetFor('invoice');

    $row->refresh();
    expect($row->number)->toBe(0);
    expect($row->reset_cycle)->toBe(2);
});
