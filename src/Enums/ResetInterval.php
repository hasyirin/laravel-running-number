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
    ): bool {
        if ($this === self::NEVER) {
            return false;
        }

        return $lastResetAt->lt($this->mostRecentBoundary(now($timezone), $month, $day, $weekday, $hour));
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
        $boundary = $now->copy()->setTime($hour, 0, 0);

        return $boundary->gt($now) ? $boundary->subDay() : $boundary;
    }

    private function weeklyBoundary(CarbonInterface $now, int $weekday, int $hour): CarbonInterface
    {
        $boundary = $now->copy()->startOfWeek($weekday)->setTime($hour, 0, 0);

        return $boundary->gt($now) ? $boundary->subWeek() : $boundary;
    }

    private function monthlyBoundary(CarbonInterface $now, int $day, int $hour): CarbonInterface
    {
        $boundary = $now->copy()->setDay(min($day, $now->daysInMonth))->setTime($hour, 0, 0);

        if ($boundary->lte($now)) {
            return $boundary;
        }

        $previousMonth = $now->copy()->subMonthNoOverflow();

        return $previousMonth->setDay(min($day, $previousMonth->daysInMonth))->setTime($hour, 0, 0);
    }

    private function yearlyBoundary(CarbonInterface $now, int $month, int $day, int $hour): CarbonInterface
    {
        $boundary = $now->copy()->setDay(1)->setMonth($month);
        $boundary = $boundary->setDay(min($day, $boundary->daysInMonth))->setTime($hour, 0, 0);

        if ($boundary->lte($now)) {
            return $boundary;
        }

        $lastYear = $now->copy()->subYearNoOverflow()->setDay(1)->setMonth($month);

        return $lastYear->setDay(min($day, $lastYear->daysInMonth))->setTime($hour, 0, 0);
    }

    private function quarterlyBoundary(CarbonInterface $now, int $month, int $day, int $hour): CarbonInterface
    {
        return collect([0, 3, 6, 9])
            ->map(fn (int $offset) => $this->yearlyBoundary($now, $this->normalizeMonth($month + $offset), $day, $hour))
            ->sortByDesc(fn (CarbonInterface $boundary) => $boundary->timestamp)
            ->first();
    }

    private function normalizeMonth(int $month): int
    {
        return (($month - 1) % 12) + 1;
    }
}
