<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A driver could not score. Retryable failures (rate limits, overload,
 * timeouts, connection errors) are tried again with backoff; permanent ones
 * (bad key, invalid request, an answer this package does not understand)
 * fail at once. Messages never carry the content that was sent.
 */
final class DriverException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $status = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  int|null  $retryAfter  seconds the service asked to wait
     */
    public static function retryable(string $message, ?int $status = null, ?string $requestId = null, ?int $retryAfter = null, ?Throwable $previous = null): self
    {
        return new self($message, true, $status, $requestId, $retryAfter, $previous);
    }

    public static function permanent(string $message, ?int $status = null, ?string $requestId = null, ?Throwable $previous = null): self
    {
        return new self($message, false, $status, $requestId, null, $previous);
    }

    /**
     * The driver gave too little time and the call was abandoned.
     */
    public static function timedOut(string $driver, float $seconds, ?Throwable $previous = null): self
    {
        return new self(sprintf('The %s driver did not answer within %ss.', $driver, rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.')), true, previous: $previous);
    }

    /**
     * The driver answered with something the package cannot read.
     */
    public static function unexpected(string $driver, string $reason, ?int $status = null, ?string $requestId = null): self
    {
        return new self(sprintf('The %s driver sent scores this package does not understand: %s.', $driver, $reason), false, $status, $requestId);
    }
}
