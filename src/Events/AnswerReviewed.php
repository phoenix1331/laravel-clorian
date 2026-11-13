<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Phoenix1331\Clorian\Reviews\ReviewAction;

/**
 * A human upheld, overturned or marked missed an answer with Clorian::review().
 *
 * Ids and scalars only, never the weighed content, so it is safe to queue
 * and log. Dispatched after the surrounding transaction commits.
 */
final readonly class AnswerReviewed implements ShouldDispatchAfterCommit
{
    /**
     * @param  string|null  $value  the option the driver picked
     * @param  string|null  $reviewedValue  the option the reviewer decided on
     */
    public function __construct(
        public int $weighingId,
        public int $answerId,
        public string $policy,
        public string $question,
        public ReviewAction $action,
        public ?string $value,
        public ?string $reviewedValue,
        public ?string $reviewerType,
        public int|string|null $reviewerId,
        public ?string $subjectType,
        public int|string|null $subjectId,
    ) {}
}
