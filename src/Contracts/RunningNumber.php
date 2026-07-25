<?php

namespace Hasyirin\RunningNumber\Contracts;

use BackedEnum;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\PendingRunningNumber;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

interface RunningNumber
{
    public function generate(Arrayable|array|string|int|BackedEnum|Model $type): string;

    public function for(Arrayable|array|string|int|BackedEnum|Model $type): PendingRunningNumber;

    public function generateFor(string $type, ?string $format, ?int $padding, ?string $timezone): string;

    public function currentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string;

    public function previewFor(string $type, ?int $number, ?string $format, ?int $padding, ?string $timezone): string;

    public function previewCurrentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string;

    public function registerFor(
        string $type,
        ?ResetInterval $interval,
        ?int $padding,
        ?string $format,
        ?int $resetMonth,
        ?int $resetDay,
        ?int $resetWeekday,
        ?int $resetHour,
        ?UndoMode $undoMode,
        ?string $timezone,
    ): void;

    public function skipFor(string $type, int|array $numbers, ?string $reason): void;

    public function useSkippedFor(string $type, int $number, ?string $format, ?int $padding, ?string $timezone): string;

    public function undoFor(string $type, int $number, ?string $reason): void;

    public function resetFor(string $type): void;
}
