<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Calibration;

use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;

/**
 * One stored answer as the calibrator reads it: the option the driver
 * picked, how sure it was, how that resolved and what a human said.
 */
final readonly class Observation
{
    public function __construct(
        public string $policy,
        public string $question,
        public string $option,
        public float $confidence,
        public Status $status,
        public ?ReviewAction $review = null,
    ) {}
}
