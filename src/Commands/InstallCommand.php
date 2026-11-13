<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Publishes the config and migrations, asks how models are weighed and
 * whether to migrate, then prints the .env keys. Never edits .env and
 * never overwrites a published file.
 */
final class InstallCommand extends Command
{
    public const string QUEUED = 'queued';

    public const string SYNC = 'sync';

    protected $signature = 'clorian:install';

    protected $description = 'Publish the Clorian config and migrations and show the .env keys to add';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'clorian-config']);
        $this->components->task('Published config/clorian.php (kept if it already existed)');

        $this->callSilently('vendor:publish', ['--tag' => 'clorian-migrations']);
        $this->components->task('Published the migrations');

        $interactive = $this->input->isInteractive();

        $mode = $interactive ? select(
            label: 'How should models be weighed?',
            options: [
                self::QUEUED => 'Queued: in a job after the save commits (recommended)',
                self::SYNC => 'Sync: inline during the save, falling back to defaults on timeout',
            ],
            default: self::QUEUED,
        ) : self::QUEUED;

        if ($interactive && confirm('Run the migrations now?', default: true)) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->line('  Add these to <comment>.env</comment>:');
        $this->newLine();

        foreach ($this->environment(is_string($mode) ? $mode : self::QUEUED) as $line) {
            $this->line("    {$line}");
        }

        $this->newLine();
        $this->line('  Next:');
        $this->line('    1. Pin JEV_MODEL to a version (clorian:ping shows the one jev-latest resolves to)');
        $this->line('    2. php artisan clorian:ping');
        $this->line('    3. Add #[Weigh(YourPolicy::class, fields: [...])] and HasWeighings to a model');

        if (! $interactive) {
            $this->line('    4. php artisan migrate');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function environment(string $mode): array
    {
        return [
            'JEV_API_KEY=',
            'JEV_MODEL=jev-latest',
            'CLORIAN_DRIVER=jev',
            "CLORIAN_MODE={$mode}",
            'CLORIAN_QUEUE=default',
            'CLORIAN_STORE_INPUT=subjectless',
        ];
    }
}
