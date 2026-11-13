<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Exceptions\DriverException;

it('carries everything a job needs to decide on a retry', function (): void {
    $previous = new RuntimeException('cURL error 28');
    $e = DriverException::retryable('Jev returned HTTP 429: slow down', 429, 'req_1', 3, $previous);

    expect($e)
        ->retryable->toBeTrue()
        ->status->toBe(429)
        ->requestId->toBe('req_1')
        ->retryAfter->toBe(3)
        ->getPrevious()->toBe($previous)
        ->getMessage()->toBe('Jev returned HTTP 429: slow down');
});

it('marks permanent failures as not retryable', function (): void {
    expect(DriverException::permanent('Jev returned HTTP 401', 401, 'req_2'))
        ->retryable->toBeFalse()
        ->status->toBe(401)
        ->retryAfter->toBeNull();
});

it('treats a timeout as retryable', function (): void {
    expect(DriverException::timedOut('jev', 2.0))
        ->retryable->toBeTrue()
        ->getMessage()->toBe('The jev driver did not answer within 2s.')
        ->and(DriverException::timedOut('jev', 1.25)->getMessage())->toBe('The jev driver did not answer within 1.25s.');
});

it('explains an answer the package cannot read', function (): void {
    expect(DriverException::unexpected('jev', 'no scores for question [urgency]', 200, 'req_3'))
        ->retryable->toBeFalse()
        ->getMessage()->toBe('The jev driver sent scores this package does not understand: no scores for question [urgency].');
});
