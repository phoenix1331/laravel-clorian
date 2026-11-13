<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;

/**
 * Retention: deletes weighings past clorian.prune.weighings_after_days,
 * clears stored input past clorian.prune.inputs_after_days (answers and
 * stats stay), and deletes weighings whose subject no longer exists.
 * Schedule it daily: Schedule::command('clorian:prune')->daily();
 */
final class PruneCommand extends Command
{
    public const int CHUNK = 1000;

    protected $signature = 'clorian:prune {--pretend : Count what would go without changing anything}';

    protected $description = 'Delete old weighings, clear old stored input and remove weighings of deleted subjects';

    public function handle(Repository $config): int
    {
        $weighingDays = self::days($config->get('clorian.prune.weighings_after_days'));
        $inputDays = self::days($config->get('clorian.prune.inputs_after_days'));

        if ($weighingDays === false || $inputDays === false) {
            $this->components->error('clorian.prune.weighings_after_days and inputs_after_days must be whole numbers of days above 0, or null to keep forever.');

            return self::INVALID;
        }

        $pretend = $this->option('pretend') === true;

        $orphans = $this->orphans();
        $old = $weighingDays === null ? null : Weighing::query()->where('created_at', '<', Carbon::now()->subDays($weighingDays));
        $inputs = $inputDays === null ? null : Weighing::query()->where('created_at', '<', Carbon::now()->subDays($inputDays))->whereNotNull('input');

        $rows = [
            ['Weighings of deleted subjects', $pretend ? count($orphans) : $this->deleteOrphans($orphans)],
            [
                $weighingDays === null ? 'Old weighings (kept: weighings_after_days is null)' : "Weighings older than {$weighingDays} days",
                $old === null ? 0 : ($pretend ? $old->count() : self::delete($old)),
            ],
            [
                $inputDays === null ? 'Old stored input (kept: inputs_after_days is null)' : "Stored input older than {$inputDays} days cleared",
                $inputs === null ? 0 : ($pretend ? $inputs->count() : $inputs->update(['input' => null])),
            ],
        ];

        $this->table(['Rule', $pretend ? 'Would change' : 'Changed'], $rows);

        $this->components->info($pretend ? 'Nothing changed (--pretend).' : 'Pruned. Answers on kept weighings, and so the stats, are untouched.');

        return self::SUCCESS;
    }

    /**
     * Null keeps forever; false is unusable.
     */
    private static function days(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : false;
    }

    /**
     * Ids of weighings whose subject is gone. Checked through each subject's
     * own model (its connection, soft-deleted rows counting as present);
     * types that no longer resolve to a model are left alone.
     *
     * @return list<int>
     */
    private function orphans(): array
    {
        $orphans = [];

        foreach (Weighing::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type') as $type) {
            $class = is_string($type) ? (Relation::getMorphedModel($type) ?? $type) : null;

            if ($class === null || ! is_subclass_of($class, Model::class)) {
                $this->components->warn(sprintf('Skipped weighings for [%s]: not a model class or morph map alias.', is_scalar($type) ? $type : '?'));

                continue;
            }

            $model = new $class;

            Weighing::query()->where('subject_type', $type)->select(['id', 'subject_id'])->chunkById(self::CHUNK, function ($weighings) use ($model, &$orphans): void {
                $ids = $weighings->pluck('subject_id')->filter(static fn (mixed $id): bool => is_scalar($id))->unique()->values()->all();
                $present = [];

                foreach ($model->newQueryWithoutScopes()->whereIn($model->getKeyName(), $ids)->pluck($model->getKeyName()) as $key) {
                    $present[] = is_scalar($key) ? (string) $key : '';
                }

                foreach ($weighings as $weighing) {
                    if (! in_array((string) $weighing->subject_id, $present, true)) {
                        $orphans[] = $weighing->id;
                    }
                }
            });
        }

        return $orphans;
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteOrphans(array $ids): int
    {
        $deleted = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $deleted += self::delete(Weighing::query()->whereKey($chunk));
        }

        return $deleted;
    }

    /**
     * Weighings and their answers; answers first, so no database relies on the cascade.
     *
     * @param  Builder<Weighing>  $weighings
     */
    private static function delete(Builder $weighings): int
    {
        WeighingAnswer::query()->whereIn('weighing_id', (clone $weighings)->select('id'))->delete();

        return $weighings->toBase()->delete();
    }
}
