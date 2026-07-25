<?php

use Hasyirin\RunningNumber\Enums\UndoMode;

it('has reclaim and record cases with the expected string values', function () {
    expect(UndoMode::RECLAIM->value)->toBe('reclaim');
    expect(UndoMode::RECORD->value)->toBe('record');
});
