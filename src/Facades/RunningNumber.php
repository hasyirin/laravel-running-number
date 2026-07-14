<?php

namespace Hasyirin\RunningNumber\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Hasyirin\RunningNumber\RunningNumber
 */
class RunningNumber extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hasyirin\RunningNumber\RunningNumber::class;
    }
}
