<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Phoenix1331\Clorian\Clorian;
use Phoenix1331\Clorian\ClorianServiceProvider;

it('merges the package config', function (): void {
    // TestCase turns weighing on save off; the shipped default is on.
    expect((require __DIR__.'/../../config/clorian.php')['enabled'])->toBeTrue()
        ->and(config('clorian.enabled'))->toBeFalse()
        ->and(config('clorian.driver'))->toBe('jev')
        ->and(config('clorian.mode'))->toBe('queued')
        ->and(config('clorian.queue'))->toBe('default')
        ->and(config('clorian.sync_timeout'))->toBe(2.0)
        ->and(config('clorian.store_input'))->toBe('subjectless')
        ->and(config('clorian.default_thresholds'))->toBe(['accept' => 0.90, 'review' => 0.60])
        ->and(config('clorian.prune'))->toBe(['weighings_after_days' => 90, 'inputs_after_days' => 30]);
});

it('registers the manager as a singleton under the facade alias', function (): void {
    expect(app('clorian'))->toBe(app(Clorian::class));
});

it('reads the jev driver settings shared with laravel-jev-moderation', function (): void {
    expect(config('clorian.drivers.jev.model'))->toBe('jev-latest')
        ->and(config('clorian.drivers.jev.base_url'))->toBe('https://api.typesafe.ai/v1')
        ->and(config('clorian.drivers.jev.timeout'))->toBe(5)
        ->and(config('clorian.drivers'))->toHaveKey('fake');
});

it('publishes the config file under its tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(ClorianServiceProvider::class, 'clorian-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/clorian.php');
});

it('registers the console commands', function (): void {
    expect(array_keys(Artisan::all()))->toContain('clorian:install', 'clorian:ping', 'clorian:prune', 'clorian:rescan', 'clorian:stats', 'clorian:tune');
});
