<?php

namespace Hasyirin\RunningNumber\Commands;

use Hasyirin\RunningNumber\Concerns\ResolvesSettings;
use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Hasyirin\RunningNumber\RunningNumberManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-import-type DeclaredSettings from ResolvesSettings
 */
class SyncRunningNumbersCommand extends Command
{
    private const SETTINGS = ['interval', 'padding', 'format', 'resetMonth', 'resetDay', 'resetWeekday', 'resetHour', 'undoMode', 'timezone'];

    public $signature = 'running-number:sync';

    public $description = 'Upsert config(\'running-number.types\') into the database';

    public function handle(RunningNumberContract $manager): int
    {
        /** @var array<string, mixed> $types */
        $types = config('running-number.types', []);

        foreach ($types as $type => $settings) {
            if (! is_array($settings ?? [])) {
                $this->error(sprintf('Type [%s] must map to an array of settings, got %s. Nothing was synced.', $type, get_debug_type($settings)));

                return self::FAILURE;
            }

            $unknown = array_diff(array_keys($settings ?? []), self::SETTINGS);

            if ($unknown !== []) {
                $this->error(sprintf(
                    'Unknown setting [%s] for type [%s]. Allowed settings: %s. Nothing was synced.',
                    implode(', ', $unknown),
                    $type,
                    implode(', ', self::SETTINGS),
                ));

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($types, $manager) {
            /** @var array<string, DeclaredSettings|null> $types */
            foreach ($types as $type => $settings) {
                $manager->registerFor($type, ...($settings ?? []), replace: true);
            }
        }, attempts: RunningNumberManager::TRANSACTION_ATTEMPTS);

        foreach (array_keys($types) as $type) {
            $this->comment("Synced [{$type}]");
        }

        $this->comment('All done');

        return self::SUCCESS;
    }
}
