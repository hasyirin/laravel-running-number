<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotUndoable;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;
use Illuminate\Support\Carbon;

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

it('stops reissuing numbers undone in reclaim mode once the type switches to record mode', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5, 'padding' => 4, 'undo_mode' => UndoMode::RECLAIM]);
    $manager = new RunningNumberManager;
    $manager->undoFor('invoice', 3, 'voided');

    $manager->registerFor('invoice', undoMode: UndoMode::RECORD);

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

it('isolates skips and pending reclaims of the previous cycle after a reset', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5, 'padding' => 1, 'undo_mode' => UndoMode::RECLAIM]);
    $manager = new RunningNumberManager;
    $manager->skipFor('invoice', 7, null);
    $manager->undoFor('invoice', 3, null);

    $manager->resetFor('invoice');

    expect(collect(range(1, 7))->map(fn () => $manager->generateFor('invoice', null, null, null))->all())
        ->toBe(['1', '2', '3', '4', '5', '6', '7']);
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

it('throws RunningNumberNotUndoable for zero and negative numbers', function (int $number) {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5]);

    expect(fn () => (new RunningNumberManager)->undoFor('invoice', $number, null))->toThrow(RunningNumberNotUndoable::class);
})->with([0, -3]);

it('undoes a number issued through useSkipped, and reclaims it', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 4, 'padding' => 4, 'undo_mode' => UndoMode::RECLAIM]);
    $manager = new RunningNumberManager;
    $manager->skipFor('invoice', 9, null);
    $manager->useSkippedFor('invoice', 9, null, null, null);

    $manager->undoFor('invoice', 9, 'voided');

    expect($manager->generateFor('invoice', null, null, null))->toBe('0009');
    expect($manager->generateFor('invoice', null, null, null))->toBe('0005');
});

it('applies a due reset before undoing, so last period numbers are not undoable', function () {
    RunningNumber::factory()->create([
        'type' => 'invoice', 'reset_interval' => ResetInterval::YEARLY, 'number' => 50,
        'last_reset_at' => Carbon::parse('2026-06-01 00:00:00'),
    ]);
    Carbon::setTestNow('2027-01-02 09:00:00');

    expect(fn () => (new RunningNumberManager)->undoFor('invoice', 50, null))->toThrow(RunningNumberNotUndoable::class);

    Carbon::setTestNow();
});

it('throws RunningNumberNotUndoable when the same number is undone twice in one cycle', function (UndoMode $mode) {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5, 'undo_mode' => $mode]);
    $manager = new RunningNumberManager;
    $manager->undoFor('invoice', 4, null);

    expect(fn () => $manager->undoFor('invoice', 4, null))->toThrow(RunningNumberNotUndoable::class);
})->with([UndoMode::RECLAIM, UndoMode::RECORD]);

it('undoes a reclaimed number again after generate() reissued it', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5, 'padding' => 4, 'undo_mode' => UndoMode::RECLAIM]);
    $manager = new RunningNumberManager;
    $manager->undoFor('invoice', 2, 'first void');
    $manager->generateFor('invoice', null, null, null);

    $manager->undoFor('invoice', 2, 'second void');

    expect($manager->generateFor('invoice', null, null, null))->toBe('0002')
        ->and(RunningNumber::query()->type('invoice')->first()->undos()->sole()->reason)->toBe('second void');
});
