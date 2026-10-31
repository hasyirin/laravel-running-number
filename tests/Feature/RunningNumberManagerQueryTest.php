<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Exceptions\RunningNumberTypeNotRegistered;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;
use Illuminate\Support\Carbon;

it('current() returns the stored number formatted, read-only', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 7, 'padding' => 4]);

    expect((new RunningNumberManager)->currentFor('invoice', null, null, null))->toBe('0007');
    expect(RunningNumber::query()->type('invoice')->first()->number)->toBe(7); // unchanged
});

it('current() formats a number from a period that ended, before its reset runs, for that period', function (array $settings, string $lastResetAt, string $expected) {
    RunningNumber::factory()->create([
        'type' => 'invoice', 'reset_interval' => ResetInterval::YEARLY, 'number' => 7, 'padding' => 3,
        'last_reset_at' => Carbon::parse($lastResetAt), ...$settings,
    ]);
    Carbon::setTestNow('2027-01-02 09:00:00');

    expect((new RunningNumberManager)->currentFor('invoice', null, null, null))->toBe($expected);

    Carbon::setTestNow();
})->with([
    'period token, Dec-14 year' => [['reset_month' => 12, 'reset_day' => 14, 'format' => '{number}/{period:Y}'], '2025-12-14 00:00:00', '007/2026'],
    'date token, calendar year' => [['format' => '{Y}/{number}'], '2026-01-01 00:00:00', '2026/007'],
]);

it('current() always throws for an unregistered type, even with auto_register on', function () {
    config(['running-number.auto_register' => true]);

    expect(fn () => (new RunningNumberManager)->currentFor('invoice', null, null, null))
        ->toThrow(RunningNumberTypeNotRegistered::class);
    expect(RunningNumber::query()->type('invoice')->exists())->toBeFalse();
});

it('preview() renders a placeholder of X characters for the row\'s padding, without creating a row', function () {
    config(['running-number.default_padding' => 5]);

    expect((new RunningNumberManager)->previewFor('invoice', null, null, null, null))->toBe('XXXXX');
    expect(RunningNumber::query()->type('invoice')->exists())->toBeFalse();
});

it('preview() renders a type declared in running-number.types with its declared settings before it has a row', function () {
    config(['running-number.types.receipt' => ['padding' => 3, 'format' => 'R-{number}']]);

    expect((new RunningNumberManager)->previewFor('receipt', null, null, null, null))->toBe('R-XXX');
});

it('preview(int) renders the given number padded instead of a placeholder', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'padding' => 4]);

    expect((new RunningNumberManager)->previewFor('invoice', 64, null, null, null))->toBe('0064');
});

it('previewCurrent() behaves identically to current() (an alias for API discoverability)', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'number' => 7, 'padding' => 4]);

    $manager = new RunningNumberManager;
    expect($manager->previewCurrentFor('invoice', null, null, null))->toBe($manager->currentFor('invoice', null, null, null));
});

it('reset() forces number back to 0 and bumps reset_cycle regardless of schedule', function () {
    $row = RunningNumber::factory()->create(['type' => 'invoice', 'number' => 42, 'reset_cycle' => 1]);

    (new RunningNumberManager)->resetFor('invoice');

    $row->refresh();
    expect($row->number)->toBe(0);
    expect($row->reset_cycle)->toBe(2);
});
