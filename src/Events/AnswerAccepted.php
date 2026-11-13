<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An answer reached its accept threshold and was applied automatically.
 *
 * Ids and scalars only, never the weighed content, so it is safe to queue
 * and log. Dispatched after the surrounding transaction commits.
 */
final readonly class AnswerAccepted implements ShouldDispatchAfterCommit
{
    /**
     * @param  string|bool  $value  the option, or true/false for a boolean question
     */
    public function __construct(
        public int $weighingId,
        public int $answerId,
        public string $policy,
        public string $question,
        public string|bool $value,
        public float $confidence,
        public ?string $subjectType,
        public int|string|null $subjectId,
    ) {}
}
