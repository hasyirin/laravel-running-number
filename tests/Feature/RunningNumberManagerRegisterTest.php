<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Exceptions\InvalidResetAnchor;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

it('stamps last_reset_at from the app clock, not the database clock', function () {
    Carbon::setTestNow('2026-07-15 10:00:00');

    (new RunningNumberManager)->registerFor('invoice', ResetInterval::DAILY, null, null, null, null, null, null, null, null);

    expect(RunningNumber::query()->type('invoice')->first()->last_reset_at->format('Y-m-d H:i:s'))->toBe('2026-07-15 10:00:00');

    Carbon::setTestNow();
});

it('stamps last_reset_at in UTC from the app clock for a type created through the model', function () {
    config(['app.timezone' => 'Asia/Kuala_Lumpur']);
    date_default_timezone_set('Asia/Kuala_Lumpur');
    Carbon::setTestNow('2026-07-15 10:00:00');

    RunningNumber::query()->updateOrCreate(['type' => 'invoice'], ['reset_interval' => ResetInterval::DAILY]);

    expect(DB::table('running_numbers')->where('type', 'invoice')->value('last_reset_at'))->toBe('2026-07-15 02:00:00');

    Carbon::setTestNow();
});

it('stamps last_reset_at for a type created through the model while model events are off', function () {
    Carbon::setTestNow('2026-07-15 10:00:00');

    Model::withoutEvents(fn () => RunningNumber::query()->create(['type' => 'invoice']));

    expect(RunningNumber::query()->type('invoice')->first()->last_reset_at->format('Y-m-d H:i:s'))->toBe('2026-07-15 10:00:00');

    Carbon::setTestNow();
});

it('rejects a null last_reset_at instead of storing the current time', function () {
    $row = RunningNumber::factory()->create(['type' => 'invoice']);

    // A savepoint, because PostgreSQL aborts the whole test transaction after a failed statement.
    expect(fn () => DB::transaction(fn () => $row->update(['last_reset_at' => null])))->toThrow(QueryException::class);
});

it('starts a new type from its settings in running-number.types', function () {
    config(['running-number.types.permit' => ['interval' => ResetInterval::YEARLY, 'resetMonth' => 12, 'resetDay' => 14, 'format' => 'PSB {number}']]);

    (new RunningNumberManager)->registerFor('permit', padding: 6);

    expect(RunningNumber::query()->type('permit')->first()->only(['reset_interval', 'reset_month', 'reset_day', 'format', 'padding']))
        ->toBe(['reset_interval' => ResetInterval::YEARLY, 'reset_month' => 12, 'reset_day' => 14, 'format' => 'PSB {number}', 'padding' => 6]);
});

