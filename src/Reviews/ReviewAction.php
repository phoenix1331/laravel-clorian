<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Reviews;

/**
 * What a human decided about an answer. Stored in clorian_answers.review_action
 * and counted by the calibrator.
 */
enum ReviewAction: string
{
    /** Jev's answer was right. */
    case Upheld = 'upheld';

    /** Jev's answer was wrong; the reviewer chose another value. */
    case Overturned = 'overturned';

    /** The question did not apply, or no option fitted. */
    case Missed = 'missed';
}
