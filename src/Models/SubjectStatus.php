<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Models;

use Phoenix1331\Clorian\Resolution\Status;

/**
 * Where a model stands after its latest weighing. Stored in the optional
 * clorian_status column that clorian:add-column adds to an app's table.
 */
enum SubjectStatus: string
{
    /** Answered; nothing waits for a human. */
    case Complete = 'complete';

    /** At least one answer waits for a human review. */
    case Review = 'review';

    /** The driver failed; the policy defaults apply. */
    case Failed = 'failed';

    public static function of(Weighing $weighing): self
    {
        if ($weighing->status === WeighingStatus::Failed) {
            return self::Failed;
        }

        foreach ($weighing->answers as $answer) {
            if ($answer->status === Status::Review && ! $answer->isReviewed()) {
                return self::Review;
            }
        }

        return self::Complete;
    }
}
