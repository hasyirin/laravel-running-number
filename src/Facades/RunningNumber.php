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
 * @method static string generate(Arrayable|array|string|int|BackedEnum|Model $type)
 * @method static PendingRunningNumber for(Arrayable|array|string|int|BackedEnum|Model $type)
 * @method static void assertGenerated(Arrayable|array|string|int|BackedEnum|Model $type, ?int $times = null)
 * @method static void assertNotGenerated(Arrayable|array|string|int|BackedEnum|Model $type)
 * @method static void assertSkipped(Arrayable|array|string|int|BackedEnum|Model $type, int|array $numbers)
 * @method static void assertUsedSkipped(Arrayable|array|string|int|BackedEnum|Model $type, int $number)
 * @method static void assertUndone(Arrayable|array|string|int|BackedEnum|Model $type, int $number)
 * @method static void assertRegistered(Arrayable|array|string|int|BackedEnum|Model $type, ?Closure $callback = null)
 * @method static void assertReset(Arrayable|array|string|int|BackedEnum|Model $type, ?int $times = null)
 * @method static Collection generated(Arrayable|array|string|int|BackedEnum|Model $type)
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
