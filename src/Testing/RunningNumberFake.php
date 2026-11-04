<?php

namespace Hasyirin\RunningNumber\Testing;

use BackedEnum;
use Closure;
use Hasyirin\RunningNumber\Concerns\ResolvesSettings;
use Hasyirin\RunningNumber\Concerns\ResolvesType;
use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Exceptions\RunningNumberAlreadyIssued;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotSkipped;
use Hasyirin\RunningNumber\Exceptions\RunningNumberNotUndoable;
use Hasyirin\RunningNumber\Exceptions\RunningNumberTypeNotRegistered;
use Hasyirin\RunningNumber\PendingRunningNumber;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @phpstan-import-type RunningNumberType from RunningNumberContract
 * @phpstan-import-type Settings from ResolvesSettings
 *
 * @phpstan-type Registration array{type: string, interval: ?ResetInterval, padding: ?int, format: ?string, resetMonth: ?int, resetDay: ?int, resetWeekday: ?int, resetHour: ?int, undoMode: ?UndoMode, timezone: ?string}
 */
class RunningNumberFake implements RunningNumberContract
{
    use ResolvesSettings;
    use ResolvesType;

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, Settings> */
    private array $settings = [];

    /** @var array<string, array<int, bool>> */
    private array $skips = [];

    /** @var array<string, array<int, true>> */
    private array $undoneNumbers = [];

    /** @var array<string, list<int>> */
    private array $reclaimQueue = [];

    /** @var Collection<int, array{type: string, number: int, result: string}> */
    private Collection $generated;

    /** @var Collection<int, Registration> */
    private Collection $registered;

    /** @var Collection<int, array{type: string, number: int, reason: ?string}> */
    private Collection $skipped;

    /** @var Collection<int, array{type: string, number: int}> */
    private Collection $usedSkipped;

    /** @var Collection<int, array{type: string, number: int, reason: ?string}> */
    private Collection $undone;

    /** @var Collection<int, array{type: string}> */
    private Collection $resets;

    public function __construct()
    {
        $this->generated = new Collection;
        $this->registered = new Collection;
        $this->skipped = new Collection;
        $this->usedSkipped = new Collection;
        $this->undone = new Collection;
        $this->resets = new Collection;
    }

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
        $settings = $this->settingsFor($type, (bool) config('running-number.auto_register'));

        if ($settings['undo_mode'] === UndoMode::RECLAIM && ($this->reclaimQueue[$type] ?? []) !== []) {
            sort($this->reclaimQueue[$type]);
            $number = array_shift($this->reclaimQueue[$type]);
            unset($this->undoneNumbers[$type][$number]);
        } else {
            $this->counters[$type] ??= 0;

            do {
                $this->counters[$type]++;
            } while (isset($this->skips[$type][$this->counters[$type]]));

            $number = $this->counters[$type];
        }

        $result = $this->render($type, $number, $format, $padding, $timezone);

        $this->generated->push(['type' => $type, 'number' => $number, 'result' => $result]);

