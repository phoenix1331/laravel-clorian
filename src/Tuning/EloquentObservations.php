<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tuning;

use DateTimeInterface;
use Phoenix1331\Clorian\Calibration\Observation;
use Phoenix1331\Clorian\Calibration\Observations;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;

/**
 * Answers from clorian_answers joined to their weighing's policy, read in
 * chunks so a large history never sits in memory at once.
 */
final readonly class EloquentObservations implements Observations
{
    public const int CHUNK = 1000;

    public function read(?string $policy = null, ?DateTimeInterface $since = null): iterable
    {
        $answers = (new WeighingAnswer)->getTable();
        $weighings = (new Weighing)->getTable();

        $rows = WeighingAnswer::query()
            ->join($weighings, "{$weighings}.id", '=', "{$answers}.weighing_id")
            ->when($policy !== null, fn ($query) => $query->where("{$weighings}.policy", $policy))
            ->when($since !== null, fn ($query) => $query->where("{$answers}.created_at", '>=', $since))
            ->whereNotNull("{$answers}.value")
            ->select([
                "{$answers}.id",
                "{$weighings}.policy",
                "{$answers}.question",
                "{$answers}.value",
                "{$answers}.confidence",
                "{$answers}.status",
                "{$answers}.review_action",
            ])
            ->toBase()
            ->lazyById(self::CHUNK, "{$answers}.id", 'id');

        foreach ($rows as $row) {
            $observation = self::observation($row);

            if ($observation !== null) {
                yield $observation;
            }
        }
    }

    /**
     * Rows the package did not write (an unknown status) are skipped.
     */
    private static function observation(object $row): ?Observation
    {
        $policy = $row->policy ?? null;
        $question = $row->question ?? null;
        $value = $row->value ?? null;
        $confidence = $row->confidence ?? null;
        $status = Status::tryFrom(is_string($row->status ?? null) ? $row->status : '');
        $review = $row->review_action ?? null;

        if (! is_string($policy) || ! is_string($question) || ! is_string($value) || ! is_numeric($confidence) || $status === null) {
            return null;
        }

        return new Observation(
            $policy,
            $question,
            $value,
            (float) $confidence,
            $status,
            is_string($review) ? ReviewAction::tryFrom($review) : null,
        );
    }
}
