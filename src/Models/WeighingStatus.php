<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Models;

/**
 * Whether a weighing got answers from the driver. Stored in clorian_weighings.status.
 */
enum WeighingStatus: string
{
    /** The driver answered; one clorian_answers row per question. */
    case Complete = 'complete';

    /** The driver failed or timed out; defaults applied, no answers. */
    case Failed = 'failed';
}
