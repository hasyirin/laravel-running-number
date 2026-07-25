<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Exceptions\InvalidResetAnchor;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;

it('creates a new row with the given settings, falling back to config defaults for omitted ones', function () {
    (new RunningNumberManager)->registerFor('invoice', ResetInterval::YEARLY, 4, null, 12, 14, null, null, null, null);

    $row = RunningNumber::query()->type('invoice')->first();

    expect($row->reset_interval)->toBe(ResetInterval::YEARLY);
    expect($row->padding)->toBe(4);
    expect($row->reset_month)->toBe(12);
    expect($row->reset_day)->toBe(14);
    expect($row->undo_mode)->toBe(config('running-number.default_undo_mode'));
    expect($row->format)->toBeNull();
});

it('upserts an existing row without touching number, reset_cycle, or last_reset_at', function () {
    $row = RunningNumber::factory()->create([
        'type' => 'invoice',
        'number' => 42,
        'reset_cycle' => 3,
        'padding' => 4,
    ]);
    $lastResetAt = $row->last_reset_at;

    (new RunningNumberManager)->registerFor('invoice', null, 6, null, null, null, null, null, null, null);

    $row->refresh();

    expect($row->padding)->toBe(6);
    expect($row->number)->toBe(42);
    expect($row->reset_cycle)->toBe(3);
    expect($row->last_reset_at->equalTo($lastResetAt))->toBeTrue();
});

it('throws InvalidResetAnchor when an anchor field does not apply to the interval', function () {
    expect(fn () => (new RunningNumberManager)->registerFor('invoice', ResetInterval::MONTHLY, null, null, null, null, 1, null, null, null))
        ->toThrow(InvalidResetAnchor::class);
});

it('throws InvalidResetAnchor when any anchor is set alongside NEVER', function () {
    expect(fn () => (new RunningNumberManager)->registerFor('invoice', ResetInterval::NEVER, null, null, null, 1, null, null, null, null))
        ->toThrow(InvalidResetAnchor::class);
});

it('throws InvalidResetAnchor when resetMonth is out of range', function () {
    expect(fn () => (new RunningNumberManager)->registerFor('invoice', ResetInterval::YEARLY, null, null, 13, 1, null, null, null, null))
        ->toThrow(InvalidResetAnchor::class);
});

it('throws InvalidResetAnchor when resetHour is out of range', function () {
    expect(fn () => (new RunningNumberManager)->registerFor('invoice', ResetInterval::DAILY, null, null, null, null, null, 25, null, null))
        ->toThrow(InvalidResetAnchor::class);
});
