<?php

namespace Hasyirin\RunningNumber\Exceptions;

use RuntimeException;

class RunningNumberAlreadyIssued extends RuntimeException
{
    public static function forNumber(string $type, int $number, int $current): self
    {
        return new self(
            "Cannot skip number [{$number}] for type [{$type}]: it is not greater than ".
            "the current number [{$current}]. skip() only applies to numbers not yet reached."
        );
    }
}
