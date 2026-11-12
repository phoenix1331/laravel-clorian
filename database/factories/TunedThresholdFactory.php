<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Phoenix1331\Clorian\Models\TunedThreshold;

/**
 * @extends Factory<TunedThreshold>
 */
final class TunedThresholdFactory extends Factory
{
    protected $model = TunedThreshold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'policy' => 'App\\Clorian\\TicketTriage',
            'question' => 'urgency',
            'option' => null,
            'accept' => 0.85,
            'review' => 0.55,
        ];
    }

    public function forOption(string $option): static
    {
        return $this->state(['option' => $option]);
    }

    public function alwaysReview(): static
    {
        return $this->state(['accept' => null]);
    }
}
