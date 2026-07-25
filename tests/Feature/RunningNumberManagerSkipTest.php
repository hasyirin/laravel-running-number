<?php

use Hasyirin\RunningNumber\Exceptions\RunningNumberAlreadyIssued;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotSkipped;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;

it('skips a single number and a batch of numbers, storing the reason and a formatted snapshot', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 27, 'padding' => 4]);
    $manager = new RunningNumberManager;

    $manager->skipFor('invoice', [28, 29, 30], 'misprinted batch');

    $row = RunningNumber::query()->type('invoice')->first();
    expect($row->skips()->pluck('number')->all())->toBe([28, 29, 30]);
    expect($row->skips()->first()->formatted_number)->toBe('0028');
    expect($row->skips()->first()->reason)->toBe('misprinted batch');
});

it('generate() steps over skipped numbers instead of reissuing them', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 4, 'padding' => 4]);
    $manager = new RunningNumberManager;

    $manager->skipFor('invoice', 5, 'skip me');

    expect($manager->generateFor('invoice', null, null, null))->toBe('0006');
});

it('throws RunningNumberAlreadyIssued when skipping a number that is already issued', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 10]);

    expect(fn () => (new RunningNumberManager)->skipFor('invoice', 5, null))
        ->toThrow(RunningNumberAlreadyIssued::class);
});

it('treats re-skipping the same number in the same cycle as a harmless no-op', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 0]);
    $manager = new RunningNumberManager;

    $manager->skipFor('invoice', 5, 'first reason');
    $manager->skipFor('invoice', 5, 'first reason');

    $row = RunningNumber::query()->type('invoice')->first();
    expect($row->skips()->count())->toBe(1);
});

it('useSkipped marks the entry used and re-formats the raw number using override-or-current settings', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 4, 'padding' => 4]);
    $manager = new RunningNumberManager;
    $manager->skipFor('invoice', 5, null);

    // No override: uses the row's current padding (4).
    expect($manager->useSkippedFor('invoice', 5, null, null, null))->toBe('0005');
});

it('useSkipped reflects a padding change made after skip() rather than the stored snapshot', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 4, 'padding' => 4]);
    $manager = new RunningNumberManager;
    $manager->skipFor('invoice', 5, null); // formatted_number snapshot is "0005"

    (new RunningNumberManager)->registerFor('invoice', null, 6, null, null, null, null, null, null, null);

    // If this returned the stored snapshot ("0005"), this assertion would fail —
    // it must reflect the *new* padding (6), proving it re-formats from the raw number.
    expect($manager->useSkippedFor('invoice', 5, null, null, null))->toBe('000005');
});

it('throws RunningNumberNotSkipped on double-use', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 4]);
    $manager = new RunningNumberManager;
    $manager->skipFor('invoice', 5, null);
    $manager->useSkippedFor('invoice', 5, null, null, null);

    expect(fn () => $manager->useSkippedFor('invoice', 5, null, null, null))
        ->toThrow(RunningNumberNotSkipped::class);
});

it('throws RunningNumberNotSkipped for a number that was never skipped', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 4]);

    expect(fn () => (new RunningNumberManager)->useSkippedFor('invoice', 999, null, null, null))
        ->toThrow(RunningNumberNotSkipped::class);
});
