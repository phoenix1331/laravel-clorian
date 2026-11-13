<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Resolution;

use Phoenix1331\Clorian\Questions\ThresholdOverrides;

/**
 * Where tuned thresholds come from. Read once per weighing; the package
 * ships an Eloquent lookup over clorian_thresholds, and NoTunedThresholds
 * for code that has no database.
 */
interface TunedThresholds
{
    /** The option key for a threshold that covers the whole question. */
    public const string WHOLE_QUESTION = '';

    /**
     * Every tuned threshold of a policy.
     *
     * @param  string  $policy  the policy class
     * @return array<string, array<string, ThresholdOverrides>> question => option (WHOLE_QUESTION for the question) => overrides
     */
    public function forPolicy(string $policy): array;
}
