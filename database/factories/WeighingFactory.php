<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingStatus;

/**
 * @extends Factory<Weighing>
 */
final class WeighingFactory extends Factory
{
    protected $model = Weighing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => null,
            'subject_id' => null,
            'policy' => 'App\\Clorian\\TicketTriage',
            'model_version' => 'jev-1.14.0',
            'driver' => 'fake',
            'status' => WeighingStatus::Complete,
            'content_hash' => hash('sha256', $this->faker->sentence()),
            'input' => null,
            'error' => null,
            'meta' => ['latency_ms' => $this->faker->numberBetween(150, 400)],
        ];
    }

    public function forSubject(Model $subject): static
    {
        return $this->state([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function policy(string $policy): static
    {
        return $this->state(['policy' => $policy]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function withInput(array $input): static
    {
        return $this->state(['input' => $input]);
    }

    public function failed(string $error = 'Jev returned HTTP 529: overloaded'): static
    {
        return $this->state([
            'status' => WeighingStatus::Failed,
            'model_version' => null,
            'error' => $error,
        ]);
    }
}
