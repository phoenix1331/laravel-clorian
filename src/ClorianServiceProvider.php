<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian;

use Illuminate\Support\ServiceProvider;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Policies\PolicyRegistry;
use Phoenix1331\Clorian\Resolution\TunedThresholds;
use Phoenix1331\Clorian\Tuning\EloquentTunedThresholds;

class ClorianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/clorian.php', 'clorian');

        $this->app->singleton(PolicyRegistry::class);
        $this->app->singleton(Clorian::class);
        // One instance, so tests and demos can script the driver Clorian uses.
        $this->app->singleton(FakeDriver::class);
        $this->app->bind(TunedThresholds::class, EloquentTunedThresholds::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/clorian.php' => config_path('clorian.php'),
        ], 'clorian-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'clorian-migrations');
    }
}
