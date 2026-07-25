<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Exceptions\InvalidResetAnchor;
use Hasyirin\RunningNumber\Exceptions\RunningNumberAlreadyIssued;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotSkipped;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotUndoable;
use Hasyirin\RunningNumber\Exceptions\RunningNumberTypeNotRegistered;

it('builds a clear message for an unregistered type', function () {
    expect(RunningNumberTypeNotRegistered::forType('invoice')->getMessage())->toContain('invoice');
});

it('builds a clear message for a disallowed anchor field', function () {
    $message = InvalidResetAnchor::fieldNotAllowed('resetWeekday', ResetInterval::MONTHLY)->getMessage();

    expect($message)->toContain('resetWeekday')->toContain('monthly');
});

it('builds a clear message for an out-of-range anchor value', function () {
    $message = InvalidResetAnchor::outOfRange('resetMonth', 13, 1, 12)->getMessage();

    expect($message)->toContain('resetMonth')->toContain('13');
});

it('builds a clear message for an already-issued skip target', function () {
    $message = RunningNumberAlreadyIssued::forNumber('invoice', 5, 10)->getMessage();

    expect($message)->toContain('invoice')->toContain('5')->toContain('10');
});

it('builds a clear message for an un-skipped useSkipped target', function () {
    expect(RunningNumberNotSkipped::forNumber('invoice', 29)->getMessage())->toContain('29');
});

it('builds a clear message for a non-undoable target', function () {
    expect(RunningNumberNotUndoable::forNumber('invoice', 4)->getMessage())->toContain('4');
});
