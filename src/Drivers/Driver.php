<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Drivers;

use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Questions\Question;

/**
 * Scores every question of a policy in one call. Implement it to use a
 * provider other than Jev: return a probability for every option of every
 * question (booleans as "true" and "false"), plus whatever metadata the
 * provider gives.
 */
interface Driver
{
    /**
     * The name stored on each weighing, e.g. "jev".
     */
    public function name(): string;

    /**
     * @param  array<string, Question>  $questions  keyed by question name
     * @param  string|array<string, mixed>  $content  the redacted input: a string, or field name => value
     * @param  array<string, mixed>  $context  the redacted Policy::context(), empty when there is none
     * @param  float|null  $timeout  seconds; null uses the driver's own setting
     *
     * @throws DriverException when the driver cannot score; its retryable flag decides whether a job tries again
     */
    public function score(array $questions, string|array $content, array $context, ?float $timeout = null): Scores;
}
