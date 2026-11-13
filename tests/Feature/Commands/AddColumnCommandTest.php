<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Phoenix1331\Clorian\Commands\AddColumnCommand;

beforeEach(function (): void {
    $this->migrationsDir = sys_get_temp_dir().'/clorian-add-column-'.bin2hex(random_bytes(6));
    app()->useDatabasePath($this->migrationsDir);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->migrationsDir);
});

/**
 * @return list<string>
 */
function generatedMigrations(string $dir): array
{
    return glob($dir.'/migrations/*.php') ?: [];
}

it('writes a migration for an indexed status column', function (): void {
    $this->artisan('clorian:add-column', ['table' => 'tickets'])
        ->expectsOutputToContain('_add_clorian_status_to_tickets_table.php')
        ->expectsOutputToContain('php artisan migrate')
        ->expectsOutputToContain('CLORIAN_STATUS_COLUMN=true')
        ->assertSuccessful();

    $files = generatedMigrations($this->migrationsDir);

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_add_clorian_status_to_tickets_table\.php$/')
        ->and((string) file_get_contents($files[0]))->toBe(AddColumnCommand::migration('tickets', 'clorian_status'))
        ->toContain("Schema::table('tickets'")
        ->toContain("\$table->string('clorian_status', 20)->nullable()->index();")
        ->toContain("\$table->dropIndex(['clorian_status']);")
        ->toContain('declare(strict_types=1);');
});

it('writes a migration that runs and rolls back', function (): void {
    $this->artisan('clorian:add-column', ['table' => 'tickets'])->assertSuccessful();

    $migration = require generatedMigrations($this->migrationsDir)[0];

    expect($migration)->toBeInstanceOf(Migration::class);

    // A scratch SQLite connection: ALTER TABLE on MySQL would commit the test's transaction.
    $default = config('database.default');
    config()->set('database.connections.scratch', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('database.default', 'scratch');

    try {
        Schema::create('tickets', fn ($table) => $table->id());

        $migration->up();

        expect(Schema::hasColumn('tickets', 'clorian_status'))->toBeTrue()
            ->and(Schema::hasIndex('tickets', ['clorian_status']))->toBeTrue();

        $migration->down();

        expect(Schema::hasColumn('tickets', 'clorian_status'))->toBeFalse();
    } finally {
        config()->set('database.default', $default);
        DB::purge('scratch');
    }
});

it('uses the configured column name', function (): void {
    config()->set('clorian.status_column.name', 'triage_state');

    $this->artisan('clorian:add-column', ['table' => 'tickets'])->assertSuccessful();

    expect(basename(generatedMigrations($this->migrationsDir)[0]))->toEndWith('_add_triage_state_to_tickets_table.php');
});

it('refuses a table that already has the column', function (): void {
    $this->artisan('clorian:add-column', ['table' => 'status_tickets'])
        ->expectsOutputToContain('[status_tickets] already has a clorian_status column.')
        ->assertFailed();

    expect(generatedMigrations($this->migrationsDir))->toBe([]);
});

it('refuses to write a second migration for the same table', function (): void {
    $this->artisan('clorian:add-column', ['table' => 'tickets'])->assertSuccessful();

    $this->artisan('clorian:add-column', ['table' => 'tickets'])
        ->expectsOutputToContain('A migration already adds clorian_status to [tickets]:')
        ->assertFailed();

    expect(generatedMigrations($this->migrationsDir))->toHaveCount(1);
});

it('warns, but still writes, for a table that does not exist yet', function (): void {
    $this->artisan('clorian:add-column', ['table' => 'orders'])
        ->expectsOutputToContain('No [orders] table yet; the migration will need it to exist when it runs.')
        ->assertSuccessful();

    expect(generatedMigrations($this->migrationsDir))->toHaveCount(1);
});

it('rejects anything but a plain table name', function (string $table): void {
    $this->artisan('clorian:add-column', ['table' => $table])
        ->expectsOutputToContain("[{$table}] is not a table name: letters, digits and underscores only.")
        ->assertExitCode(2);
})->with(['tickets; drop table users', '../tickets', '1tickets', 'my-table']);
