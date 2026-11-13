<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Calibration;

use Phoenix1331\Clorian\Resolution\Status;

/**
 * Running counts for one option while the calibrator reads.
 *
 * @internal
 */
final class Tally
{
    /** @var array<string, int> status => answers */
    public array $statuses = [];

    public ReviewCounts $reviews;

    /** @var array<int, int> band index => answers */
    public array $bandAnswers = [];

    /** @var array<int, ReviewCounts> band index => reviews */
    public array $bandReviews = [];

    public function __construct(
        public readonly string $policy,
        public readonly string $question,
        public readonly string $option,
    ) {
        $this->reviews = new ReviewCounts;
    }

    public function add(Observation $observation, ?int $band): void
    {
        $this->statuses[$observation->status->value] = $this->count($observation->status) + 1;

        if ($band !== null) {
            $this->bandAnswers[$band] = ($this->bandAnswers[$band] ?? 0) + 1;
        }

        if ($observation->review === null) {
            return;
        }

        $this->reviews = $this->reviews->with($observation->review);

        if ($band !== null) {
            $this->bandReviews[$band] = ($this->bandReviews[$band] ?? new ReviewCounts)->with($observation->review);
        }
    }

    public function count(Status $status): int
    {
        return $this->statuses[$status->value] ?? 0;
    }
}
