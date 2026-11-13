<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Calibration;

use InvalidArgumentException;
use Phoenix1331\Clorian\Resolution\Status;

/**
 * Turns stored answers and their reviews into calibration per policy,
 * question and option. The suggested accept threshold is the lowest band
 * edge where the reviewed answers at or above it were upheld at the target
 * rate over at least the minimum sample, with every reviewed band from
 * there up meeting the target on its own: accepting from there would have
 * been right that often.
 */
final readonly class Calibrator
{
    public const float DEFAULT_TARGET = 0.95;

    public const int DEFAULT_MIN_SAMPLE = 30;

    /** Band lower edges; the last band runs to 1.0. */
    public const array DEFAULT_BANDS = [0.6, 0.7, 0.8, 0.9];

    /**
     * @param  float  $target  upheld rate an accept threshold must reach, 0 to 1
     * @param  int  $minSample  reviews needed before suggesting anything
     * @param  list<float>  $bands  ascending lower edges between 0 and 1
     */
    public function __construct(
        public float $target = self::DEFAULT_TARGET,
        public int $minSample = self::DEFAULT_MIN_SAMPLE,
        public array $bands = self::DEFAULT_BANDS,
    ) {
        if ($target <= 0.0 || $target > 1.0) {
            throw new InvalidArgumentException(sprintf('The calibration target must be above 0 and at most 1, got %s.', $target));
        }

        if ($minSample < 1) {
            throw new InvalidArgumentException(sprintf('The calibration min_sample must be at least 1, got %d.', $minSample));
        }

        if ($bands === [] || $bands !== array_values(array_unique($bands, SORT_REGULAR)) || $bands !== self::sorted($bands) || min($bands) < 0.0 || max($bands) >= 1.0) {
            throw new InvalidArgumentException('Calibration bands must be ascending, distinct lower edges from 0 up to but not including 1.');
        }
    }

    /**
     * @param  iterable<Observation>  $observations
     * @return list<OptionStats> ordered by policy, question and option
     */
    public function calibrate(iterable $observations): array
    {
        $tallies = [];

        foreach ($observations as $observation) {
            $key = implode("\0", [$observation->policy, $observation->question, $observation->option]);
            $tallies[$key] ??= new Tally($observation->policy, $observation->question, $observation->option);
            $tallies[$key]->add($observation, $this->bandIndex($observation->confidence));
        }

        ksort($tallies, SORT_STRING);

        return array_values(array_map($this->stats(...), $tallies));
    }

    private function stats(Tally $tally): OptionStats
    {
        $bands = [];

        foreach ($this->bands as $i => $from) {
            $bands[] = new Band($from, $this->bands[$i + 1] ?? null, $tally->bandAnswers[$i] ?? 0, $tally->bandReviews[$i] ?? new ReviewCounts);
        }

        return new OptionStats(
            $tally->policy,
            $tally->question,
            $tally->option,
            $tally->count(Status::Accepted),
            $tally->count(Status::Review),
            $tally->count(Status::Unresolved),
            $tally->reviews,
            $bands,
            $this->suggest($bands),
        );
    }

    /**
     * Walking down from the top band: an edge is suggested when its band has
     * reviews of its own meeting the target, and the reviews from there up
     * reach the minimum sample and the target together. The walk stops at
     * the first band whose own reviews miss the target, so a weak band is
     * never accepted on the strength of the bands around it.
     *
     * @param  list<Band>  $bands
     */
    private function suggest(array $bands): ?float
    {
        $suggestion = null;
        $above = new ReviewCounts;

        foreach (array_reverse($bands) as $band) {
            $own = $band->reviews->upheldRate();

            if ($own === null) {
                continue;
            }

            if ($own < $this->target) {
                break;
            }

            $above = $above->plus($band->reviews);

            if ($above->reviewed() >= $this->minSample && $above->upheldRate() >= $this->target) {
                $suggestion = $band->from;
            }
        }

        return $suggestion;
    }

    private function bandIndex(float $confidence): ?int
    {
        $index = null;

        foreach ($this->bands as $i => $from) {
            // Confidences are stored to 4dp: whole basis points keep 0.7 in the 0.70 band.
            if ((int) round($confidence * 10_000) >= (int) round($from * 10_000)) {
                $index = $i;
            }
        }

        return $index;
    }

    /**
     * @param  list<float>  $bands
     * @return list<float>
     */
    private static function sorted(array $bands): array
    {
        sort($bands);

        return $bands;
    }
}
