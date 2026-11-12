<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;

/**
 * @extends Factory<WeighingAnswer>
 */
final class WeighingAnswerFactory extends Factory
{
    protected $model = WeighingAnswer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weighing_id' => Weighing::factory(),
            'question' => 'category',
            'value' => 'billing',
            'confidence' => 0.95,
            'scores' => self::scores('billing', 0.95),
            'accept_threshold' => 0.90,
            'review_threshold' => 0.60,
            'status' => Status::Accepted,
        ];
    }

    /**
     * @param  list<string>  $options
     */
    public function answering(string $question, string $value, float $confidence, array $options = ['billing', 'bug', 'account', 'feature']): static
    {
        return $this->state([
            'question' => $question,
            'value' => $value,
            'confidence' => $confidence,
            'scores' => self::scores($value, $confidence, $options),
        ]);
    }

    public function accepted(): static
    {
        return $this->answering('category', 'billing', 0.95)->state(['status' => Status::Accepted]);
    }

    public function needsReview(): static
    {
        return $this->answering('category', 'bug', 0.72)->state(['status' => Status::Review]);
    }

    public function unresolved(): static
    {
        return $this->answering('category', 'account', 0.41)->state(['status' => Status::Unresolved]);
    }

    public function reviewed(ReviewAction $action = ReviewAction::Upheld, ?string $value = null, ?Model $reviewer = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'review_action' => $action,
            'reviewed_value' => $value ?? ($action === ReviewAction::Upheld ? $attributes['value'] : null),
            'reviewer_type' => $reviewer?->getMorphClass(),
            'reviewer_id' => $reviewer?->getKey(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * The chosen value at its confidence, the rest shared evenly.
     *
     * @param  list<string>  $options
     * @return array<string, float>
     */
    public static function scores(string $value, float $confidence, array $options = ['billing', 'bug', 'account', 'feature']): array
    {
        $others = array_values(array_diff($options, [$value]));
        $share = $others === [] ? 0.0 : round((1 - $confidence) / count($others), 4);

        return [$value => $confidence] + array_fill_keys($others, $share);
    }
}
