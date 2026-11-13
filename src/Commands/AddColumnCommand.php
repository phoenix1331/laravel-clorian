<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Phoenix1331\Clorian\Support\StatusColumn;

/**
 * Writes a migration adding an indexed clorian_status column to one of the
 * app's tables, so pendingReview() can use an index instead of a subquery.
 * Nothing is migrated or enabled here.
 */
final class AddColumnCommand extends Command
{
    protected $signature = 'clorian:add-column {table : The table of a model using HasWeighings, e.g. tickets}';

    protected $description = 'Generate a migration for an indexed clorian_status column on one of your tables';

    public function handle(Repository $config, DatabaseManager $db, Filesystem $files): int
    {
        $table = $this->argument('table');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            $this->components->error(sprintf('[%s] is not a table name: letters, digits and underscores only.', $table));

            return self::INVALID;
        }

        $name = $config->get('clorian.status_column.name');
        $column = is_string($name) && $name !== '' ? $name : StatusColumn::DEFAULT_NAME;
        $schema = $db->connection()->getSchemaBuilder();

        if ($schema->hasTable($table) && $schema->hasColumn($table, $column)) {
            $this->components->error(sprintf('[%s] already has a %s column.', $table, $column));

            return self::FAILURE;
        }

        if (! $schema->hasTable($table)) {
            $this->components->warn(sprintf('No [%s] table yet; the migration will need it to exist when it runs.', $table));
        }

        $directory = $this->laravel->databasePath('migrations');
        $existing = $files->glob($directory."/*_add_{$column}_to_{$table}_table.php");
        $first = $existing[0] ?? null;

        if (is_string($first)) {
            $this->components->error(sprintf('A migration already adds %s to [%s]: %s', $column, $table, basename($first)));

            return self::FAILURE;
        }

        $path = sprintf('%s/%s_add_%s_to_%s_table.php', $directory, Carbon::now()->format('Y_m_d_His'), $column, $table);
        $files->ensureDirectoryExists($directory);
        $files->put($path, self::migration($table, $column));

        $this->components->info(sprintf('Created %s.', basename($path)));
        $this->line('  Next:');
        $this->line('    1. php artisan migrate');
        $this->line('    2. CLORIAN_STATUS_COLUMN=true in .env');
        $this->line(sprintf('    3. php artisan clorian:rescan "App\Models\YourModel" to fill %s for existing rows', $column));
        $this->newLine();

        return self::SUCCESS;
    }

    public static function migration(string $table, string $column): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        /*
         * Clorian's status for each row (complete, review or failed), kept in
         * sync when clorian.status_column.enabled is on.
         */
        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('{$table}', function (Blueprint \$table): void {
                    \$table->string('{$column}', 20)->nullable()->index();
                });
            }

            public function down(): void
            {
                Schema::table('{$table}', function (Blueprint \$table): void {
                    \$table->dropIndex(['{$column}']);
                    \$table->dropColumn('{$column}');
                });
            }
        };

        PHP;
    }
}
