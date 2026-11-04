<?php

namespace Hasyirin\RunningNumber\Facades;

use BackedEnum;
use Closure;
use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Hasyirin\RunningNumber\PendingRunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;
use Hasyirin\RunningNumber\Testing\RunningNumberFake;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @phpstan-import-type Registration from RunningNumberFake
 *
 * @method static string generate(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type)
 * @method static PendingRunningNumber for(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type)
 * @method static void assertGenerated(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type, ?int $times = null)
 * @method static void assertNotGenerated(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type)
 * @method static void assertSkipped(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type, int|array<int> $numbers)
 * @method static void assertUsedSkipped(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type, int $number)
 * @method static void assertUndone(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type, int $number)
 * @method static void assertRegistered(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type, (Closure(Registration): bool)|null $callback = null)
 * @method static void assertReset(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type, ?int $times = null)
 * @method static Collection<int, array{type: string, number: int, result: string}> generated(Arrayable<array-key, mixed>|array<mixed>|string|int|BackedEnum|Model $type)
 *
 * @see RunningNumberManager
 * @see RunningNumberFake
 */
class RunningNumber extends Facade
{
    public static function fake(): RunningNumberFake
    {
        $fake = new RunningNumberFake;

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return RunningNumberContract::class;
    }
}
