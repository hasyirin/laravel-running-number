<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Exceptions\RunningNumberTypeNotRegistered;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('throws when generating for an unregistered type without auto_register', function () {
    config(['running-number.auto_register' => false]);

    expect(fn () => (new RunningNumberManager)->generateFor('invoice', null, null, null))
        ->toThrow(RunningNumberTypeNotRegistered::class);
});

it('auto-registers using config defaults when auto_register is enabled', function () {
    config(['running-number.auto_register' => true, 'running-number.default_padding' => 3]);

    $result = (new RunningNumberManager)->generateFor('invoice', null, null, null);

    expect($result)->toBe('001');
    expect(RunningNumber::query()->type('invoice')->exists())->toBeTrue();
});

it('increments sequentially and zero-pads to the row\'s padding', function () {
    $manager = new RunningNumberManager;
    RunningNumber::factory()->create(['type' => 'invoice', 'padding' => 4]);

    expect($manager->generateFor('invoice', null, null, null))->toBe('0001');
    expect($manager->generateFor('invoice', null, null, null))->toBe('0002');
});

it('respects one-off format/padding/timezone overrides without persisting them', function () {
    $manager = new RunningNumberManager;
    RunningNumber::factory()->create(['type' => 'invoice', 'padding' => 4, 'format' => '{number}']);

    expect($manager->generateFor('invoice', '{Y}-{number}', 2, 'UTC'))->toBe(now('UTC')->format('Y').'-01');

    $row = RunningNumber::query()->type('invoice')->first();
    expect($row->format)->toBe('{number}');
    expect($row->padding)->toBe(4);
});

it('resets the counter and bumps reset_cycle when a reset is due', function () {
    Carbon::setTestNow('2027-01-01 00:00:01');

    $row = RunningNumber::factory()->create([
        'type' => 'invoice',
        'reset_interval' => ResetInterval::YEARLY,
        'number' => 50,
        'reset_cycle' => 1,
        'last_reset_at' => '2026-06-01 00:00:00',
        'padding' => 3,
    ]);

    $result = (new RunningNumberManager)->generateFor('invoice', null, null, null);

    expect($result)->toBe('001');
    $row->refresh();
    expect($row->reset_cycle)->toBe(2);

    Carbon::setTestNow();
});

it('locks the row with a SELECT ... FOR UPDATE inside a transaction', function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite has no row-locking SQL syntax — lockForUpdate() is a documented no-op on this driver. This assertion is only meaningful on MySQL/PostgreSQL. The lockForUpdate() call itself is verified by source inspection during code review, not by this test on this driver.');
    }

    RunningNumber::factory()->create(['type' => 'invoice']);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    (new RunningNumberManager)->generateFor('invoice', null, null, null);

    $hasLockingSelect = collect($queries)->contains(
        fn ($sql) => str_contains($sql, 'select') && str_contains(strtolower($sql), 'for update')
    );

    expect($hasLockingSelect)->toBeTrue();
});
