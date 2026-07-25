<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\Models\RunningNumberSkip;
use Hasyirin\RunningNumber\Models\RunningNumberUndo;

it('casts reset_interval and undo_mode to their enums', function () {
    $row = RunningNumber::factory()->create([
        'type' => 'invoice',
        'reset_interval' => ResetInterval::YEARLY,
        'undo_mode' => UndoMode::RECORD,
    ]);

    expect($row->reset_interval)->toBe(ResetInterval::YEARLY);
    expect($row->undo_mode)->toBe(UndoMode::RECORD);
});

it('scopes queries by type', function () {
    RunningNumber::factory()->create(['type' => 'invoice']);
    RunningNumber::factory()->create(['type' => 'permit']);

    $found = RunningNumber::query()->type('permit')->first();

    expect($found->type)->toBe('permit');
});

it('relates to its skips and undos', function () {
    $row = RunningNumber::factory()->create(['type' => 'invoice']);

    $skip = RunningNumberSkip::create([
        'running_number_id' => $row->id,
        'number' => 5,
        'formatted_number' => '00005',
        'reset_cycle' => 1,
    ]);

    $undo = RunningNumberUndo::create([
        'running_number_id' => $row->id,
        'number' => 3,
        'formatted_number' => '00003',
        'mode' => UndoMode::RECLAIM,
        'reset_cycle' => 1,
    ]);

    expect($row->skips()->first()->is($skip))->toBeTrue();
    expect($row->undos()->first()->is($undo))->toBeTrue();
    expect($skip->runningNumber->is($row))->toBeTrue();
    expect($undo->runningNumber->is($row))->toBeTrue();
});
