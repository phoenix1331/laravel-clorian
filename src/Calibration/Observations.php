<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Calibration;

use DateTimeInterface;

/**
 * Where the calibrator's answers come from: the database in Laravel, any
 * source elsewhere.
 */
interface Observations
{
    /**
     * Answers from complete weighings, optionally for one policy and since a time.
     *
     * @return iterable<Observation>
     */
    public function read(?string $policy = null, ?DateTimeInterface $since = null): iterable;
}
