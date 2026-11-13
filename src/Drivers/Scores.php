<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Drivers;

use InvalidArgumentException;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Questions\Question;

/**
 * What a driver returned: a probability for every option of every question,
 * and what it knows about the call.
 */
final readonly class Scores
{
    /**
     * @param  array<string, array<string, float>>  $probabilities  question => option => probability
     * @param  string|null  $modelVersion  the version that answered (jev-1.14.0), not the alias asked for
     * @param  bool  $truncated  the input was cut to fit the driver's context budget
     */
    public function __construct(
        public array $probabilities,
        public ?string $modelVersion = null,
        public ?int $latencyMs = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $requestId = null,
        public bool $truncated = false,
    ) {}

    /**
     * @return array<string, float> option => probability
     */
    public function for(string $question): array
    {
        if (! isset($this->probabilities[$question])) {
            throw new InvalidArgumentException(sprintf('No scores for question [%s].', $question));
        }

        return $this->probabilities[$question];
    }

    /**
     * The highest-scoring option and its probability. Ties go to the option
     * listed first.
     *
     * @return array{0: string, 1: float}
     */
    public function top(string $question): array
    {
        $top = null;
        $best = -1.0;

        foreach ($this->for($question) as $option => $probability) {
            if ($probability > $best) {
                $top = $option;
                $best = $probability;
            }
        }

        if ($top === null) {
            throw new InvalidArgumentException(sprintf('No options scored for question [%s].', $question));
        }

        return [$top, $best];
    }

    /**
     * Metadata for clorian_weighings.meta.
     *
     * @return array<string, int|string|true>
     */
    public function meta(): array
    {
        return array_filter([
            'latency_ms' => $this->latencyMs,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'request_id' => $this->requestId,
            'truncated' => $this->truncated ?: null,
        ], static fn (int|string|bool|null $value): bool => $value !== null);
    }

    /**
     * Check the scores cover exactly the questions asked and their options,
     * each between 0 and 1.
     *
     * @param  array<string, Question>  $questions
     *
     * @throws DriverException naming the driver and what is wrong
     */
    public function assertCovers(array $questions, string $driver): void
    {
        foreach ($questions as $name => $question) {
            if (! isset($this->probabilities[$name])) {
                throw DriverException::unexpected($driver, sprintf('no scores for question [%s]', $name), requestId: $this->requestId);
            }

            $scored = $this->probabilities[$name];
            $expected = $question->options();
            $given = array_map(strval(...), array_keys($scored));

            sort($expected);
            sort($given);

            if ($given !== $expected) {
                throw DriverException::unexpected($driver, sprintf('question [%s] scored options (%s), expected (%s)', $name, implode(', ', $given), implode(', ', $expected)), requestId: $this->requestId);
            }

            foreach ($scored as $option => $probability) {
                // Negated so NAN, which fails every comparison, is rejected too.
                if (! ($probability >= 0.0 && $probability <= 1.0)) {
                    throw DriverException::unexpected($driver, sprintf('question [%s] option [%s] has probability %s, outside 0 to 1', $name, $option, is_nan($probability) ? 'NAN' : (string) $probability), requestId: $this->requestId);
                }
            }
        }
    }
}
