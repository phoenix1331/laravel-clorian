<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Phoenix1331\Clorian\Clorian;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Observers\WeighObserver;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Support\Weighable;

/**
 * For Eloquent models with a #[Weigh] attribute: their weighings, the
 * latest answers, a way to weigh on demand, and the review queue scope.
 *
 * @mixin Model
 */
trait HasWeighings
{
    /**
     * Weigh the model whenever its #[Weigh] fields change.
     */
    public static function bootHasWeighings(): void
    {
        // Listeners, not observe(): observe() builds a model while this one is still booting.
        static::created(static fn (Model $model) => Container::getInstance()->make(WeighObserver::class)->created($model));
        static::updated(static fn (Model $model) => Container::getInstance()->make(WeighObserver::class)->updated($model));
    }

    /**
     * @return MorphMany<Weighing, $this>
     */
    public function weighings(): MorphMany
    {
        return $this->morphMany(Weighing::class, 'subject');
    }

    /**
     * @return MorphOne<Weighing, $this>
     */
    public function latestWeighing(): MorphOne
    {
        return $this->morphOne(Weighing::class, 'subject')->latestOfMany();
    }

    /**
     * The most recent answer to a question, from any weighing of this model.
     */
    public function answer(string $question): ?WeighingAnswer
    {
        return WeighingAnswer::query()
            ->where('question', $question)
            ->whereHas('weighing', fn (Builder $weighing) => $weighing
                ->where('subject_type', $this->getMorphClass())
                ->where('subject_id', $this->getKey()))
            ->latest('id')
            ->first();
    }

    /**
     * Weigh now, whatever changed, and apply the result.
     */
    public function weighNow(): Weighing
    {
        return Container::getInstance()->make(Clorian::class)->now($this->weighPolicy(), $this->weighInput(), $this);
    }

    /**
     * The policy class from #[Weigh].
     */
    public function weighPolicy(): string
    {
        return Weighable::policy($this);
    }

    /**
     * The #[Weigh] fields as sent to the driver: field => value, with enums,
     * dates and stringables turned into plain values.
     *
     * @return array<string, mixed>
     */
    public function weighInput(): array
    {
        return Weighable::input($this);
    }

    /**
     * Models whose latest weighing has an answer waiting for a human.
     *
     * @param  Builder<static>  $query
     */
    public function scopePendingReview(Builder $query): void
    {
        $query->whereHas('latestWeighing.answers', fn (Builder $answers) => $answers
            ->where('status', Status::Review)
            ->whereNull('review_action'));
    }
}
