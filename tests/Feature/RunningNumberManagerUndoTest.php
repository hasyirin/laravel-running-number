<?php

use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotUndoable;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;

it('reclaim-mode undo makes the number reappear via generate() before any fresh increment', function () {
    RunningNumber::factory()->create([
        'type' => 'invoice', 'number' => 5, 'padding' => 4, 'undo_mode' => UndoMode::RECLAIM,
    ]);
    $manager = new RunningNumberManager;

    $manager->undoFor('invoice', 4, 'voided');

    // number column is untouched — still 5.
    expect(RunningNumber::query()->type('invoice')->first()->number)->toBe(5);

    // Next generate() draws the reclaimed 4, not a fresh 6.
    expect($manager->generateFor('invoice', null, null, null))->toBe('0004');
    // The one after that resumes fresh from the untouched counter.
    expect($manager->generateFor('invoice', null, null, null))->toBe('0006');
});

it('drains multiple pending reclaims lowest-first', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5, 'padding' => 4]);
    $manager = new RunningNumberManager;

    $manager->undoFor('invoice', 4, null);
    $manager->undoFor('invoice', 2, null);

    expect($manager->generateFor('invoice', null, null, null))->toBe('0002');
    expect($manager->generateFor('invoice', null, null, null))->toBe('0004');
    expect($manager->generateFor('invoice', null, null, null))->toBe('0006');
});

it('record-mode undo has zero effect on generate()', function () {
    RunningNumber::factory()->create([
        'type' => 'invoice', 'number' => 5, 'padding' => 4, 'undo_mode' => UndoMode::RECORD,
    ]);
    $manager = new RunningNumberManager;

    $manager->undoFor('invoice', 4, 'voided, never reuse');

    expect($manager->generateFor('invoice', null, null, null))->toBe('0006');
});

it('undo re-formats the raw number from override-or-current settings when drawn by generate()', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5, 'padding' => 4]);
    $manager = new RunningNumberManager;
    $manager->undoFor('invoice', 4, null); // snapshot stored as "0004"

    (new RunningNumberManager)->registerFor('invoice', null, 6, null, null, null, null, null, null, null);

    // If this returned the stored snapshot ("0004") the assertion below would fail.
    expect($manager->generateFor('invoice', null, null, null))->toBe('000004');
});

it('throws RunningNumberNotUndoable for a number not yet issued', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5]);

    expect(fn () => (new RunningNumberManager)->undoFor('invoice', 10, null))
        ->toThrow(RunningNumberNotUndoable::class);
});

it('throws RunningNumberNotUndoable for a number that was skipped rather than issued', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5]);
    $manager = new RunningNumberManager;
    $manager->skipFor('invoice', 10, null);
    $manager->generateFor('invoice', null, null, null); // number is now 6, still < 10

    expect(fn () => $manager->undoFor('invoice', 10, null))->toThrow(RunningNumberNotUndoable::class);
});

it('throws RunningNumberNotUndoable when the same number is undone twice in one cycle', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5]);
    $manager = new RunningNumberManager;
    $manager->undoFor('invoice', 4, null);

    expect(fn () => $manager->undoFor('invoice', 4, null))->toThrow(RunningNumberNotUndoable::class);
});
