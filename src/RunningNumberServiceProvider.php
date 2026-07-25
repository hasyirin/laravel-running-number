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
                'create_running_numbers_table',
                'create_running_number_skips_table',
                'create_running_number_undos_table',
            ])
            ->runsMigrations()
            ->hasCommand(SyncRunningNumbersCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(RunningNumberContract::class, RunningNumberManager::class);
    }
}
