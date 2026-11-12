<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian;

use Illuminate\Support\ServiceProvider;

class ClorianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/clorian.php', 'clorian');
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
