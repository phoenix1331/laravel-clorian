<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Phoenix1331\Clorian\Calibration\Calibrator;
use Phoenix1331\Clorian\Calibration\Observations;
use Phoenix1331\Clorian\Commands\AddColumnCommand;
use Phoenix1331\Clorian\Commands\InstallCommand;
use Phoenix1331\Clorian\Commands\PingCommand;
use Phoenix1331\Clorian\Commands\PruneCommand;
use Phoenix1331\Clorian\Commands\RescanCommand;
use Phoenix1331\Clorian\Commands\StatsCommand;
use Phoenix1331\Clorian\Commands\TuneCommand;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Policies\PolicyRegistry;
use Phoenix1331\Clorian\Resolution\TunedThresholds;
use Phoenix1331\Clorian\Support\StatusColumn;
use Phoenix1331\Clorian\Tuning\EloquentObservations;
use Phoenix1331\Clorian\Tuning\EloquentTunedThresholds;

class ClorianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/clorian.php', 'clorian');

        $this->app->singleton(PolicyRegistry::class);
        $this->app->singleton(Clorian::class);
        $this->app->alias(Clorian::class, Clorian::ALIAS);
        // One instance, so tests and demos can script the driver Clorian uses.
        $this->app->singleton(FakeDriver::class);
        $this->app->singleton(StatusColumn::class);
        $this->app->bind(TunedThresholds::class, EloquentTunedThresholds::class);
        $this->app->bind(Observations::class, EloquentObservations::class);
        $this->app->bind(Calibrator::class, static function (Application $app): Calibrator {
            $config = $app->make(Repository::class);
            $target = $config->get('clorian.calibration.target');
            $minSample = $config->get('clorian.calibration.min_sample');

            return new Calibrator(
                is_numeric($target) ? (float) $target : Calibrator::DEFAULT_TARGET,
                is_numeric($minSample) ? (int) $minSample : Calibrator::DEFAULT_MIN_SAMPLE,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/clorian.php' => config_path('clorian.php'),
        ], 'clorian-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'clorian-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AddColumnCommand::class,
                InstallCommand::class,
                PingCommand::class,
                PruneCommand::class,
                RescanCommand::class,
                StatsCommand::class,
                TuneCommand::class,
            ]);
        }
    }
}