it('patches a new type that another request created after the existence check, instead of overwriting it', function () {
    $created = false;
    DB::beforeExecuting(function (string $sql) use (&$created) {
        if (! $created && preg_match('/^insert into [`"]running_numbers[`"]/', $sql)) {
            $created = true;
            (new RunningNumberManager)->registerFor('permit', ResetInterval::YEARLY, resetMonth: 12, resetDay: 14, replace: true);
        }
    });

    (new RunningNumberManager)->registerFor('permit', padding: 6);

    expect(RunningNumber::query()->type('permit')->first()->only(['reset_interval', 'reset_month', 'reset_day', 'padding']))
        ->toBe(['reset_interval' => ResetInterval::YEARLY, 'reset_month' => 12, 'reset_day' => 14, 'padding' => 6]);
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

it('continues the count when the reset schedule changes', function () {
    $manager = new RunningNumberManager;
    Carbon::setTestNow('2026-12-15 09:00:00');
    $manager->registerFor('permit', ResetInterval::YEARLY, 3, '{number}/{period:Y}', 12, 14);
    $manager->generateFor('permit', null, null, null);
    $manager->generateFor('permit', null, null, null);

    Carbon::setTestNow('2026-12-18 09:00:00');
    $manager->registerFor('permit', resetDay: 17);

    expect($manager->generateFor('permit', null, null, null))->toBe('003/2027');

    Carbon::setTestNow();
});

it('applies a reset that is due under the old schedule before changing it', function () {
    RunningNumber::factory()->create([
        'type' => 'invoice', 'reset_interval' => ResetInterval::YEARLY, 'number' => 50, 'padding' => 3,
        'last_reset_at' => Carbon::parse('2026-06-01 00:00:00'),
    ]);
    $manager = new RunningNumberManager;
    Carbon::setTestNow('2027-01-05 09:00:00');

    $manager->registerFor('invoice', resetMonth: 4);

    expect($manager->generateFor('invoice', null, null, null))->toBe('001');

    Carbon::setTestNow();
});

it('keeps a setting that another request changed while a schedule change waited for the lock', function () {
    $manager = new RunningNumberManager;
    $manager->registerFor('invoice', ResetInterval::YEARLY);

    $changed = false;
    DB::beforeExecuting(function (string $sql) use (&$changed) {
        if (! $changed && DB::transactionLevel() > 1 && str_contains($sql, 'running_numbers')) {
            $changed = true;
            DB::table('running_numbers')->where('type', 'invoice')->update(['padding' => 8]);
        }
    });

    $manager->registerFor('invoice', ResetInterval::MONTHLY);

    expect(RunningNumber::query()->type('invoice')->first()->only(['reset_interval', 'padding']))
        ->toBe(['reset_interval' => ResetInterval::MONTHLY, 'padding' => 8]);
});

it('only reads the row when re-registering unchanged settings', function () {
    $manager = new RunningNumberManager;
    $manager->registerFor('invoice', ResetInterval::YEARLY, 4);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $manager->registerFor('invoice', ResetInterval::YEARLY, 4);

    expect($queries)->toBe(1);
});

it('rejects a negative padding', function () {
    expect(fn () => (new RunningNumberManager)->registerFor('invoice', padding: -1))->toThrow(InvalidArgumentException::class);
    expect(RunningNumber::query()->type('invoice')->exists())->toBeFalse();
});

it('changes only the passed settings when re-registering an existing type', function () {
    $manager = new RunningNumberManager;
    $manager->registerFor('invoice', ResetInterval::YEARLY, 4, 'INV-{number}', 12, 14, null, null, UndoMode::RECORD, 'Asia/Kuala_Lumpur');

    $manager->registerFor('invoice', padding: 6);

    expect(RunningNumber::query()->type('invoice')->first()->only(['reset_interval', 'reset_month', 'reset_day', 'format', 'undo_mode', 'timezone', 'padding']))->toBe([
        'reset_interval' => ResetInterval::YEARLY,
        'reset_month' => 12,
        'reset_day' => 14,
        'format' => 'INV-{number}',
        'undo_mode' => UndoMode::RECORD,
        'timezone' => 'Asia/Kuala_Lumpur',
        'padding' => 6,
    ]);
});

it('clears the old anchors when re-registering with a different interval', function () {
    $manager = new RunningNumberManager;
    $manager->registerFor('invoice', ResetInterval::YEARLY, resetMonth: 12, resetDay: 14);

    $manager->registerFor('invoice', ResetInterval::MONTHLY);

    $row = RunningNumber::query()->type('invoice')->first();
    expect([$row->reset_interval, $row->reset_month, $row->reset_day])->toBe([ResetInterval::MONTHLY, null, null]);
});

it('keeps the other anchors when re-registering one anchor on the same interval', function () {
    $manager = new RunningNumberManager;
    $manager->registerFor('invoice', ResetInterval::YEARLY, resetMonth: 12, resetDay: 14);

    $manager->registerFor('invoice', resetDay: 20);

    $row = RunningNumber::query()->type('invoice')->first();
    expect([$row->reset_interval, $row->reset_month, $row->reset_day])->toBe([ResetInterval::YEARLY, 12, 20]);
});

it('validates a re-registered anchor against the stored interval', function () {
    $manager = new RunningNumberManager;
    $manager->registerFor('invoice', ResetInterval::MONTHLY);

    expect(fn () => $manager->registerFor('invoice', resetWeekday: 1))->toThrow(InvalidResetAnchor::class);
});

it('resets omitted settings to the defaults in replace mode', function () {
    $manager = new RunningNumberManager;
    $manager->registerFor('invoice', ResetInterval::YEARLY, 4, 'INV-{number}', 12, 14);

    $manager->registerFor('invoice', padding: 6, replace: true);

    $row = RunningNumber::query()->type('invoice')->first();
    expect([$row->reset_interval, $row->reset_month, $row->format, $row->padding])->toBe([ResetInterval::NEVER, null, null, 6]);
});

it('rejects an unknown timezone without storing it', function () {
    expect(fn () => (new RunningNumberManager)->registerFor('invoice', timezone: 'Asia/KualaLumpur'))
        ->toThrow(DateInvalidTimeZoneException::class);
    expect(RunningNumber::query()->type('invoice')->exists())->toBeFalse();
});

it('rejects an unknown default timezone', function () {
    config(['running-number.default_timezone' => 'Mars/Olympus_Mons']);

    expect(fn () => (new RunningNumberManager)->registerFor('invoice'))->toThrow(DateInvalidTimeZoneException::class);
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

it('throws InvalidResetAnchor for an out-of-range anchor value', function (ResetInterval $interval, array $anchor) {
    expect(fn () => (new RunningNumberManager)->registerFor('invoice', $interval, ...$anchor))->toThrow(InvalidResetAnchor::class);
})->with([
    'resetDay 0' => [ResetInterval::MONTHLY, ['resetDay' => 0]],
    'resetDay 32' => [ResetInterval::MONTHLY, ['resetDay' => 32]],
    'resetWeekday -1' => [ResetInterval::WEEKLY, ['resetWeekday' => -1]],
    'resetWeekday 7' => [ResetInterval::WEEKLY, ['resetWeekday' => 7]],
    'resetMonth 0' => [ResetInterval::YEARLY, ['resetMonth' => 0]],
    'resetHour -1' => [ResetInterval::DAILY, ['resetHour' => -1]],
]);

it('throws InvalidResetAnchor when resetHour is out of range', function () {
    expect(fn () => (new RunningNumberManager)->registerFor('invoice', ResetInterval::DAILY, null, null, null, null, null, 25, null, null))
        ->toThrow(InvalidResetAnchor::class);
});
