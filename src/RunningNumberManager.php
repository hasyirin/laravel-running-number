<?php

namespace Hasyirin\RunningNumber;

use BackedEnum;
use Carbon\CarbonInterface;
use Hasyirin\RunningNumber\Concerns\ResolvesSettings;
use Hasyirin\RunningNumber\Concerns\ResolvesType;
use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Exceptions\RunningNumberAlreadyIssued;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotSkipped;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotUndoable;
use Hasyirin\RunningNumber\Exceptions\RunningNumberTypeNotRegistered;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @phpstan-import-type RunningNumberType from RunningNumberContract
 * @phpstan-import-type Settings from ResolvesSettings
 */
class RunningNumberManager implements RunningNumberContract
{
    use ResolvesSettings;
    use ResolvesType;

    private const SCHEDULE = ['reset_interval', 'reset_month', 'reset_day', 'reset_weekday', 'reset_hour', 'timezone'];

    private const SKIP_WINDOW = 1000;

    public const TRANSACTION_ATTEMPTS = 3;

    /**
     * @param  RunningNumberType  $type
     */
    public function generate(Arrayable|array|string|int|BackedEnum|Model $type): string
    {
        return $this->generateFor($this->resolveType($type), null, null, null);
    }

    /**
     * @param  RunningNumberType  $type
     */
    public function for(Arrayable|array|string|int|BackedEnum|Model $type): PendingRunningNumber
    {
        return new PendingRunningNumber($this, $this->resolveType($type));
    }

    public function generateFor(string $type, ?string $format, ?int $padding, ?string $timezone): string
    {
        if (config('running-number.auto_register')) {
            $this->registerIfMissing($type);
        }

        return DB::transaction(function () use ($type, $format, $padding, $timezone) {
            $row = $this->lockedRow($type);
            $now = now();

            $this->resetIfDue($row, $now);

            if ($row->undo_mode === UndoMode::RECLAIM) {
                $reclaimed = $row->undos()
                    ->where('mode', UndoMode::RECLAIM)
                    ->where('reset_cycle', $row->reset_cycle)
                    ->whereNull('reclaimed_at')
                    ->orderBy('number')
                    ->lockForUpdate()
                    ->first();

                if ($reclaimed) {
                    $reclaimed->update(['reclaimed_at' => $now]);

                    return $this->format($row, $reclaimed->number, $format, $padding, $timezone, $now);
                }
            }

            $row->number = $this->nextFreeNumber($row);
            $row->save();

            return $this->format($row, $row->number, $format, $padding, $timezone, $now);
        }, attempts: self::TRANSACTION_ATTEMPTS);
    }

    public function currentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string
    {
        $row = RunningNumber::query()->type($type)->first();

        if (! $row) {
            throw RunningNumberTypeNotRegistered::forType($type);
        }

        $now = now();
        $nextReset = $this->nextBoundary($row, $row->last_reset_at);

        // A due reset has not run yet, so the current number still belongs to the period that ended.
        $at = $nextReset?->lte($now) ? $nextReset->subSecond() : $now;

        return $this->format($row, $row->number, $format, $padding, $timezone, $at);
    }

