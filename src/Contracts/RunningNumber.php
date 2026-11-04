<?php

namespace Hasyirin\RunningNumber\Contracts;

use BackedEnum;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\PendingRunningNumber;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-type RunningNumberType Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model
 */
interface RunningNumber
{
    /**
     * @param  RunningNumberType  $type
     */
    public function generate(Arrayable|array|string|int|BackedEnum|Model $type): string;

    /**
     * @param  RunningNumberType  $type
     */
    public function for(Arrayable|array|string|int|BackedEnum|Model $type): PendingRunningNumber;

    public function generateFor(string $type, ?string $format, ?int $padding, ?string $timezone): string;

    public function currentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string;

    public function previewFor(string $type, ?int $number, ?string $format, ?int $padding, ?string $timezone): string;

    public function previewCurrentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string;

    public function registerFor(
        string $type,
        ?ResetInterval $interval = null,
        ?int $padding = null,
        ?string $format = null,
        ?int $resetMonth = null,
        ?int $resetDay = null,
        ?int $resetWeekday = null,
        ?int $resetHour = null,
        ?UndoMode $undoMode = null,
        ?string $timezone = null,
        bool $replace = false,
    ): void;

    /**
     * @param  int|array<int>  $numbers
     */
    public function skipFor(string $type, int|array $numbers, ?string $reason): void;

    public function useSkippedFor(string $type, int $number, ?string $format, ?int $padding, ?string $timezone): string;

    public function undoFor(string $type, int $number, ?string $reason): void;

    public function resetFor(string $type): void;
}
