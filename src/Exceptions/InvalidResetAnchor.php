<?php

namespace Hasyirin\RunningNumber\Exceptions;

use Hasyirin\RunningNumber\Enums\ResetInterval;
use RuntimeException;

class InvalidResetAnchor extends RuntimeException
{
    public static function fieldNotAllowed(string $field, ResetInterval $interval): self
    {
        return new self("[{$field}] is not a valid anchor for the [{$interval->value}] reset interval.");
    }

    public static function outOfRange(string $field, int $value, int $min, int $max): self
    {
        return new self("[{$field}] must be between {$min} and {$max}, got {$value}.");
    }
}