    public function previewFor(string $type, ?int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        $row = RunningNumber::query()->type($type)->first() ?? new RunningNumber($this->declaredSettings($type) ?? $this->defaultSettings());
        $padding ??= $row->padding;

        $representation = $number !== null
            ? str_pad((string) $number, $padding, '0', STR_PAD_LEFT)
            : str_repeat('X', $padding);

        return $this->render($row, $representation, $format, $timezone, now());
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
    ): void {
        $resolve = fn (?RunningNumber $row) => $this->resolveSettings(
            $row?->only(array_keys($this->defaultSettings())) ?? $this->declaredSettings($type),
            $replace,
            $interval,
            $padding,
            $format,
            $resetMonth,
            $resetDay,
            $resetWeekday,
            $resetHour,
            $undoMode,
            $timezone,
        );

        $existing = RunningNumber::query()->type($type)->first();

        if (! $existing) {
            // Another request can create the type first. The insert then changes nothing and the row is patched below.
            // The locking read sees that row even from an older snapshot of the caller's transaction.
            $this->insertType($type, $resolve(null));
            $existing = $this->lockedRow($type);
        }

        if (! $existing->fill($resolve($existing))->isDirty(self::SCHEDULE)) {
            $existing->save();

            return;
        }

        DB::transaction(function () use ($type, $resolve) {
            $row = $this->lockedRow($type);
            $now = now();

            $this->resetIfDue($row, $now);
            $row->fill($resolve($row));

            if ($row->isDirty(self::SCHEDULE)) {
                $row->last_reset_at = $now;
            }

            $row->save();
        }, attempts: self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @param  int|array<int>  $numbers
     */
    public function skipFor(string $type, int|array $numbers, ?string $reason): void
    {
        DB::transaction(function () use ($type, $numbers, $reason) {
            $row = $this->lockedRow($type);
            $now = now();

            $this->resetIfDue($row, $now);

            foreach (Arr::wrap($numbers) as $number) {
                if ($number <= $row->number) {
                    throw RunningNumberAlreadyIssued::forNumber($type, $number, $row->number);
                }

                $row->skips()->lockForUpdate()->firstOrCreate(
                    ['number' => $number, 'reset_cycle' => $row->reset_cycle],
                    [
                        'formatted_number' => $this->format($row, $number, null, null, null, $now),
                        'reason' => $reason,
                    ],
                );
            }
        }, attempts: self::TRANSACTION_ATTEMPTS);
    }

    public function useSkippedFor(string $type, int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        return DB::transaction(function () use ($type, $number, $format, $padding, $timezone) {
            $row = $this->lockedRow($type);
            $now = now();

            $this->resetIfDue($row, $now);

            $skip = $row->skips()
                ->where('number', $number)
                ->where('reset_cycle', $row->reset_cycle)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if (! $skip) {
                throw RunningNumberNotSkipped::forNumber($type, $number);
            }

            $skip->update(['used_at' => $now]);

            return $this->format($row, $number, $format, $padding, $timezone, $now);
        }, attempts: self::TRANSACTION_ATTEMPTS);
    }

    public function undoFor(string $type, int $number, ?string $reason): void
    {
        DB::transaction(function () use ($type, $number, $reason) {
            $row = $this->lockedRow($type);
            $now = now();

            $this->resetIfDue($row, $now);

            $skip = $row->skips()->where('number', $number)->where('reset_cycle', $row->reset_cycle)->lockForUpdate()->first();

            $issued = $skip
                ? $skip->used_at !== null
                : $number >= 1 && $number <= $row->number;

            if (! $issued) {
                throw RunningNumberNotUndoable::forNumber($type, $number);
            }

            $undo = $row->undos()->lockForUpdate()->firstOrNew(['number' => $number, 'reset_cycle' => $row->reset_cycle]);

            if ($undo->exists && $undo->reclaimed_at === null) {
                throw RunningNumberNotUndoable::forNumber($type, $number);
            }

            $undo->fill([
                'formatted_number' => $this->format($row, $number, null, null, null, $now),
                'mode' => $row->undo_mode,
                'reason' => $reason,
                'reclaimed_at' => null,
            ])->save();
        }, attempts: self::TRANSACTION_ATTEMPTS);
    }

    public function resetFor(string $type): void
    {
        DB::transaction(function () use ($type) {
            $row = $this->lockedRow($type);

            $row->update([
                'number' => 0,
                'last_reset_at' => now(),
                'reset_cycle' => $row->reset_cycle + 1,
            ]);
        }, attempts: self::TRANSACTION_ATTEMPTS);
    }

    private function registerIfMissing(string $type): void
    {
        if (RunningNumber::query()->type($type)->exists()) {
            return;
        }

        $this->insertType($type, $this->declaredSettings($type) ?? $this->resolveSettings(null, true));
    }

    // upsert() is race-safe for new types, but it skips the model, so last_reset_at is passed in UTC here.
    /**
     * @param  Settings  $settings
     */
    private function insertType(string $type, array $settings): void
    {
        RunningNumber::query()->upsert([['type' => $type, ...$settings, 'last_reset_at' => now()->utc()]], ['type'], ['type']);
    }

    private function lockedRow(string $type): RunningNumber
    {
        $row = RunningNumber::query()->type($type)->lockForUpdate()->first();

        if (! $row) {
            throw RunningNumberTypeNotRegistered::forType($type);
        }

        return $row;
    }

    private function resetIfDue(RunningNumber $row, CarbonInterface $now): void
    {
        $needsReset = $row->reset_interval->needsReset(
            $row->last_reset_at,
            $row->reset_month,
            $row->reset_day,
            $row->reset_weekday,
            $row->reset_hour,
            $row->timezone,
            $now,
        );

        if (! $needsReset) {
            return;
        }

        $row->update([
            'number' => 0,
            'last_reset_at' => $now,
            'reset_cycle' => $row->reset_cycle + 1,
        ]);
    }

    private function nextFreeNumber(RunningNumber $row): int
    {
        $number = $row->number + 1;

        do {
            $windowEnd = $number + self::SKIP_WINDOW - 1;

            $skipped = $row->skips()
                ->where('reset_cycle', $row->reset_cycle)
                ->whereBetween('number', [$number, $windowEnd])
                ->orderBy('number')
                ->lockForUpdate()
                ->pluck('number');

            foreach ($skipped as $skippedNumber) {
                if ($skippedNumber !== $number) {
                    return $number;
                }

                $number++;
            }
        } while ($number > $windowEnd);

        return $number;
    }

    private function render(RunningNumber $row, string $numberRepresentation, ?string $format, ?string $timezone, CarbonInterface $at): string
    {
        $format ??= $row->format ?? $this->defaultFormat();

        return preg_replace_callback('/\{([^}]+)\}/', fn (array $matches) => match (true) {
            $matches[1] === 'number' => $numberRepresentation,
            str_starts_with($matches[1], 'period:') => $this->periodEnd($row, $at)->format(substr($matches[1], strlen('period:'))),
            default => $at->copy()->setTimezone($timezone ?? $row->timezone ?? date_default_timezone_get())->format($matches[1]),
        }, $format) ?? throw new RuntimeException(preg_last_error_msg());
    }

    private function periodEnd(RunningNumber $row, CarbonInterface $at): CarbonInterface
    {
        return $this->nextBoundary($row, $at)?->subSecond() ?? $at->copy()->setTimezone($row->timezone ?? date_default_timezone_get());
    }

    private function nextBoundary(RunningNumber $row, CarbonInterface $after): ?CarbonInterface
    {
        return $row->reset_interval->nextBoundary(
            $row->reset_month,
            $row->reset_day,
            $row->reset_weekday,
            $row->reset_hour,
            $row->timezone,
            $after,
        );
    }

    private function format(RunningNumber $row, int $number, ?string $format, ?int $padding, ?string $timezone, CarbonInterface $at): string
    {
        return $this->render($row, str_pad((string) $number, $padding ?? $row->padding, '0', STR_PAD_LEFT), $format, $timezone, $at);
    }
}
