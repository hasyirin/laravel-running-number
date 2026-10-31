<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Exceptions\InvalidResetAnchor;
use Hasyirin\RunningNumber\Exceptions\RunningNumberAlreadyIssued;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotSkipped;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotUndoable;
use Hasyirin\RunningNumber\Exceptions\RunningNumberTypeNotRegistered;
use Hasyirin\RunningNumber\Facades\RunningNumber;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(fn () => config(['running-number.types' => ['invoice' => [], 'permit' => []]]));

it('throws for a type that is not registered, not declared in config, and not auto-registered', function () {
    RunningNumber::fake();

    expect(fn () => RunningNumber::generate('receipt'))->toThrow(RunningNumberTypeNotRegistered::class);
});

it('auto-registers an unknown type when auto_register is on', function () {
    config(['running-number.auto_register' => true]);
    RunningNumber::fake();

    expect(RunningNumber::generate('receipt'))->toBe('00001');
});

it('throws for an unknown type when auto_register is null', function () {
    config(['running-number.auto_register' => null]);
    RunningNumber::fake();

    expect(fn () => RunningNumber::generate('receipt'))->toThrow(RunningNumberTypeNotRegistered::class);
});

it('treats a type declared with null as registered with the defaults', function () {
    config(['running-number.types.receipt' => null]);
    RunningNumber::fake();

    expect(RunningNumber::generate('receipt'))->toBe('00001');
});

it('renders a config-declared type with its configured padding and format', function () {
    config(['running-number.types.receipt' => ['padding' => 3, 'format' => 'R-{number}']]);
    RunningNumber::fake();

    expect(RunningNumber::generate('receipt'))->toBe('R-001');
});

it('throws for an unregistered type on every other stateful method, even with auto_register on', function (string $method, array $arguments) {
    config(['running-number.auto_register' => true]);
    RunningNumber::fake();

    expect(fn () => RunningNumber::for('receipt')->{$method}(...$arguments))->toThrow(RunningNumberTypeNotRegistered::class);
})->with([
    'current' => ['current', []],
    'previewCurrent' => ['previewCurrent', []],
    'skip' => ['skip', [2]],
    'useSkipped' => ['useSkipped', [2]],
    'undo' => ['undo', [1]],
    'reset' => ['reset', []],
]);

it('previews an unregistered type with the config defaults', function () {
    RunningNumber::fake();

    expect(RunningNumber::for('receipt')->preview())->toBe('XXXXX');
});

it('validates anchors on register', function () {
    RunningNumber::fake();

    expect(fn () => RunningNumber::for('invoice')->register(interval: ResetInterval::MONTHLY, resetWeekday: 1))
        ->toThrow(InvalidResetAnchor::class);
});

it('validates the padding on register', function () {
    RunningNumber::fake();

    expect(fn () => RunningNumber::for('invoice')->register(padding: -1))->toThrow(InvalidArgumentException::class);
});

it('validates the timezone on register', function () {
    RunningNumber::fake();

    expect(fn () => RunningNumber::for('invoice')->register(timezone: 'Asia/KualaLumpur'))->toThrow(DateInvalidTimeZoneException::class);
});

it('changes only the passed settings when re-registering', function () {
    RunningNumber::fake();
    RunningNumber::for('receipt')->register(padding: 4, format: 'R-{number}');

    RunningNumber::for('receipt')->register(padding: 6);

    expect(RunningNumber::generate('receipt'))->toBe('R-000001');
});

it('throws RunningNumberAlreadyIssued when skipping an issued number, and skips none of the batch', function () {
    RunningNumber::fake();
    RunningNumber::generate('invoice');

    expect(fn () => RunningNumber::for('invoice')->skip([3, 1]))->toThrow(RunningNumberAlreadyIssued::class);
    expect([RunningNumber::generate('invoice'), RunningNumber::generate('invoice')])->toBe(['00002', '00003']);
});

it('throws RunningNumberNotSkipped for a number that was never skipped', function () {
    RunningNumber::fake();

    expect(fn () => RunningNumber::for('invoice')->useSkipped(9))->toThrow(RunningNumberNotSkipped::class);
});

it('throws RunningNumberNotSkipped when a skipped number is used twice', function () {
    RunningNumber::fake();
    RunningNumber::for('invoice')->skip(5);
    RunningNumber::for('invoice')->useSkipped(5);

    expect(fn () => RunningNumber::for('invoice')->useSkipped(5))->toThrow(RunningNumberNotSkipped::class);
});

