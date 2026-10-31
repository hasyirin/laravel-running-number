<?php

namespace Hasyirin\RunningNumber\Tests;

use Hasyirin\RunningNumber\RunningNumberServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Hasyirin\\RunningNumber\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            RunningNumberServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        $connection = env('RUNNING_NUMBER_TEST_CONNECTION', 'testing');

        config()->set('database.default', $connection);

        // RefreshDatabase drops every table, so never run against the database that DB_DATABASE or DB_URL names.
        if ($connection !== 'testing') {
            config()->set("database.connections.{$connection}.url", null);
            config()->set("database.connections.{$connection}.database", 'running_number_testing');
        }
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
