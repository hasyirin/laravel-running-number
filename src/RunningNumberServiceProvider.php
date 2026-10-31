<?php

namespace Hasyirin\RunningNumber;

use Hasyirin\RunningNumber\Commands\SyncRunningNumbersCommand;
use Hasyirin\RunningNumber\Contracts\RunningNumber as RunningNumberContract;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RunningNumberServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-running-number')
            ->hasConfigFile()
            ->hasMigrations([
                '2026_07_15_000001_create_running_numbers_table',
                '2026_07_15_000002_create_running_number_skips_table',
                '2026_07_15_000003_create_running_number_undos_table',
            ])
            ->hasCommand(SyncRunningNumbersCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(RunningNumberContract::class, RunningNumberManager::class);
    }
}
