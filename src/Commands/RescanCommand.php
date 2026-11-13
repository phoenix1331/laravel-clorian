<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Commands;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Phoenix1331\Clorian\Attributes\Weigh;
use Phoenix1331\Clorian\Clorian;
use Phoenix1331\Clorian\Exceptions\MissingWeighAttribute;
use Phoenix1331\Clorian\Jobs\WeighSubject;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Support\Weighable;

/**
 * Weighs existing models again, e.g. after changing a policy or tuning
 * thresholds. Unchanged content keeps its human decisions: Clorian carries
 * them onto the new answers.
 */
final class RescanCommand extends Command
{
    protected $signature = 'clorian:rescan
        {model : A model with #[Weigh], e.g. App\Models\Ticket or Ticket}
        {--since= : Only models created on or after this date}
        {--chunk=500 : Models read per query}
        {--sync : Weigh inline now instead of queueing jobs}';

    protected $description = 'Weigh existing models again, keeping human review decisions';

    public function handle(Clorian $clorian, Repository $config): int
    {
        $name = $this->argument('model');
        $name = is_string($name) ? $name : '';
        $class = $this->modelClass($name);

        if ($class === null) {
            $this->components->error(sprintf('No model [%s]. Pass the class, e.g. App\Models\Ticket.', $name));

            return self::INVALID;
        }

        try {
            Weigh::of($class);
        } catch (MissingWeighAttribute $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $chunk = $this->option('chunk');

        if (! is_string($chunk) || ! ctype_digit($chunk) || (int) $chunk < 1) {
            $this->components->error('--chunk must be a whole number above 0.');

            return self::INVALID;
        }

        $model = new $class;
        $query = $model->newQuery();
        $since = $this->option('since');

        if (is_string($since) && $since !== '') {
            $error = $this->since($query, $model, $since);

            if ($error !== null) {
                $this->components->error($error);

                return self::INVALID;
            }
        }

        $sync = $this->option('sync') === true || $config->get('clorian.mode') === 'sync';
        $queue = $config->get('clorian.queue');
        $weighed = 0;
        $failed = 0;

        $query->chunkById((int) $chunk, function (Collection $models) use ($clorian, $sync, $queue, &$weighed, &$failed): void {
            foreach ($models as $subject) {
                $weighed++;

                if (! $sync) {
                    WeighSubject::dispatch($subject)->onQueue(is_string($queue) && $queue !== '' ? $queue : null);

                    continue;
                }

                $weighing = $clorian->now(Weighable::policy($subject), Weighable::input($subject), $subject);

                if ($weighing->status === WeighingStatus::Failed) {
                    $failed++;
                }
            }
        });

        if ($weighed === 0) {
            $this->components->info(sprintf('No %s to weigh.', class_basename($class)));

            return self::SUCCESS;
        }

        if (! $sync) {
            $this->components->info(sprintf('Queued %d %s for weighing.', $weighed, class_basename($class)));

            return self::SUCCESS;
        }

        if ($failed > 0) {
            $this->components->warn(sprintf('%d of %d failed; their defaults were applied. Run again once the driver is back.', $failed, $weighed));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Weighed %d %s.', $weighed, class_basename($class)));

        return self::SUCCESS;
    }

    /**
     * The model class, as given or under App\Models.
     *
     * @return class-string<Model>|null
     */
    private function modelClass(string $name): ?string
    {
        foreach ([ltrim($name, '\\'), 'App\\Models\\'.ltrim($name, '\\')] as $candidate) {
            if (is_subclass_of($candidate, Model::class)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Limit to models created since a date, or say why not.
     *
     * @param  Builder<Model>  $query
     */
    private function since(Builder $query, Model $model, string $since): ?string
    {
        $column = $model->getCreatedAtColumn();

        if (! $model->usesTimestamps() || $column === null) {
            return sprintf('%s has no created_at timestamp, so --since cannot be used.', $model::class);
        }

        try {
            $date = Carbon::parse($since);
        } catch (InvalidFormatException) {
            return sprintf('--since must be a date, e.g. 2026-01-31 or "30 days ago", got "%s".', $since);
        }

        $query->where($model->qualifyColumn($column), '>=', $date);

        return null;
    }
}
