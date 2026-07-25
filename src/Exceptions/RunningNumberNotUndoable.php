<?php

namespace Hasyirin\RunningNumber\Exceptions;

use RuntimeException;

class RunningNumberNotUndoable extends RuntimeException
{
    public static function forNumber(string $type, int $number): self
    {
        return new self(
            "Number [{$number}] for type [{$type}] cannot be undone: it was not issued, ".
            'was skipped rather than issued, or has already been undone in the current cycle.'
        );
    }
}
