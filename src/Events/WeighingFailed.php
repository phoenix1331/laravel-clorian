<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The driver failed or timed out (for a queued weighing, after the last
 * retry). The weighing is stored as failed and the policy's defaults applied.
 *
 * Ids and scalars only, never the weighed content: the error is the
 * driver's content-free message. Dispatched after the surrounding
 * transaction commits.
 */
final readonly class WeighingFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $weighingId,
        public string $policy,
        public string $error,
        public ?string $subjectType,
        public int|string|null $subjectId,
    ) {}
}
