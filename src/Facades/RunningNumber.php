<?php

namespace Hasyirin\RunningNumber\Facades;

use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Hasyirin\RunningNumber\RunningNumberManager;
use Hasyirin\RunningNumber\Testing\RunningNumberFake;
use Illuminate\Support\Facades\Facade;

/**
 * @see RunningNumberManager
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
