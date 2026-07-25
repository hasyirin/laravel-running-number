<?php

namespace Hasyirin\RunningNumber\Exceptions;

use RuntimeException;

class RunningNumberNotSkipped extends RuntimeException
{
    public static function forNumber(string $type, int $number): self
    {
        return new self(
            "Number [{$number}] for type [{$type}] has not been skipped, or was already ".
            'used, in the current reset cycle.'
        );
    }
}
