<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Exceptions\RunningNumberTypeNotRegistered;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class UnixClock extends Carbon
{
    public static int $timestamp = 0;

    public static function now($timezone = null): static
    {
        return self::createFromTimestamp(self::$timestamp)->setTimezone($timezone ?? date_default_timezone_get());
    }
}

afterEach(fn () => Date::useDefault());

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

it('auto-registers a type declared in config with its declared settings', function () {
    config([
        'running-number.auto_register' => true,
        'running-number.types' => ['invoice' => ['padding' => 3, 'format' => 'INV-{number}']],
    ]);

    expect((new RunningNumberManager)->generateFor('invoice', null, null, null))->toBe('INV-001');
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

it('resets once per local day for a type timezone behind app.timezone', function () {
    RunningNumber::factory()->create([
        'type' => 'permit', 'reset_interval' => ResetInterval::DAILY, 'timezone' => 'America/New_York',
        'last_reset_at' => Carbon::parse('2026-07-14 10:00:00', 'UTC'),
    ]);
    $manager = new RunningNumberManager;

    Carbon::setTestNow(Carbon::parse('2026-07-15 04:30:00', 'UTC'));
    $first = $manager->generateFor('permit', null, null, null);

    Carbon::setTestNow(Carbon::parse('2026-07-15 13:00:00', 'UTC'));
    $second = $manager->generateFor('permit', null, null, null);

    expect([$first, $second])->toBe(['00001', '00002']);

    Carbon::setTestNow();
});

it('resets at local midnight for a type timezone ahead of app.timezone', function () {
    RunningNumber::factory()->create([
        'type' => 'permit', 'reset_interval' => ResetInterval::DAILY, 'timezone' => 'Asia/Kuala_Lumpur',
        'last_reset_at' => Carbon::parse('2026-07-14 00:00:00', 'UTC'),
    ]);
    $manager = new RunningNumberManager;

    Carbon::setTestNow(Carbon::parse('2026-07-15 15:30:00', 'UTC'));
    $first = $manager->generateFor('permit', null, null, null);

    Carbon::setTestNow(Carbon::parse('2026-07-15 22:00:00', 'UTC'));
    $second = $manager->generateFor('permit', null, null, null);

    expect([$first, $second])->toBe(['00001', '00001']);

    Carbon::setTestNow();
});

it('resets once when the app clock repeats an hour at the end of daylight saving time', function () {
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');
    RunningNumber::factory()->create([
        'type' => 'permit', 'reset_interval' => ResetInterval::MONTHLY, 'timezone' => 'America/Mexico_City',
        'number' => 30, 'padding' => 3, 'last_reset_at' => Carbon::parse('2026-10-01 06:00:00', 'UTC'),
    ]);
    Date::use(UnixClock::class);
    $manager = new RunningNumberManager;

    // New York repeats 01:00-02:00 on 2026-11-01; the Mexico City month starts at 06:00 UTC, in the second pass.
    $issued = collect(['06:05', '06:20', '06:40', '07:05'])->map(function (string $utc) use ($manager) {
        UnixClock::$timestamp = Carbon::parse("2026-11-01 {$utc}:00", 'UTC')->getTimestamp();

        return $manager->generateFor('permit', null, null, null);
    });

    expect($issued->all())->toBe(['001', '002', '003', '004'])
        ->and(DB::table('running_numbers')->where('type', 'permit')->value('last_reset_at'))->toBe('2026-11-01 06:05:00');
});

it('stamps last_reset_at from the app clock when auto-registering', function () {
    config(['running-number.auto_register' => true]);
    Carbon::setTestNow('2026-07-15 10:00:00');

    (new RunningNumberManager)->generateFor('invoice', null, null, null);

    expect(RunningNumber::query()->type('invoice')->first()->last_reset_at->format('Y-m-d H:i:s'))->toBe('2026-07-15 10:00:00');

    Carbon::setTestNow();
});

it('locks every skip and undo row it reads, so an older transaction snapshot cannot hide them', function (string $operation) {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite has no row-locking SQL syntax.');
    }

    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5]);
    $manager = new RunningNumberManager;
    $manager->skipFor('invoice', [7, 8], null);
    $manager->useSkippedFor('invoice', 8, null, null, null);

    $reads = [];
    DB::listen(function ($query) use (&$reads) {
        if (preg_match('/^select .* from [`"]running_number_(skips|undos)[`"]/i', $query->sql)) {
            $reads[] = strtolower($query->sql);
        }
    });

    match ($operation) {
        'generate' => $manager->generateFor('invoice', null, null, null),
        'skip' => $manager->skipFor('invoice', 9, null),
        'useSkipped' => $manager->useSkippedFor('invoice', 7, null, null, null),
        'undo a generated number' => $manager->undoFor('invoice', 3, null),
        'undo a used skip' => $manager->undoFor('invoice', 8, null),
    };

    expect($reads)->not->toBeEmpty()->each->toContain('for update');
})->with(['generate', 'skip', 'useSkipped', 'undo a generated number', 'undo a used skip']);

it('retries its own transaction after a deadlock', function (string $operation) {
    // Laravel retries only the outermost transaction, so leave the one that RefreshDatabase opened.
    DB::rollBack();

    try {
        RunningNumber::factory()->create(['type' => 'invoice', 'number' => 5]);
        $manager = new RunningNumberManager;
        $manager->skipFor('invoice', 7, null);

        $deadlocks = 0;
        DB::beforeExecuting(function (string $sql) use (&$deadlocks) {
            if ($deadlocks === 0 && preg_match('/^(insert into|update) [`"]running_number/', $sql)) {
                $deadlocks++;

                throw new DeadlockException('Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        match ($operation) {
            'generate' => $manager->generateFor('invoice', null, null, null),
            'skip' => $manager->skipFor('invoice', 9, null),
            'useSkipped' => $manager->useSkippedFor('invoice', 7, null, null, null),
            'undo' => $manager->undoFor('invoice', 3, null),
            'reset' => $manager->resetFor('invoice'),
            'register a new schedule' => $manager->registerFor('invoice', ResetInterval::DAILY),
        };

        expect($deadlocks)->toBe(1);
    } finally {
        DB::table('running_number_undos')->delete();
        DB::table('running_number_skips')->delete();
        DB::table('running_numbers')->delete();
        DB::beginTransaction();
    }
})->with(['generate', 'skip', 'useSkipped', 'undo', 'reset', 'register a new schedule']);

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
