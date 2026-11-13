<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Resolution;

use Phoenix1331\Clorian\Questions\Thresholds;

/**
 * One question's answer: the top option, how sure the driver was, the
 * thresholds in force when it was judged, and the status that gave.
 */
final readonly class Answer
{
    /**
     * @param  string  $option  the option name as stored ("true"/"false" for booleans)
     * @param  string|bool  $value  the option, or true/false for a boolean question
     * @param  array<string, float>  $scores  every option's probability
     */
    public function __construct(
        public string $question,
        public string $option,
        public string|bool $value,
        public float $confidence,
        public array $scores,
        public Thresholds $thresholds,
        public Status $status,
    ) {}

    public function isAccepted(): bool
    {
        return $this->status === Status::Accepted;
    }

    public function needsReview(): bool
    {
        return $this->status === Status::Review;
    }

    public function isUnresolved(): bool
    {
        return $this->status === Status::Unresolved;
    }
}
