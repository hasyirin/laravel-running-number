<?php

namespace Hasyirin\RunningNumber;

use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;

class PendingRunningNumber
{
    private ?string $format = null;

    private ?int $padding = null;

    private ?string $timezone = null;

    public function __construct(
        private readonly RunningNumberContract $manager,
        private readonly string $type,
    ) {}

    public function format(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function padding(int $padding): static
    {
        $this->padding = $padding;

        return $this;
    }

    public function timezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function generate(): string
    {
        return $this->manager->generateFor($this->type, $this->format, $this->padding, $this->timezone);
    }

    public function current(): string
    {
        return $this->manager->currentFor($this->type, $this->format, $this->padding, $this->timezone);
    }

    public function preview(?int $number = null): string
    {
        return $this->manager->previewFor($this->type, $number, $this->format, $this->padding, $this->timezone);
    }

    public function previewCurrent(): string
    {
        return $this->manager->previewCurrentFor($this->type, $this->format, $this->padding, $this->timezone);
    }

    public function register(
        ?ResetInterval $interval = null,
        ?int $padding = null,
        ?string $format = null,
        ?int $resetMonth = null,
        ?int $resetDay = null,
        ?int $resetWeekday = null,
        ?int $resetHour = null,
        ?UndoMode $undoMode = null,
        ?string $timezone = null,
    ): void {
        $this->manager->registerFor(
            $this->type, $interval, $padding, $format, $resetMonth, $resetDay,
            $resetWeekday, $resetHour, $undoMode, $timezone,
        );
    }

    public function skip(int|array $numbers, ?string $reason = null): void
    {
        $this->manager->skipFor($this->type, $numbers, $reason);
    }

    public function useSkipped(int $number): string
    {
        return $this->manager->useSkippedFor($this->type, $number, $this->format, $this->padding, $this->timezone);
    }

    public function undo(int $number, ?string $reason = null): void
    {
        $this->manager->undoFor($this->type, $number, $reason);
    }

    public function reset(): void
    {
        $this->manager->resetFor($this->type);
    }
}