it('throws RunningNumberNotUndoable for a number that cannot be undone', function (array $skipped, int $number) {
    RunningNumber::fake();
    RunningNumber::generate('invoice');
    RunningNumber::for('invoice')->skip($skipped);

    expect(fn () => RunningNumber::for('invoice')->undo($number))->toThrow(RunningNumberNotUndoable::class);
})->with([
    'not yet issued' => [[], 2],
    'zero' => [[], 0],
    'skipped but unused' => [[5], 5],
]);

it('throws RunningNumberNotUndoable when the same number is undone twice', function () {
    RunningNumber::fake();
    RunningNumber::generate('invoice');
    RunningNumber::for('invoice')->undo(1);

    expect(fn () => RunningNumber::for('invoice')->undo(1))->toThrow(RunningNumberNotUndoable::class);
});

it('reissues an undone number in reclaim mode, and allows undoing it again', function () {
    RunningNumber::fake();
    RunningNumber::generate('invoice');
    RunningNumber::generate('invoice');

    RunningNumber::for('invoice')->undo(1);
    $reissued = RunningNumber::generate('invoice');
    RunningNumber::for('invoice')->undo(1);

    expect([$reissued, RunningNumber::generate('invoice'), RunningNumber::generate('invoice')])->toBe(['00001', '00001', '00003']);
});

it('never reissues an undone number in record mode', function () {
    RunningNumber::fake();
    RunningNumber::for('invoice')->register(undoMode: UndoMode::RECORD);
    RunningNumber::generate('invoice');
    RunningNumber::generate('invoice');

    RunningNumber::for('invoice')->undo(1);

    expect(RunningNumber::generate('invoice'))->toBe('00003')
        ->and(fn () => RunningNumber::for('invoice')->undo(1))->toThrow(RunningNumberNotUndoable::class);
});

it('stops reissuing numbers undone in reclaim mode once the type switches to record mode', function () {
    RunningNumber::fake();
    RunningNumber::generate('invoice');
    RunningNumber::generate('invoice');
    RunningNumber::for('invoice')->undo(1);

    RunningNumber::for('invoice')->register(undoMode: UndoMode::RECORD);

    expect(RunningNumber::generate('invoice'))->toBe('00003');
});

it('reads a declared type\'s settings when a call uses the type, not when preview() reads them', function () {
    config(['running-number.types.receipt' => ['padding' => 3, 'format' => 'R-{number}']]);
    RunningNumber::fake();
    RunningNumber::for('receipt')->preview();

    config(['running-number.types.receipt' => ['padding' => 6, 'format' => 'R-{number}']]);

    expect(RunningNumber::generate('receipt'))->toBe('R-000001');
});

it('undoes a number issued through useSkipped', function () {
    RunningNumber::fake();
    RunningNumber::for('invoice')->skip(5);
    RunningNumber::for('invoice')->useSkipped(5);

    RunningNumber::for('invoice')->undo(5);

    RunningNumber::assertUndone('invoice', 5);
});

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

it('fails each assertion when the expected call did not happen', function (string $assertion, array $arguments) {
    RunningNumber::fake();
    RunningNumber::generate('invoice');

    expect(fn () => RunningNumber::$assertion(...$arguments))->toThrow(ExpectationFailedException::class);
})->with([
    'assertNotGenerated' => ['assertNotGenerated', ['invoice']],
    'assertSkipped' => ['assertSkipped', ['invoice', 2]],
    'assertUsedSkipped' => ['assertUsedSkipped', ['invoice', 2]],
    'assertUndone' => ['assertUndone', ['invoice', 1]],
    'assertRegistered' => ['assertRegistered', ['invoice']],
    'assertReset' => ['assertReset', ['invoice']],
]);

it('fails assertGenerated and assertReset when the count differs', function () {
    RunningNumber::fake();
    RunningNumber::generate('invoice');
    RunningNumber::for('invoice')->reset();

    expect(fn () => RunningNumber::assertGenerated('invoice', times: 2))->toThrow(ExpectationFailedException::class);
    expect(fn () => RunningNumber::assertReset('invoice', times: 2))->toThrow(ExpectationFailedException::class);
});

it('fails assertRegistered when no registration matches the callback', function () {
    RunningNumber::fake();
    RunningNumber::for('invoice')->register(padding: 4);

    expect(fn () => RunningNumber::assertRegistered('invoice', fn (array $entry) => $entry['padding'] === 6))
        ->toThrow(ExpectationFailedException::class);
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
