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
     * @param  string|bool|null  $value  what the driver picked (true/false for a boolean question)
     * @param  string|bool|null  $reviewedValue  what the reviewer decided; null when marked missed
     */
    public function __construct(
        public int $weighingId,
        public int $answerId,
        public string $policy,
        public string $question,
        public ReviewAction $action,
        public string|bool|null $value,
        public string|bool|null $reviewedValue,
        public ?string $reviewerType,
        public int|string|null $reviewerId,
        public ?string $subjectType,
        public int|string|null $subjectId,
    ) {}
}
