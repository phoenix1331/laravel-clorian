<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Exceptions;

use InvalidArgumentException;

/**
 * A policy's questions or thresholds cannot be used. Thrown when a policy is
 * first used and by clorian:tune, naming the policy, question and option so
 * the fix is obvious.
 */
final class InvalidThresholds extends InvalidArgumentException
{
    public function __construct(
        string $reason,
        public readonly string $policy,
        public readonly ?string $question = null,
        public readonly ?string $option = null,
    ) {
        parent::__construct(sprintf('Invalid %s: %s.', self::location($policy, $question, $option), $reason));
    }

    public static function forPolicy(string $policy, string $reason): self
    {
        return new self($reason, $policy);
    }

    public static function forQuestion(string $policy, string $question, string $reason): self
    {
        return new self($reason, $policy, $question);
    }

    public static function forOption(string $policy, string $question, string $option, string $reason): self
    {
        return new self($reason, $policy, $question, $option);
    }

    private static function location(string $policy, ?string $question, ?string $option): string
    {
        $location = "policy [{$policy}]";

        if ($question !== null) {
            $location .= ", question [{$question}]";
        }

        if ($option !== null) {
            $location .= ", option [{$option}]";
        }

        return $location;
    }
}
