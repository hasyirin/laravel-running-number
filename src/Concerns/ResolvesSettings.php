<?php

namespace Hasyirin\RunningNumber\Concerns;

use DateTimeZone;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Exceptions\InvalidResetAnchor;
use InvalidArgumentException;

/**
 * @phpstan-type Settings array{reset_interval: ResetInterval, reset_month: int|null, reset_day: int|null, reset_weekday: int|null, reset_hour: int|null, timezone: string|null, undo_mode: UndoMode, padding: int, format: string|null}
 * @phpstan-type DeclaredSettings array{interval?: ResetInterval|null, padding?: int|null, format?: string|null, resetMonth?: int|null, resetDay?: int|null, resetWeekday?: int|null, resetHour?: int|null, undoMode?: UndoMode|null, timezone?: string|null}
 */
trait ResolvesSettings
{
    /**
     * @return Settings
     */
    protected function defaultSettings(): array
    {
        /** @var Settings $settings */
        $settings = [
            'reset_interval' => config('running-number.default_interval'),
            'reset_month' => null,
            'reset_day' => null,
            'reset_weekday' => null,
            'reset_hour' => null,
            'timezone' => config('running-number.default_timezone'),
            'undo_mode' => config('running-number.default_undo_mode'),
            'padding' => config('running-number.default_padding'),
            'format' => null,
        ];

        return $settings;
    }

    protected function defaultFormat(): string
    {
        /** @var string $format */
        $format = config('running-number.default_format');

        return $format;
    }

    /**
     * @return Settings|null
     */
    protected function declaredSettings(string $type): ?array
    {
        /** @var array<string, DeclaredSettings|null> $declared */
        $declared = config('running-number.types', []);

        return array_key_exists($type, $declared) ? $this->resolveSettings(null, true, ...($declared[$type] ?? [])) : null;
    }

    /**
     * @param  Settings|null  $current
     * @return Settings
     */
    protected function resolveSettings(
        ?array $current,
        bool $replace,
        ?ResetInterval $interval = null,
        ?int $padding = null,
        ?string $format = null,
        ?int $resetMonth = null,
        ?int $resetDay = null,
        ?int $resetWeekday = null,
        ?int $resetHour = null,
        ?UndoMode $undoMode = null,
        ?string $timezone = null,
    ): array {
        $settings = $current === null || $replace ? $this->defaultSettings() : $current;

        if ($interval !== null && $interval !== $settings['reset_interval']) {
            $settings = [...$settings, 'reset_month' => null, 'reset_day' => null, 'reset_weekday' => null, 'reset_hour' => null];
        }

        $settings = [...$settings, ...array_filter([
            'reset_interval' => $interval,
            'reset_month' => $resetMonth,
            'reset_day' => $resetDay,
            'reset_weekday' => $resetWeekday,
            'reset_hour' => $resetHour,
            'timezone' => $timezone,
            'undo_mode' => $undoMode,
            'padding' => $padding,
            'format' => $format,
        ], fn (mixed $value) => $value !== null)];

        $this->validateAnchors(
            $settings['reset_interval'],
            $settings['reset_month'],
            $settings['reset_day'],
            $settings['reset_weekday'],
            $settings['reset_hour'],
        );

        if ($settings['padding'] < 0) {
            throw new InvalidArgumentException("[padding] must be 0 or greater, got {$settings['padding']}.");
        }

        if ($settings['timezone'] !== null) {
            new DateTimeZone($settings['timezone']);
        }

        return $settings;
    }

    private function validateAnchors(ResetInterval $interval, ?int $month, ?int $day, ?int $weekday, ?int $hour): void
    {
        $allowed = match ($interval) {
            ResetInterval::NEVER => [],
            ResetInterval::DAILY => ['hour'],
            ResetInterval::WEEKLY => ['weekday', 'hour'],
            ResetInterval::MONTHLY => ['day', 'hour'],
            ResetInterval::QUARTERLY, ResetInterval::YEARLY => ['month', 'day', 'hour'],
        };

        $supplied = array_filter(
            ['month' => $month, 'day' => $day, 'weekday' => $weekday, 'hour' => $hour],
            fn ($value) => $value !== null,
        );

        foreach ($supplied as $field => $value) {
            if (! in_array($field, $allowed, true)) {
                throw InvalidResetAnchor::fieldNotAllowed('reset'.ucfirst($field), $interval);
            }
        }

        $ranges = ['month' => [1, 12], 'day' => [1, 31], 'weekday' => [0, 6], 'hour' => [0, 23]];

        foreach ($supplied as $field => $value) {
            [$min, $max] = $ranges[$field];

            if ($value < $min || $value > $max) {
                throw InvalidResetAnchor::outOfRange('reset'.ucfirst($field), $value, $min, $max);
            }
        }
    }
}
