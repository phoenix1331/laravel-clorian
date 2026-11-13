<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Commands;

use Illuminate\Console\Command;
use Phoenix1331\Clorian\Clorian;
use Phoenix1331\Clorian\Drivers\JevDriver;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Questions\Question;

/**
 * One sample weighing through the configured driver, so a key, model or
 * network problem shows up before real traffic does. Nothing is stored and
 * no policy runs. The key is never printed, only whether it is set.
 */
final class PingCommand extends Command
{
    public const string SAMPLE = 'Hello, I was charged twice for my subscription this month. Please refund the second payment.';

    /** Model names that move to new versions without notice. */
    public const array FLOATING = ['-latest', '-preview'];

    protected $signature = 'clorian:ping';

    protected $description = 'Check the driver and key with one sample weighing';

    public function handle(Clorian $clorian): int
    {
        $driver = $clorian->driver();
        $model = $driver instanceof JevDriver ? $driver->model() : null;

        $this->components->twoColumnDetail('Driver', $driver->name());

        if ($driver instanceof JevDriver) {
            $key = $this->laravel->make('config')->get('clorian.drivers.jev.key');
            $this->components->twoColumnDetail('Key', is_string($key) && trim($key) !== '' ? 'set' : '<fg=red>missing</>');
            $this->components->twoColumnDetail('Model', (string) $model);
        }

        try {
            $scores = $driver->score(self::questions(), self::SAMPLE, []);
        } catch (DriverException $e) {
            $this->components->error($e->getMessage().($e->requestId === null ? '' : " (request {$e->requestId})"));

            return self::FAILURE;
        }

        if ($scores->modelVersion !== null) {
            $this->components->twoColumnDetail('Answered by', $scores->modelVersion);
        }

        if ($scores->latencyMs !== null) {
            $this->components->twoColumnDetail('Latency', "{$scores->latencyMs} ms");
        }

        if ($scores->requestId !== null) {
            $this->components->twoColumnDetail('Request id', $scores->requestId);
        }

        foreach (array_keys(self::questions()) as $question) {
            [$option, $probability] = $scores->top($question);
            $this->components->twoColumnDetail("Sample: {$question}", sprintf('%s (%s)', $option, number_format($probability, 2)));
        }

        if ($model !== null && self::floats($model)) {
            $this->components->warn(sprintf(
                '%s moves to new versions without notice, which shifts confidences and invalidates calibration. Pin it: JEV_MODEL=%s',
                $model,
                $scores->modelVersion ?? 'a version from TypeSafe\'s docs',
            ));
        }

        $this->components->info(sprintf('The %s driver answered. Clorian is ready.', $driver->name()));

        return self::SUCCESS;
    }

    /**
     * @return array<string, Question>
     */
    private static function questions(): array
    {
        return [
            'topic' => Question::choice('topic', ['billing', 'technical', 'other']),
            'wants_refund' => Question::boolean('wants_refund'),
        ];
    }

    private static function floats(string $model): bool
    {
        foreach (self::FLOATING as $suffix) {
            if (str_ends_with($model, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
