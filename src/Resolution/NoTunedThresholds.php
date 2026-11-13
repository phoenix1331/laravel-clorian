<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Resolution;

/**
 * No tuning: policies and config defaults decide every threshold.
 */
final readonly class NoTunedThresholds implements TunedThresholds
{
    public function forPolicy(string $policy): array
    {
        return [];
    }
}
