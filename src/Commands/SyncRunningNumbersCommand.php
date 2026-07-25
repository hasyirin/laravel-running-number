<?php

namespace Hasyirin\RunningNumber\Commands;

use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Illuminate\Console\Command;

class SyncRunningNumbersCommand extends Command
{
    public $signature = 'running-number:sync';

    public $description = 'Upsert config(\'running-number.types\') into the database';

    public function handle(RunningNumberContract $manager): int
    {
        $types = config('running-number.types', []);

        foreach ($types as $type => $settings) {
            $manager->registerFor(
                $type,
                $settings['interval'] ?? null,
                $settings['padding'] ?? null,
                $settings['format'] ?? null,
                $settings['resetMonth'] ?? null,
                $settings['resetDay'] ?? null,
                $settings['resetWeekday'] ?? null,
                $settings['resetHour'] ?? null,
                $settings['undoMode'] ?? null,
                $settings['timezone'] ?? null,
            );

            $this->comment("Synced [{$type}]");
        }

        $this->comment('All done');

        return self::SUCCESS;
    }
}
