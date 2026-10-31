<?php

namespace Hasyirin\RunningNumber\Enums;

use Carbon\CarbonInterface;

enum ResetInterval: string
{
    case NEVER = 'never';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case YEARLY = 'yearly';

    public function needsReset(
        CarbonInterface $lastResetAt,
        ?int $month = null,
        ?int $day = null,
        ?int $weekday = null,
        ?int $hour = null,
        ?string $timezone = null,
        ?CarbonInterface $now = null,
    ): bool {
        if ($this === self::NEVER) {
            return false;
        }

        return $lastResetAt->lt($this->mostRecentBoundary($this->localNow($now, $timezone), $month, $day, $weekday, $hour));
    }

    public function nextBoundary(
        ?int $month = null,
        ?int $day = null,
        ?int $weekday = null,
        ?int $hour = null,
        ?string $timezone = null,
        ?CarbonInterface $now = null,
    ): ?CarbonInterface {
        if ($this === self::NEVER) {
            return null;
        }

        $now = $this->localNow($now, $timezone);
        $hour ??= 0;

        return match ($this) {
            self::DAILY => $this->nextDailyBoundary($now, $hour),
            self::WEEKLY => $this->nextWeeklyBoundary($now, $weekday ?? CarbonInterface::MONDAY, $hour),
            self::MONTHLY => $this->nextMonthlyBoundary($now, $day ?? 1, $hour),
            self::QUARTERLY => $this->nextQuarterlyBoundary($now, $month ?? 1, $day ?? 1, $hour),
            self::YEARLY => $this->nextYearlyBoundary($now, $month ?? 1, $day ?? 1, $hour),
        };
    }

    private function localNow(?CarbonInterface $now, ?string $timezone): CarbonInterface
    {
        return ($now ?? now())->copy()->setTimezone($timezone ?? date_default_timezone_get());
    }

    private function mostRecentBoundary(CarbonInterface $now, ?int $month, ?int $day, ?int $weekday, ?int $hour): CarbonInterface
    {
        $hour ??= 0;

        return match ($this) {
            // needsReset() returns early for NEVER, so this branch is unreachable at
            // runtime; it exists only to keep this match exhaustive for static analysis.
            self::NEVER => throw new \LogicException('ResetInterval::NEVER never needs a boundary.'),
            self::DAILY => $this->dailyBoundary($now, $hour),
            self::WEEKLY => $this->weeklyBoundary($now, $weekday ?? CarbonInterface::MONDAY, $hour),
            self::MONTHLY => $this->monthlyBoundary($now, $day ?? 1, $hour),
            self::QUARTERLY => $this->quarterlyBoundary($now, $month ?? 1, $day ?? 1, $hour),
            self::YEARLY => $this->yearlyBoundary($now, $month ?? 1, $day ?? 1, $hour),
        };
    }

    private function dailyBoundary(CarbonInterface $now, int $hour): CarbonInterface
    {
        $boundary = $this->atHour($now, $hour);

        return $boundary->gt($now) ? $this->atHour($this->midnight($now)->subDay(), $hour) : $boundary;
    }

    private function weeklyBoundary(CarbonInterface $now, int $weekday, int $hour): CarbonInterface
    {
        $weekStart = $this->midnight($now)->startOfWeek($weekday);
        $boundary = $this->atHour($weekStart, $hour);

        return $boundary->gt($now) ? $this->atHour($weekStart->subWeek(), $hour) : $boundary;
    }

    private function monthlyBoundary(CarbonInterface $now, int $day, int $hour): CarbonInterface
    {
        $boundary = $this->atHour($this->midnight($now)->setDay(min($day, $now->daysInMonth)), $hour);

        if ($boundary->lte($now)) {
            return $boundary;
        }

        $previousMonth = $this->midnight($now)->subMonthNoOverflow();

        return $this->atHour($previousMonth->setDay(min($day, $previousMonth->daysInMonth)), $hour);
    }

