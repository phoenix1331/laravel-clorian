<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Calibration;

/**
 * Calibration for one option of one question: how its answers resolved,
 * how humans judged them, per confidence band, and the accept threshold
 * the reviews support.
 */
final readonly class OptionStats
{
    /**
     * @param  list<Band>  $bands  lowest first
     * @param  float|null  $suggestedAccept  null until enough reviews meet the target
     */
    public function __construct(
        public string $policy,
        public string $question,
        public string $option,
        public int $accepted,
        public int $review,
        public int $unresolved,
        public ReviewCounts $reviews,
        public array $bands,
        public ?float $suggestedAccept,
    ) {}

    public function total(): int
    {
        return $this->accepted + $this->review + $this->unresolved;
    }
}
