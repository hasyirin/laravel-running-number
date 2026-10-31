<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Exceptions\InvalidResetAnchor;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;

it('upserts every type declared in config into the database', function () {
    config(['running-number.types' => [
        'invoice' => ['padding' => 4, 'interval' => ResetInterval::YEARLY, 'resetMonth' => 12, 'resetDay' => 14],
        'permit' => ['padding' => 5],
    ]]);

    $this->artisan('running-number:sync')->assertSuccessful();

    $invoice = RunningNumber::query()->type('invoice')->first();
    expect($invoice->padding)->toBe(4);
    expect($invoice->reset_interval)->toBe(ResetInterval::YEARLY);
    expect($invoice->reset_month)->toBe(12);

    expect(RunningNumber::query()->type('permit')->first()->padding)->toBe(5);
});

it('resets settings removed from config back to the defaults', function () {
    config(['running-number.types' => ['invoice' => ['interval' => ResetInterval::YEARLY, 'resetMonth' => 12, 'resetDay' => 14, 'padding' => 4]]]);
    $this->artisan('running-number:sync')->assertSuccessful();

    config(['running-number.types' => ['invoice' => ['padding' => 4]]]);
    $this->artisan('running-number:sync')->assertSuccessful();

    $row = RunningNumber::query()->type('invoice')->first();
    expect([$row->reset_interval, $row->reset_month, $row->reset_day, $row->padding])->toBe([ResetInterval::NEVER, null, null, 4]);
});

it('rejects unknown setting keys and syncs nothing', function () {
    config(['running-number.types' => [
        'permit' => ['padding' => 5],
        'invoice' => ['reset_interval' => ResetInterval::YEARLY],
    ]]);

    $this->artisan('running-number:sync')
        ->expectsOutputToContain('reset_interval')
        ->assertFailed();

    expect(RunningNumber::query()->count())->toBe(0);
});

it('syncs nothing when a later type has an invalid value', function () {
    config(['running-number.types' => [
        'permit' => ['padding' => 5],
        'invoice' => ['interval' => ResetInterval::MONTHLY, 'resetWeekday' => 1],
    ]]);

    expect(fn () => $this->artisan('running-number:sync')->run())->toThrow(InvalidResetAnchor::class)
        ->and(RunningNumber::query()->count())->toBe(0);
});

it('syncs a type declared with null using the defaults', function () {
    config(['running-number.types' => ['invoice' => null]]);

    $this->artisan('running-number:sync')->assertSuccessful();

    expect(RunningNumber::query()->type('invoice')->first()->padding)->toBe(config('running-number.default_padding'));
});

it('rejects a type whose settings are not an array and syncs nothing', function () {
    config(['running-number.types' => ['invoice', 'permit']]);

    $this->artisan('running-number:sync')
        ->expectsOutputToContain('must map to an array of settings')
        ->assertFailed();

    expect(RunningNumber::query()->count())->toBe(0);
});

it('is idempotent — running it twice does not duplicate rows or reset live counters', function () {
    config(['running-number.types' => ['invoice' => ['padding' => 4]]]);

    $this->artisan('running-number:sync')->assertSuccessful();
    RunningNumber::query()->type('invoice')->update(['number' => 10]);

    $this->artisan('running-number:sync')->assertSuccessful();

    expect(RunningNumber::query()->where('type', 'invoice')->count())->toBe(1);
    expect(RunningNumber::query()->type('invoice')->first()->number)->toBe(10);
});

it('retries its transaction after a deadlock', function (string $statement) {
    // Laravel retries only the outermost transaction, so leave the one that RefreshDatabase opened.
    DB::rollBack();

    try {
        RunningNumber::factory()->create(['type' => 'invoice']);

        config(['running-number.types' => [
            'invoice' => ['interval' => ResetInterval::DAILY],
            'permit' => ['padding' => 5],
        ]]);

        $deadlocks = 0;
        DB::beforeExecuting(function (string $sql) use (&$deadlocks, $statement) {
            if ($deadlocks === 0 && preg_match("/^{$statement} [`\"]running_numbers[`\"]/", $sql)) {
                $deadlocks++;

                throw new DeadlockException('Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        $this->artisan('running-number:sync')->assertSuccessful();

        expect($deadlocks)->toBe(1);
        expect(RunningNumber::query()->type('invoice')->first()->reset_interval)->toBe(ResetInterval::DAILY);
        expect(RunningNumber::query()->type('permit')->first()->padding)->toBe(5);
    } finally {
        DB::table('running_numbers')->delete();
        DB::beginTransaction();
    }
})->with(['a schedule change in its nested transaction' => 'update', 'a new type after earlier work' => 'insert into']);
