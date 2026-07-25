<?php

use Hasyirin\RunningNumber\Facades\RunningNumber;
use PHPUnit\Framework\ExpectationFailedException;

it('fake() intercepts generate() without touching the real database', function () {
    RunningNumber::fake();

    $result = RunningNumber::generate('invoice');

    expect($result)->toBe(str_pad('1', config('running-number.default_padding'), '0', STR_PAD_LEFT));
    expect(Hasyirin\RunningNumber\Models\RunningNumber::query()->exists())->toBeFalse();
    RunningNumber::assertGenerated('invoice', times: 1);
});

it('assertGenerated fails when the type was not generated', function () {
    RunningNumber::fake();

    RunningNumber::generate('invoice');

    expect(fn () => RunningNumber::assertGenerated('permit'))->toThrow(ExpectationFailedException::class);
});

it('assertNotGenerated passes when the type was never generated', function () {
    RunningNumber::fake();

    RunningNumber::generate('invoice');

    RunningNumber::assertNotGenerated('permit');
});

it('captures calls made through for() the same as direct calls', function () {
    RunningNumber::fake();

    RunningNumber::for('invoice')->format('{Y}-{number}')->generate();

    RunningNumber::assertGenerated('invoice', times: 1);
});

it('simulates skip preventing reissue and useSkipped/undo/reset in-memory', function () {
    $fake = RunningNumber::fake();

    RunningNumber::for('invoice')->skip(2);
    expect(RunningNumber::generate('invoice'))->not->toBe(str_pad('2', 5, '0', STR_PAD_LEFT));
    RunningNumber::assertSkipped('invoice', 2);

    RunningNumber::for('invoice')->useSkipped(2);
    RunningNumber::assertUsedSkipped('invoice', 2);

    RunningNumber::for('invoice')->undo(1);
    RunningNumber::assertUndone('invoice', 1);

    RunningNumber::for('invoice')->reset();
    RunningNumber::assertReset('invoice', times: 1);
});

it('assertRegistered checks that register() was called, optionally with a matcher callback', function () {
    RunningNumber::fake();

    RunningNumber::for('invoice')->register(padding: 4);

    RunningNumber::assertRegistered('invoice', fn ($entry) => $entry['padding'] === 4);
});

it('reset() clears skip and reclaim state for that type, matching real reset_cycle scoping', function () {
    RunningNumber::fake();

    RunningNumber::for('invoice')->skip(2);
    RunningNumber::for('invoice')->reset();

    expect(RunningNumber::generate('invoice'))->toBe(str_pad('1', config('running-number.default_padding'), '0', STR_PAD_LEFT));
    expect(RunningNumber::generate('invoice'))->toBe(str_pad('2', config('running-number.default_padding'), '0', STR_PAD_LEFT));
});

it('generated() returns the raw log for a type', function () {
    RunningNumber::fake();

    RunningNumber::generate('invoice');
    RunningNumber::generate('invoice');
    RunningNumber::generate('permit');

    expect(RunningNumber::generated('invoice'))->toHaveCount(2);
});
