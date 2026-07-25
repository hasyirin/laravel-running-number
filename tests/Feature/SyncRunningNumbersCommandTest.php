<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Models\RunningNumber;

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

it('is idempotent — running it twice does not duplicate rows or reset live counters', function () {
    config(['running-number.types' => ['invoice' => ['padding' => 4]]]);

    $this->artisan('running-number:sync')->assertSuccessful();
    RunningNumber::query()->type('invoice')->update(['number' => 10]);

    $this->artisan('running-number:sync')->assertSuccessful();

    expect(RunningNumber::query()->where('type', 'invoice')->count())->toBe(1);
    expect(RunningNumber::query()->type('invoice')->first()->number)->toBe(10);
});
