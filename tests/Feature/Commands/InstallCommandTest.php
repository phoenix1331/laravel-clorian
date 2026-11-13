<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Phoenix1331\Clorian\ClorianServiceProvider;

const MODE_CHOICES = [
    'queued' => 'Queued: in a job after the save commits (recommended)',
    'sync' => 'Sync: inline during the save, falling back to defaults on timeout',
];

/*
 * Publishing goes to a temporary app directory, never into Testbench's
 * skeleton under vendor/.
 */
beforeEach(function (): void {
    $this->installDir = sys_get_temp_dir().'/clorian-install-'.bin2hex(random_bytes(6));
    (new Filesystem)->ensureDirectoryExists($this->installDir.'/config');
    (new Filesystem)->ensureDirectoryExists($this->installDir.'/database/migrations');

    app()->useConfigPath($this->installDir.'/config');
    app()->useDatabasePath($this->installDir.'/database');

    // Publish targets are worked out in boot(), so boot again with the new paths.
    (new ClorianServiceProvider(app()))->boot();
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->installDir);
});

/**
 * @return list<string>
 */
function publishedMigrations(string $dir): array
{
    return array_map('basename', glob($dir.'/database/migrations/*.php') ?: []);
}

it('publishes, asks for the mode and migrations, and prints the .env keys', function (): void {
    foreach (['clorian_answers', 'clorian_thresholds', 'clorian_weighings'] as $table) {
        Schema::drop($table);
    }

    $this->artisan('clorian:install')
        ->expectsChoice('How should models be weighed?', 'sync', MODE_CHOICES)
        ->expectsConfirmation('Run the migrations now?', 'yes')
        ->expectsOutputToContain('JEV_API_KEY=')
        ->expectsOutputToContain('JEV_MODEL=jev-latest')
        ->expectsOutputToContain('CLORIAN_DRIVER=jev')
        ->expectsOutputToContain('CLORIAN_MODE=sync')
        ->expectsOutputToContain('CLORIAN_QUEUE=default')
        ->expectsOutputToContain('CLORIAN_STORE_INPUT=subjectless')
        ->expectsOutputToContain('php artisan clorian:ping')
        ->doesntExpectOutputToContain('php artisan migrate')
        ->assertSuccessful();

    expect(file_exists($this->installDir.'/config/clorian.php'))->toBeTrue()
        ->and(publishedMigrations($this->installDir))->toHaveCount(3)
        ->and(Schema::hasTable('clorian_weighings'))->toBeTrue()
        ->and(Schema::hasTable('clorian_answers'))->toBeTrue()
        ->and(Schema::hasTable('clorian_thresholds'))->toBeTrue();
});

it('leaves migrating to later when declined', function (): void {
    $this->artisan('clorian:install')
        ->expectsChoice('How should models be weighed?', 'queued', MODE_CHOICES)
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->expectsOutputToContain('CLORIAN_MODE=queued')
        ->assertSuccessful();

    expect(publishedMigrations($this->installDir))->toHaveCount(3);
});

it('skips the prompts with --no-interaction, choosing queued and not migrating', function (): void {
    $this->artisan('clorian:install', ['--no-interaction' => true])
        ->expectsOutputToContain('CLORIAN_MODE=queued')
        ->expectsOutputToContain('php artisan migrate')
        ->assertSuccessful();

    expect(file_exists($this->installDir.'/config/clorian.php'))->toBeTrue();
});

it('never overwrites a published config', function (): void {
    file_put_contents($this->installDir.'/config/clorian.php', "<?php\n\nreturn ['mine' => true];\n");

    $this->artisan('clorian:install', ['--no-interaction' => true])->assertSuccessful();

    expect((string) file_get_contents($this->installDir.'/config/clorian.php'))->toContain("'mine' => true");
});

it('publishes the migrations once, however often it runs', function (): void {
    $this->artisan('clorian:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Published 3 migrations')
        ->assertSuccessful();

    $this->artisan('clorian:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Migrations already published')
        ->assertSuccessful();

    expect(publishedMigrations($this->installDir))->toHaveCount(3);
});

it('publishes only the migrations an app is missing, in order', function (): void {
    $this->artisan('clorian:install', ['--no-interaction' => true])->assertSuccessful();

    // As if an upgrade added the thresholds migration after the first install.
    $thresholds = glob($this->installDir.'/database/migrations/*_create_clorian_thresholds_table.php') ?: [];
    unlink($thresholds[0]);
    $this->travel(1)->minutes();

    $this->artisan('clorian:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Published 1 new migrations, kept 2')
        ->assertSuccessful();

    $names = publishedMigrations($this->installDir);
    sort($names);

    expect($names)->toHaveCount(3)
        ->and(array_map(static fn (string $name): string => (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name), $names))
        ->toBe(['create_clorian_weighings_table.php', 'create_clorian_answers_table.php', 'create_clorian_thresholds_table.php']);
});

it('gives each migration its own timestamp, in the package order', function (): void {
    $this->artisan('clorian:install', ['--no-interaction' => true])->assertSuccessful();

    $names = publishedMigrations($this->installDir);
    sort($names);

    expect(array_unique(array_map(static fn (string $name): string => substr($name, 0, 17), $names)))->toHaveCount(3)
        ->and($names[0])->toEndWith('_create_clorian_weighings_table.php')
        ->and($names[2])->toEndWith('_create_clorian_thresholds_table.php');
});
