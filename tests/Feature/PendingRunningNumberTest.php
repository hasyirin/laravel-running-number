<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;
use Illuminate\Support\Carbon;

it('for() does no database work until a terminal method is called', function () {
    $pending = (new RunningNumberManager)->for('invoice');

    expect(RunningNumber::query()->type('invoice')->exists())->toBeFalse();
});

it('chained format/padding/timezone overrides reach generate()', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'padding' => 4, 'format' => '{number}']);

    $result = (new RunningNumberManager)->for('invoice')->format('{Y}-{number}')->padding(2)->timezone('UTC')->generate();

    expect($result)->toBe(now('UTC')->format('Y').'-01');
});

it('chained format/padding/timezone overrides reach useSkipped()', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 4, 'padding' => 4, 'format' => '{number}']);
    $pending = (new RunningNumberManager)->for('invoice');
    $pending->skip(6);
    Carbon::setTestNow(Carbon::parse('2026-12-31 20:00:00', 'UTC'));

    expect($pending->format('{Y}-{number}')->padding(2)->timezone('Asia/Kuala_Lumpur')->useSkipped(6))->toBe('2027-06');

    Carbon::setTestNow();
});

it('rejects a negative padding override', function () {
    expect(fn () => (new RunningNumberManager)->for('invoice')->padding(-1))->toThrow(InvalidArgumentException::class);
});

it('register() through the builder persists settings without needing to repeat the type', function () {
    (new RunningNumberManager)->for('invoice')->register(interval: ResetInterval::YEARLY, padding: 4);

    $row = RunningNumber::query()->type('invoice')->first();
    expect($row->reset_interval)->toBe(ResetInterval::YEARLY);
    expect($row->padding)->toBe(4);
});

it('skip/useSkipped/undo/reset all work through the builder', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5, 'padding' => 4]);
    $pending = (new RunningNumberManager)->for('invoice');

    $pending->skip(6, 'test');
    expect($pending->useSkipped(6))->toBe('0006');

    $pending->undo(4, 'voided');
    expect($pending->generate())->toBe('0004'); // reclaimed

    $pending->reset();
    expect(RunningNumber::query()->type('invoice')->first()->number)->toBe(0);
});
