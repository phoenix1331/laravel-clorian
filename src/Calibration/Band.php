<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Calibration;

/**
 * Answers whose confidence is at least $from and below $to (or up to 1.0
 * for the top band), with how humans judged the reviewed ones.
 */
final readonly class Band
{
    public function __construct(
        public float $from,
        public ?float $to,
        public int $answers,
        public ReviewCounts $reviews,
    ) {}

    /**
     * "0.60-0.70", or "0.90+" for the top band.
     */
    public function label(): string
    {
        return $this->to === null
            ? sprintf('%.2f+', $this->from)
            : sprintf('%.2f-%.2f', $this->from, $this->to);
    }
}
