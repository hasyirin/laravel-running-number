<?php

namespace Hasyirin\RunningNumber\Concerns;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

trait ResolvesType
{
    protected function resolveType(Arrayable|array|string|int|BackedEnum|Model $type): string
    {
        // Model implements Arrayable, so it must be checked first to prevent collect()
        // from exploding it into its raw attributes instead of treating it as a single type part.
        if ($type instanceof Model) {
            return $this->resolveTypePart($type);
        }

        if (is_array($type) || $type instanceof Arrayable) {
            return collect($type)
                ->map(fn (mixed $part): string => match (true) {
                    is_string($part) => $this->resolveTypePart($part),
                    is_int($part) => $this->resolveTypePart($part),
                    $part instanceof BackedEnum => $this->resolveTypePart($part),
                    $part instanceof Model => $this->resolveTypePart($part),
                    default => throw new UnexpectedValueException(sprintf(
                        "Collection should only include [string, int, %s, %s] items, but '%s' found.",
                        BackedEnum::class,
                        Model::class,
                        get_debug_type($part),
                    )),
                })
                ->implode(':');
        }

        return $this->resolveTypePart($type);
    }

    protected function resolveTypePart(string|int|BackedEnum|Model $part): string
    {
        return match (true) {
            $part instanceof BackedEnum => (string) $part->value,
            $part instanceof Model => $part->getMorphClass(),
            is_string($part) && is_subclass_of($part, Model::class) => (new $part)->getMorphClass(),
            default => (string) $part,
        };
    }
}
