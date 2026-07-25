<?php

namespace Hasyirin\RunningNumber\Testing;

use BackedEnum;
use Closure;
use Hasyirin\RunningNumber\Concerns\ResolvesType;
use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\PendingRunningNumber;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

class RunningNumberFake implements RunningNumberContract
{
    use ResolvesType;

    private array $counters = [];

    private array $config = [];

    private array $skips = [];

    private array $reclaimQueue = [];

    private Collection $generated;

    private Collection $registered;

    private Collection $skipped;

    private Collection $usedSkipped;

    private Collection $undone;

    private Collection $resets;

    public function __construct()
    {
        $this->generated = collect();
        $this->registered = collect();
        $this->skipped = collect();
        $this->usedSkipped = collect();
        $this->undone = collect();
        $this->resets = collect();
    }

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
        if (! empty($this->reclaimQueue[$type] ?? [])) {
            sort($this->reclaimQueue[$type]);
            $number = array_shift($this->reclaimQueue[$type]);
        } else {
            $this->counters[$type] ??= 0;

            do {
                $this->counters[$type]++;
            } while (in_array($this->counters[$type], $this->skips[$type] ?? [], true));

            $number = $this->counters[$type];
        }

        $result = $this->render($type, $number, $format, $padding, $timezone);

        $this->generated->push(['type' => $type, 'number' => $number, 'result' => $result]);

        return $result;
    }

    public function currentFor(string $type, ?string $format, ?int $padding, ?string $timezone): string
    {
        return $this->render($type, $this->counters[$type] ?? 0, $format, $padding, $timezone);
    }

    public function previewFor(string $type, ?int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        $padding ??= $this->config[$type]['padding'] ?? config('running-number.default_padding');
        $format ??= $this->config[$type]['format'] ?? config('running-number.default_format');

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
        $this->config[$type] = [
            'padding' => $padding ?? config('running-number.default_padding'),
            'format' => $format ?? config('running-number.default_format'),
        ];

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

    public function skipFor(string $type, int|array $numbers, ?string $reason): void
    {
        foreach ((array) $numbers as $number) {
            $this->skips[$type][] = $number;
            $this->skipped->push(['type' => $type, 'number' => $number, 'reason' => $reason]);
        }
    }

    public function useSkippedFor(string $type, int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        $this->usedSkipped->push(['type' => $type, 'number' => $number]);

        return $this->render($type, $number, $format, $padding, $timezone);
    }

    // Simplification: the fake always treats undo as reclaim-mode (pushes the number onto
    // the reclaim queue so it resurfaces on the next generate()), regardless of the
    // `undoMode` the type was registered with. It does not simulate UndoMode::RECORD,
    // where the real manager would log the undo without making the number reusable.
    public function undoFor(string $type, int $number, ?string $reason): void
    {
        $this->reclaimQueue[$type][] = $number;
        $this->undone->push(['type' => $type, 'number' => $number, 'reason' => $reason]);
    }

    public function resetFor(string $type): void
    {
        $this->counters[$type] = 0;
        unset($this->skips[$type]);
        unset($this->reclaimQueue[$type]);
        $this->resets->push(['type' => $type]);
    }

    // Simplification: only the `{number}` token is substituted. Unlike the real manager,
    // which resolves date tokens (e.g. `{Y}`, `{m}`) via now($timezone)->format(...), the
    // fake leaves any other token in the format string as literal text.
    private function render(string $type, int $number, ?string $format, ?int $padding, ?string $timezone): string
    {
        $padding ??= $this->config[$type]['padding'] ?? config('running-number.default_padding');
        $format ??= $this->config[$type]['format'] ?? config('running-number.default_format');

        return str_replace('{number}', str_pad((string) $number, $padding, '0', STR_PAD_LEFT), $format);
    }

    public function assertGenerated(Arrayable|array|string|int|BackedEnum|Model $type, ?int $times = null): void
    {
        $resolved = $this->resolveType($type);
        $count = $this->generated->where('type', $resolved)->count();

        PHPUnit::assertTrue($count > 0, "No running number was generated for [{$resolved}].");

        if ($times !== null) {
            PHPUnit::assertSame($times, $count, "Expected [{$resolved}] to be generated {$times} time(s), generated {$count} time(s).");
        }
    }

    public function assertNotGenerated(Arrayable|array|string|int|BackedEnum|Model $type): void
    {
        $resolved = $this->resolveType($type);

        PHPUnit::assertCount(0, $this->generated->where('type', $resolved), "Running number was generated for [{$resolved}].");
    }

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

    public function assertUsedSkipped(Arrayable|array|string|int|BackedEnum|Model $type, int $number): void
    {
        $resolved = $this->resolveType($type);

        PHPUnit::assertTrue(
            $this->usedSkipped->contains(fn ($entry) => $entry['type'] === $resolved && $entry['number'] === $number),
            "Skipped number [{$number}] was not used for [{$resolved}].",
        );
    }

    public function assertUndone(Arrayable|array|string|int|BackedEnum|Model $type, int $number): void
    {
        $resolved = $this->resolveType($type);

        PHPUnit::assertTrue(
            $this->undone->contains(fn ($entry) => $entry['type'] === $resolved && $entry['number'] === $number),
            "Number [{$number}] was not undone for [{$resolved}].",
        );
    }

    public function assertRegistered(Arrayable|array|string|int|BackedEnum|Model $type, ?Closure $callback = null): void
    {
        $resolved = $this->resolveType($type);
        $entries = $this->registered->where('type', $resolved);

        PHPUnit::assertTrue($entries->isNotEmpty(), "[{$resolved}] was never registered.");

        if ($callback) {
            PHPUnit::assertTrue($entries->contains(fn ($entry) => $callback($entry)), "No matching registration found for [{$resolved}].");
        }
    }

    public function assertReset(Arrayable|array|string|int|BackedEnum|Model $type, ?int $times = null): void
    {
        $resolved = $this->resolveType($type);
        $count = $this->resets->where('type', $resolved)->count();

        PHPUnit::assertTrue($count > 0, "[{$resolved}] was never reset.");

        if ($times !== null) {
            PHPUnit::assertSame($times, $count, "Expected [{$resolved}] to be reset {$times} time(s), reset {$count} time(s).");
        }
    }

    public function generated(Arrayable|array|string|int|BackedEnum|Model $type): Collection
    {
        return $this->generated->where('type', $this->resolveType($type))->values();
    }
}
