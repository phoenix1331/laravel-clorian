<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tuning;

use Phoenix1331\Clorian\Models\TunedThreshold;
use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Resolution\TunedThresholds;

/**
 * Tuned thresholds from clorian_thresholds, one query per call. A null
 * accept means always review; a null review falls through to the level
 * below.
 */
final readonly class EloquentTunedThresholds implements TunedThresholds
{
    public function forPolicy(string $policy): array
    {
        $tuned = [];

        foreach (TunedThreshold::query()->where('policy', $policy)->get() as $row) {
            $overrides = new ThresholdOverrides;

            if ($row->accept === null) {
                $overrides->alwaysReview();
            }

            if ($row->accept !== null) {
                $overrides->accept($row->accept);
            }

            if ($row->review !== null) {
                $overrides->review($row->review);
            }

            $tuned[$row->question][$row->option ?? self::WHOLE_QUESTION] = $overrides;
        }

        return $tuned;
    }
}
