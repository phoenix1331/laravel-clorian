<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Resolution;

/**
 * How an answer resolved against its thresholds. Stored in clorian_answers.status.
 */
enum Status: string
{
    /** Confidence reached the accept threshold: applied automatically. */
    case Accepted = 'accepted';

    /** Reached the review threshold only: the default applies until a human decides. */
    case Review = 'review';

    /** Below the review threshold: the default applies, still recorded for stats. */
    case Unresolved = 'unresolved';
}
