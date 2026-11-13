<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Observers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Attributes\Weigh;
use Phoenix1331\Clorian\Clorian;
use Phoenix1331\Clorian\Jobs\WeighSubject;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Support\Weighable;

/**
 * Weighs a model when it is created or its #[Weigh] fields change: queued after the save
 * commits, or inline in sync mode. Content already weighed successfully is
 * skipped. Registered by HasWeighings.
 *
 * @internal
 */
final readonly class WeighObserver
{
    public function __construct(
        private Repository $config,
        private Clorian $clorian,
    ) {}

    public function created(Model $model): void
    {
        $this->weigh($model);
    }

    public function updated(Model $model): void
    {
        if ($model->wasChanged(Weigh::of($model)->fields)) {
            $this->weigh($model);
        }
    }

    private function weigh(Model $model): void
    {
        if ($this->config->get('clorian.enabled', true) === false) {
            return;
        }

        $input = Weighable::input($model);

        if ($this->alreadyWeighed($model, Clorian::hash($input))) {
            return;
        }

        if ($this->config->get('clorian.mode') === 'sync') {
            $this->clorian->now(Weighable::policy($model), $input, $model);

            return;
        }

        $queue = $this->config->get('clorian.queue');

        WeighSubject::dispatch($model)
            ->onQueue(is_string($queue) && $queue !== '' ? $queue : null)
            ->afterCommit();
    }

    private function alreadyWeighed(Model $model, string $hash): bool
    {
        $latest = Weighing::query()
            ->where('subject_type', $model->getMorphClass())
            ->where('subject_id', $model->getKey())
            ->latest('id')
            ->first(['content_hash', 'status']);

        return $latest !== null && $latest->status === WeighingStatus::Complete && $latest->content_hash === $hash;
    }
}
