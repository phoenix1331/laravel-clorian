<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Resolution;

use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Questions\BooleanQuestion;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Questions\Thresholds;

/**
 * Turns a driver's scores into answers. Thresholds, highest first: tuned
 * option, tuned question, policy option, policy question, config default.
 * Reaching a threshold exactly counts; a null accept never accepts.
 */
final readonly class ThresholdResolver
{
    public function __construct(private Thresholds $defaults) {}

    /**
     * @param  string  $policy  the policy class, for error messages
     * @param  array<string, Question>  $questions  keyed by question name
     * @param  array<string, array<string, ThresholdOverrides>>  $tuned  from TunedThresholds::forPolicy()
     *
     * @throws InvalidThresholds when tuning leaves an option with unusable thresholds
     */
    public function resolve(string $policy, array $questions, Scores $scores, array $tuned = []): ResolvedAnswers
    {
        $answers = [];

        foreach ($questions as $name => $question) {
            $answers[$name] = $this->answer($policy, $question, $scores, $tuned[$name] ?? []);
        }

        return new ResolvedAnswers($answers);
    }

    /**
     * The thresholds in force for one option.
     *
     * @param  array<string, ThresholdOverrides>  $tuned  option (TunedThresholds::WHOLE_QUESTION for the question) => overrides
     *
     * @throws InvalidThresholds
     */
    public function thresholdsFor(string $policy, Question $question, string $option, array $tuned = []): Thresholds
    {
        $thresholds = $question->thresholdsFor($option, $this->defaults);

        foreach ([TunedThresholds::WHOLE_QUESTION, $option] as $level) {
            if (isset($tuned[$level])) {
                $thresholds = $thresholds->with($tuned[$level]);
            }
        }

        $problem = $thresholds->problem();

        if ($problem !== null) {
            throw InvalidThresholds::forOption($policy, $question->name(), $option, 'with tuned thresholds, '.$problem);
        }

        return $thresholds;
    }

    public static function status(float $confidence, Thresholds $thresholds): Status
    {
        $confidence = round($confidence, Thresholds::PRECISION);

        if ($thresholds->accept !== null && $confidence >= $thresholds->accept) {
            return Status::Accepted;
        }

        if ($confidence >= $thresholds->review) {
            return Status::Review;
        }

        return Status::Unresolved;
    }

    /**
     * @param  array<string, ThresholdOverrides>  $tuned
     */
    private function answer(string $policy, Question $question, Scores $scores, array $tuned): Answer
    {
        $name = $question->name();

        [$option, $confidence] = $question instanceof BooleanQuestion
            ? BooleanQuestion::answerFor($scores->for($name)[BooleanQuestion::TRUE] ?? 0.0)
            : $scores->top($name);

        $thresholds = $this->thresholdsFor($policy, $question, $option, $tuned);

        return new Answer(
            question: $name,
            option: $option,
            value: $question instanceof BooleanQuestion ? $option === BooleanQuestion::TRUE : $option,
            confidence: round($confidence, Thresholds::PRECISION),
            scores: $scores->for($name),
            thresholds: $thresholds,
            status: self::status($confidence, $thresholds),
        );
    }
}
