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
    private function __construct(
        string $message,
        public readonly string $policy,
        public readonly ?string $question = null,
        public readonly ?string $option = null,
    ) {
        parent::__construct($message);
    }

    public static function forPolicy(string $policy, string $reason): self
    {
        return new self(self::message($reason, $policy), $policy);
    }

    public static function forQuestion(string $policy, string $question, string $reason): self
    {
        return new self(self::message($reason, $policy, $question), $policy, $question);
    }

    public static function forOption(string $policy, string $question, string $option, string $reason): self
    {
        return new self(self::message($reason, $policy, $question, $option), $policy, $question, $option);
    }

    /**
     * The defaults in config/clorian.php are wrong, so no policy can be used.
     */
    public static function inConfig(string $key, string $reason): self
    {
        return new self(sprintf('Invalid config [%s]: %s.', $key, $reason), '');
    }

    private static function message(string $reason, string $policy, ?string $question = null, ?string $option = null): string
    {
        $location = "policy [{$policy}]";

        if ($question !== null) {
            $location .= ", question [{$question}]";
        }

        if ($option !== null) {
            $location .= ", option [{$option}]";
        }

        return sprintf('Invalid %s: %s.', $location, $reason);
    }
}
