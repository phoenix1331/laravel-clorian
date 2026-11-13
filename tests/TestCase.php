<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Phoenix1331\Clorian\ClorianServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any HTTP request a test has not faked is a bug.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ClorianServiceProvider::class,
        ];
    }

    /**
     * Models are not weighed on save unless a test turns it on, so creating
     * a fixture never calls a driver by accident.
     */
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('clorian.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
