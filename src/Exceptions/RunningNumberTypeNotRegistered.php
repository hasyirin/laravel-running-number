<?php

namespace Hasyirin\RunningNumber\Exceptions;

use RuntimeException;

class RunningNumberTypeNotRegistered extends RuntimeException
{
    public static function forType(string $type): self
    {
        return new self(
            "Running number type [{$type}] is not registered. Register it via ".
            "RunningNumber::for('{$type}')->register(...), configure it in ".
            "config('running-number.types'), or enable auto_register."
        );
    }
}
