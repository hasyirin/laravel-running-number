<?php

namespace Hasyirin\RunningNumber;

use BackedEnum;
use Hasyirin\RunningNumber\Concerns\ResolvesType;
use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Exceptions\InvalidResetAnchor;
use Hasyirin\RunningNumber\Exceptions\RunningNumberAlreadyIssued;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotSkipped;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotUndoable;
use Hasyirin\RunningNumber\Exceptions\RunningNumberTypeNotRegistered;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RunningNumberManager implements RunningNumberContract
{
    use ResolvesType;

    public function generate(Arrayable|array|string|int|BackedEnum|Model $type): string
    {
        return $this->generateFor($this->resolveType($type), null, null, null);
    }

    public function for(Arrayable|array|string|int|BackedEnum|Model $type): PendingRunningNumber
    {
        return new PendingRunningNumber($this, $this->resolveType($type));
    }

    public function generateFor(string $type, ?string $format, ?int $padding, ?string $timezone): string
    {
        return DB::transaction(function () use ($type, $format, $padding, $timezone) {
            $row = RunningNumber::query()->type($type)->lockForUpdate()->first();

            if (! $row) {
                if (! config('running-number.auto_register')) {
                    throw RunningNumberTypeNotRegistered::forType($type);
                }

                $this->registerFor($type, null, null, null, null, null, null, null, null, null);
                $row = $this->lockedRow($type);
            }

            $this->resetIfDue($row);

            $reclaimed = $row->undos()
                ->where('mode', UndoMode::RECLAIM)
                ->where('reset_cycle', $row->reset_cycle)
                ->whereNull('reclaimed_at')
                ->orderBy('number')
                ->lockForUpdate()
                ->first();

            if ($reclaimed) {
                $reclaimed->update(['reclaimed_at' => now()]);

                return $this->format($row, $reclaimed->number, $format, $padding, $timezone);
            }

            do {
                $row->number++;
            } while ($row->skips()->where('reset_cycle', $row->reset_cycle)->where('number', $row->number)->exists());

            $row->save();

            return $this->format($row, $row->number, $format, $padding, $timezone);
        });
    }

    public function currentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string
    {
        $row = RunningNumber::query()->type($type)->first();

        if (! $row) {
            throw RunningNumberTypeNotRegistered::forType($type);
        }

        return $this->format($row, $row->number, $format, $padding, $timezone);
    }

    public function previewFor(string $type, ?int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        $row = RunningNumber::query()->type($type)->first();

        $resolvedFormat = $format ?? $row->format ?? config('running-number.default_format');
        $resolvedPadding = $padding ?? $row->padding ?? config('running-number.default_padding');
        $resolvedTimezone = $timezone ?? $row->timezone ?? config('running-number.default_timezone');

        $representation = $number !== null
            ? str_pad((string) $number, $resolvedPadding, '0', STR_PAD_LEFT)
            : str_repeat('X', $resolvedPadding);

        return $this->render($resolvedFormat, $representation, $resolvedTimezone);
    }

    public function previewCurrentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string
    {
        // Deliberately identical to currentFor() — previewCurrent() is a semantic alias
        // grouping it with preview()/preview(int) as "the preview family," not a
        // behaviorally distinct operation. Both respect overrides, both require a real row.
        return $this->currentFor($type, $format, $padding, $timezone);
    }

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
    ): void {
        $interval ??= config('running-number.default_interval');

        $this->validateAnchor($interval, $resetMonth, $resetDay, $resetWeekday, $resetHour);

        $attributes = [
            'reset_interval' => $interval,
            'reset_month' => $resetMonth,
            'reset_day' => $resetDay,
            'reset_weekday' => $resetWeekday,
            'reset_hour' => $resetHour,
            'timezone' => $timezone ?? config('running-number.default_timezone'),
            'undo_mode' => $undoMode ?? config('running-number.default_undo_mode'),
            'padding' => $padding ?? config('running-number.default_padding'),
            'format' => $format,
        ];

        $existing = RunningNumber::query()->type($type)->first();

        if ($existing) {
            $existing->update($attributes);

            return;
        }

        RunningNumber::query()->create(['type' => $type, ...$attributes]);
    }

    public function skipFor(string $type, int|array $numbers, ?string $reason): void
    {
        DB::transaction(function () use ($type, $numbers, $reason) {
            $row = $this->lockedRow($type);

            foreach (Arr::wrap($numbers) as $number) {
                if ($number <= $row->number) {
                    throw RunningNumberAlreadyIssued::forNumber($type, $number, $row->number);
                }

                $row->skips()->firstOrCreate(
                    ['number' => $number, 'reset_cycle' => $row->reset_cycle],
                    [
                        'formatted_number' => $this->format($row, $number, null, null, null),
                        'reason' => $reason,
                    ],
                );
            }
        });
    }

    public function useSkippedFor(string $type, int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        return DB::transaction(function () use ($type, $number, $format, $padding, $timezone) {
            $row = $this->lockedRow($type);

            $skip = $row->skips()
                ->where('number', $number)
                ->where('reset_cycle', $row->reset_cycle)
                ->whereNull('used_at')
                ->first();

            if (! $skip) {
                throw RunningNumberNotSkipped::forNumber($type, $number);
            }

            $skip->update(['used_at' => now()]);

            return $this->format($row, $number, $format, $padding, $timezone);
        });
    }

    public function undoFor(string $type, int $number, ?string $reason): void
    {
        DB::transaction(function () use ($type, $number, $reason) {
            $row = $this->lockedRow($type);

            if ($number > $row->number) {
                throw RunningNumberNotUndoable::forNumber($type, $number);
            }

            $hasSkip = $row->skips()->where('number', $number)->where('reset_cycle', $row->reset_cycle)->exists();

            if ($hasSkip) {
                throw RunningNumberNotUndoable::forNumber($type, $number);
            }

            $alreadyUndone = $row->undos()->where('number', $number)->where('reset_cycle', $row->reset_cycle)->exists();

            if ($alreadyUndone) {
                throw RunningNumberNotUndoable::forNumber($type, $number);
            }

            $row->undos()->create([
                'number' => $number,
                'formatted_number' => $this->format($row, $number, null, null, null),
                'mode' => $row->undo_mode,
                'reason' => $reason,
                'reset_cycle' => $row->reset_cycle,
                'reclaimed_at' => null,
            ]);
        });
    }

    public function resetFor(string $type): void
    {
        DB::transaction(function () use ($type) {
            $row = $this->lockedRow($type);

            $row->update([
                'number' => 0,
                'last_reset_at' => now($row->timezone),
                'reset_cycle' => $row->reset_cycle + 1,
            ]);
        });
    }

    private function validateAnchor(ResetInterval $interval, ?int $month, ?int $day, ?int $weekday, ?int $hour): void
    {
        $allowed = match ($interval) {
            ResetInterval::NEVER => [],
            ResetInterval::DAILY => ['hour'],
            ResetInterval::WEEKLY => ['weekday', 'hour'],
            ResetInterval::MONTHLY => ['day', 'hour'],
            ResetInterval::QUARTERLY, ResetInterval::YEARLY => ['month', 'day', 'hour'],
        };

        $supplied = array_filter(
            ['month' => $month, 'day' => $day, 'weekday' => $weekday, 'hour' => $hour],
            fn ($value) => $value !== null,
        );

        foreach ($supplied as $field => $value) {
            if (! in_array($field, $allowed, true)) {
                throw InvalidResetAnchor::fieldNotAllowed('reset'.ucfirst($field), $interval);
            }
        }

        $ranges = ['month' => [1, 12], 'day' => [1, 31], 'weekday' => [0, 6], 'hour' => [0, 23]];

        foreach ($supplied as $field => $value) {
            [$min, $max] = $ranges[$field];

            if ($value < $min || $value > $max) {
                throw InvalidResetAnchor::outOfRange('reset'.ucfirst($field), $value, $min, $max);
            }
        }
    }

    private function lockedRow(string $type): RunningNumber
    {
        $row = RunningNumber::query()->type($type)->lockForUpdate()->first();

        if (! $row) {
            throw RunningNumberTypeNotRegistered::forType($type);
        }

        return $row;
    }

    private function resetIfDue(RunningNumber $row): void
    {
        $needsReset = $row->reset_interval->needsReset(
            $row->last_reset_at,
            $row->reset_month,
            $row->reset_day,
            $row->reset_weekday,
            $row->reset_hour,
            $row->timezone,
        );

        if (! $needsReset) {
            return;
        }

        $row->update([
            'number' => 0,
            'last_reset_at' => now($row->timezone),
            'reset_cycle' => $row->reset_cycle + 1,
        ]);
    }

    private function render(string $format, string $numberRepresentation, ?string $timezone): string
    {
        return preg_replace_callback('/\{([^}]+)\}/', function ($matches) use ($numberRepresentation, $timezone) {
            return $matches[1] === 'number' ? $numberRepresentation : now($timezone)->format($matches[1]);
        }, $format);
    }

    private function format(RunningNumber $row, int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        $format ??= $row->format ?? config('running-number.default_format');
        $padding ??= $row->padding;
        $timezone ??= $row->timezone;

        return $this->render($format, str_pad((string) $number, $padding, '0', STR_PAD_LEFT), $timezone);
    }
}