        return $result;
    }

    public function currentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string
    {
        $this->settingsFor($type);

        return $this->render($type, $this->counters[$type] ?? 0, $format, $padding, $timezone);
    }

    public function previewFor(string $type, ?int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        $settings = $this->lookupSettings($type) ?? $this->defaultSettings();
        $padding ??= $settings['padding'];
        $format ??= $settings['format'] ?? $this->defaultFormat();

        $representation = $number !== null
            ? str_pad((string) $number, $padding, '0', STR_PAD_LEFT)
            : str_repeat('X', $padding);

        return str_replace('{number}', $representation, $format);
    }

    public function previewCurrentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string
    {
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
        $this->settings[$type] = $this->resolveSettings(
            $this->lookupSettings($type),
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

        $this->registered->push([
            'type' => $type,
            'interval' => $interval,
            'padding' => $padding,
            'format' => $format,
            'resetMonth' => $resetMonth,
            'resetDay' => $resetDay,
            'resetWeekday' => $resetWeekday,
            'resetHour' => $resetHour,
            'undoMode' => $undoMode,
            'timezone' => $timezone,
        ]);
    }

    /**
     * @param  int|array<int>  $numbers
     */
    public function skipFor(string $type, int|array $numbers, ?string $reason): void
    {
        $this->settingsFor($type);

        foreach ((array) $numbers as $number) {
            if ($number <= ($this->counters[$type] ?? 0)) {
                throw RunningNumberAlreadyIssued::forNumber($type, $number, $this->counters[$type] ?? 0);
            }
        }

        foreach ((array) $numbers as $number) {
            $this->skips[$type][$number] ??= false;
            $this->skipped->push(['type' => $type, 'number' => $number, 'reason' => $reason]);
        }
    }

    public function useSkippedFor(string $type, int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        $this->settingsFor($type);

        if (($this->skips[$type][$number] ?? null) !== false) {
            throw RunningNumberNotSkipped::forNumber($type, $number);
        }

        $this->skips[$type][$number] = true;
        $this->usedSkipped->push(['type' => $type, 'number' => $number]);

        return $this->render($type, $number, $format, $padding, $timezone);
    }

    public function undoFor(string $type, int $number, ?string $reason): void
    {
        $settings = $this->settingsFor($type);

        $issued = isset($this->skips[$type][$number])
            ? $this->skips[$type][$number]
            : $number >= 1 && $number <= ($this->counters[$type] ?? 0);

        if (! $issued || isset($this->undoneNumbers[$type][$number])) {
            throw RunningNumberNotUndoable::forNumber($type, $number);
        }

        $this->undoneNumbers[$type][$number] = true;

        if ($settings['undo_mode'] === UndoMode::RECLAIM) {
            $this->reclaimQueue[$type][] = $number;
        }

        $this->undone->push(['type' => $type, 'number' => $number, 'reason' => $reason]);
    }

    public function resetFor(string $type): void
    {
        $this->settingsFor($type);

        $this->counters[$type] = 0;
        unset($this->skips[$type], $this->undoneNumbers[$type], $this->reclaimQueue[$type]);
        $this->resets->push(['type' => $type]);
    }

    /**
     * @return Settings
     */
    private function settingsFor(string $type, bool $autoRegister = false): array
    {
        if ($this->lookupSettings($type) === null && ! $autoRegister) {
            throw RunningNumberTypeNotRegistered::forType($type);
        }

        return $this->settings[$type] ??= $this->lookupSettings($type) ?? $this->resolveSettings(null, true);
    }

    /**
     * @return Settings|null
     */
    private function lookupSettings(string $type): ?array
    {
        return $this->settings[$type] ?? $this->declaredSettings($type);
    }

    // Simplification: only the `{number}` token is substituted. Unlike the real manager,
    // which resolves date tokens (e.g. `{Y}`, `{m}`) and `{period:...}` tokens, the fake
    // leaves any other token in the format string as literal text.
    private function render(string $type, int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        $settings = $this->lookupSettings($type) ?? $this->defaultSettings();
        $padding ??= $settings['padding'];
        $format ??= $settings['format'] ?? $this->defaultFormat();

        return str_replace('{number}', str_pad((string) $number, $padding, '0', STR_PAD_LEFT), $format);
    }

    /**
     * @param  RunningNumberType  $type
     */
    public function assertGenerated(Arrayable|array|string|int|BackedEnum|Model $type, ?int $times = null): void
    {
        $resolved = $this->resolveType($type);
        $count = $this->generated->where('type', $resolved)->count();

        PHPUnit::assertTrue($count > 0, "No running number was generated for [{$resolved}].");

        if ($times !== null) {
            PHPUnit::assertSame($times, $count, "Expected [{$resolved}] to be generated {$times} time(s), generated {$count} time(s).");
        }
    }

    /**
     * @param  RunningNumberType  $type
     */
    public function assertNotGenerated(Arrayable|array|string|int|BackedEnum|Model $type): void
    {
        $resolved = $this->resolveType($type);

        PHPUnit::assertCount(0, $this->generated->where('type', $resolved), "Running number was generated for [{$resolved}].");
    }

    /**
     * @param  RunningNumberType  $type
     * @param  int|array<int>  $numbers
     */
    public function assertSkipped(Arrayable|array|string|int|BackedEnum|Model $type, int|array $numbers): void
    {
        $resolved = $this->resolveType($type);

        foreach ((array) $numbers as $number) {
            PHPUnit::assertTrue(
                $this->skipped->contains(fn ($entry) => $entry['type'] === $resolved && $entry['number'] === $number),
                "Number [{$number}] was not skipped for [{$resolved}].",
            );
        }
    }

    /**
     * @param  RunningNumberType  $type
     */
    public function assertUsedSkipped(Arrayable|array|string|int|BackedEnum|Model $type, int $number): void
    {
        $resolved = $this->resolveType($type);

        PHPUnit::assertTrue(
            $this->usedSkipped->contains(fn ($entry) => $entry['type'] === $resolved && $entry['number'] === $number),
            "Skipped number [{$number}] was not used for [{$resolved}].",
        );
    }

    /**
     * @param  RunningNumberType  $type
     */
    public function assertUndone(Arrayable|array|string|int|BackedEnum|Model $type, int $number): void
    {
        $resolved = $this->resolveType($type);

        PHPUnit::assertTrue(
            $this->undone->contains(fn ($entry) => $entry['type'] === $resolved && $entry['number'] === $number),
            "Number [{$number}] was not undone for [{$resolved}].",
        );
    }

    /**
     * @param  RunningNumberType  $type
     * @param  (Closure(Registration): bool)|null  $callback
     */
    public function assertRegistered(Arrayable|array|string|int|BackedEnum|Model $type, ?Closure $callback = null): void
    {
        $resolved = $this->resolveType($type);
        $entries = $this->registered->where('type', $resolved);

        PHPUnit::assertTrue($entries->isNotEmpty(), "[{$resolved}] was never registered.");

        if ($callback) {
            PHPUnit::assertTrue($entries->contains(fn ($entry) => $callback($entry)), "No matching registration found for [{$resolved}].");
        }
    }

    /**
     * @param  RunningNumberType  $type
     */
    public function assertReset(Arrayable|array|string|int|BackedEnum|Model $type, ?int $times = null): void
    {
        $resolved = $this->resolveType($type);
        $count = $this->resets->where('type', $resolved)->count();

        PHPUnit::assertTrue($count > 0, "[{$resolved}] was never reset.");

        if ($times !== null) {
            PHPUnit::assertSame($times, $count, "Expected [{$resolved}] to be reset {$times} time(s), reset {$count} time(s).");
        }
    }

    /**
     * @param  RunningNumberType  $type
     * @return Collection<int, array{type: string, number: int, result: string}>
     */
    public function generated(Arrayable|array|string|int|BackedEnum|Model $type): Collection
    {
        return $this->generated->where('type', $this->resolveType($type))->values();
    }
}
