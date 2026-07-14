<?php

namespace Hasyirin\RunningNumber;

use Hasyirin\RunningNumber\Commands\RunningNumberCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RunningNumberServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-running-number')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_running_number_table')
            ->hasCommand(RunningNumberCommand::class);
    }
}
