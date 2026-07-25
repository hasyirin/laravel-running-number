<?php

namespace Hasyirin\RunningNumber\Database\Factories;

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

class RunningNumberFactory extends Factory
{
    protected $model = RunningNumber::class;

    public function definition(): array
    {
        return [
            'type' => $this->faker->unique()->word(),
            'reset_interval' => ResetInterval::NEVER,
            'undo_mode' => UndoMode::RECLAIM,
            'padding' => 5,
            'number' => 0,
            'reset_cycle' => 1,
            'last_reset_at' => now(),
        ];
    }
}