    private function yearlyBoundary(CarbonInterface $now, int $month, int $day, int $hour): CarbonInterface
    {
        $boundary = $this->midnight($now)->setDay(1)->setMonth($month);
        $boundary = $this->atHour($boundary->setDay(min($day, $boundary->daysInMonth)), $hour);

        if ($boundary->lte($now)) {
            return $boundary;
        }

        $lastYear = $this->midnight($now)->subYearNoOverflow()->setDay(1)->setMonth($month);

        return $this->atHour($lastYear->setDay(min($day, $lastYear->daysInMonth)), $hour);
    }

    private function quarterlyBoundary(CarbonInterface $now, int $month, int $day, int $hour): CarbonInterface
    {
        return collect([0, 3, 6, 9])
            ->map(fn (int $offset) => $this->yearlyBoundary($now, $this->normalizeMonth($month + $offset), $day, $hour))
            ->sortByDesc(fn (CarbonInterface $boundary) => $boundary->timestamp)
            ->first();
    }

    private function nextDailyBoundary(CarbonInterface $now, int $hour): CarbonInterface
    {
        $boundary = $this->atHour($now, $hour);

        return $boundary->gt($now) ? $boundary : $this->atHour($this->midnight($now)->addDay(), $hour);
    }

    private function nextWeeklyBoundary(CarbonInterface $now, int $weekday, int $hour): CarbonInterface
    {
        $weekStart = $this->midnight($now)->startOfWeek($weekday);
        $boundary = $this->atHour($weekStart, $hour);

        return $boundary->gt($now) ? $boundary : $this->atHour($weekStart->addWeek(), $hour);
    }

    private function nextMonthlyBoundary(CarbonInterface $now, int $day, int $hour): CarbonInterface
    {
        $boundary = $this->atHour($this->midnight($now)->setDay(min($day, $now->daysInMonth)), $hour);

        if ($boundary->gt($now)) {
            return $boundary;
        }

        $nextMonth = $this->midnight($now)->startOfMonth()->addMonth();

        return $this->atHour($nextMonth->setDay(min($day, $nextMonth->daysInMonth)), $hour);
    }

    private function nextYearlyBoundary(CarbonInterface $now, int $month, int $day, int $hour): CarbonInterface
    {
        $boundary = $this->midnight($now)->setDay(1)->setMonth($month);
        $boundary = $this->atHour($boundary->setDay(min($day, $boundary->daysInMonth)), $hour);

        if ($boundary->gt($now)) {
            return $boundary;
        }

        $nextYear = $this->midnight($now)->setDay(1)->addYear()->setMonth($month);

        return $this->atHour($nextYear->setDay(min($day, $nextYear->daysInMonth)), $hour);
    }

    private function nextQuarterlyBoundary(CarbonInterface $now, int $month, int $day, int $hour): CarbonInterface
    {
        return collect([0, 3, 6, 9])
            ->map(fn (int $offset) => $this->nextYearlyBoundary($now, $this->normalizeMonth($month + $offset), $day, $hour))
            ->sortBy(fn (CarbonInterface $boundary) => $boundary->timestamp)
            ->first();
    }

    // Built from the date, not with setTime(): setTime() keeps the current UTC offset, so an
    // hour that happens twice (DST end) would give two different boundaries on one day.
    private function atHour(CarbonInterface $date, int $hour): CarbonInterface
    {
        return $date::create($date->year, $date->month, $date->day, $hour, 0, 0, $date->getTimezone());
    }

    // Date arithmetic starts from midnight: a time of day such as 23:30 can fall into an hour that the
    // target date skips (America/Nuuk skips Saturday 23:00-24:00), and PHP then moves the date forward.
    private function midnight(CarbonInterface $now): CarbonInterface
    {
        return $now->copy()->startOfDay();
    }

    private function normalizeMonth(int $month): int
    {
        return (($month - 1) % 12) + 1;
    }
}
