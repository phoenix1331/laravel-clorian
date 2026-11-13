<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Drivers;

use Closure;
use InvalidArgumentException;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Questions\BooleanQuestion;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\Thresholds;

/**
 * Scripted scores for tests and offline demos. Nothing leaves the app.
 *
 * Scripted questions get the probability given; the rest share what is left
 * evenly. Questions with no script get an even spread across their options,
 * which resolves as unresolved under any sensible thresholds.
 */
final class FakeDriver implements Driver
{
    public const string NAME = 'fake';

    public const string MODEL_VERSION = 'fake';

    /** @var array<string, array{value: string, probability: float}> */
    private array $answers = [];

    /** @var array<string, array<string, float>> */
    private array $distributions = [];

    /** @var (Closure(array<string, Question>, string|array<string, mixed>, array<string, mixed>): array<string, array{0: string|bool, 1: float}>)|null */
    private ?Closure $using = null;

    private ?DriverException $failure = null;

    /** @var list<array{questions: array<string, Question>, content: string|array<string, mixed>, context: array<string, mixed>, timeout: float|null}> */
    private array $calls = [];

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Answer a question with a value at a probability. Booleans take true or false.
     */
    public function answer(string $question, string|bool $value, float $probability): self
    {
        // Negated so NAN, which fails every comparison, is rejected too.
        if (! ($probability >= 0.0 && $probability <= 1.0)) {
            throw new InvalidArgumentException(sprintf('The probability for [%s] must be between 0 and 1, got %s.', $question, is_nan($probability) ? 'NAN' : (string) $probability));
        }

        $this->answers[$question] = ['value' => self::option($value), 'probability' => $probability];
        unset($this->distributions[$question]);

        return $this;
    }

    /**
     * Score every option of a question exactly.
     *
     * @param  array<string, float>  $probabilities  option => probability
     */
    public function distribution(string $question, array $probabilities): self
    {
        $this->distributions[$question] = $probabilities;
        unset($this->answers[$question]);

        return $this;
    }

    /**
     * Decide answers from the input, e.g. to seed demo data offline. Return
     * question => [value, probability]; questions left out fall back to the
     * scripted answers, then to an even spread.
     *
     * @param  Closure(array<string, Question>, string|array<string, mixed>, array<string, mixed>): array<string, array{0: string|bool, 1: float}>  $using
     */
    public function using(Closure $using): self
    {
        $this->using = $using;

        return $this;
    }

    /**
     * Throw on every call until reset(). Retryable by default, like a 529.
     */
    public function fail(string $message = 'The fake driver was told to fail.', bool $retryable = true): self
    {
        $this->failure = $retryable ? DriverException::retryable($message) : DriverException::permanent($message);

        return $this;
    }

    /**
     * Forget every script, failure and recorded call.
     */
    public function reset(): self
    {
        $this->answers = [];
        $this->distributions = [];
        $this->using = null;
        $this->failure = null;
        $this->calls = [];

        return $this;
    }

    public function score(array $questions, string|array $content, array $context, ?float $timeout = null): Scores
    {
        $this->calls[] = ['questions' => $questions, 'content' => $content, 'context' => $context, 'timeout' => $timeout];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $answers = $this->answers;

        if ($this->using !== null) {
            foreach (($this->using)($questions, $content, $context) as $question => [$value, $probability]) {
                $answers[$question] = ['value' => self::option($value), 'probability' => $probability];
            }
        }

        $probabilities = [];

        foreach ($questions as $name => $question) {
            $probabilities[$name] = $this->distributions[$name]
                ?? (isset($answers[$name]) ? self::spread($question, $answers[$name]['value'], $answers[$name]['probability']) : self::even($question));
        }

        $scores = new Scores($probabilities, self::MODEL_VERSION, latencyMs: 0);
        $scores->assertCovers($questions, self::NAME);

        return $scores;
    }

    /**
     * Every call so far, oldest first.
     *
     * @return list<array{questions: array<string, Question>, content: string|array<string, mixed>, context: array<string, mixed>, timeout: float|null}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    private static function option(string|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? BooleanQuestion::TRUE : BooleanQuestion::FALSE;
        }

        return $value;
    }

    /**
     * @return array<string, float>
     */
    private static function spread(Question $question, string $value, float $probability): array
    {
        $options = $question->options();

        if (! in_array($value, $options, true)) {
            throw new InvalidArgumentException(sprintf('[%s] is not an option of question [%s]. Options: %s.', $value, $question->name(), implode(', ', $options)));
        }

        if (count($options) < 2) {
            return [$value => $probability];
        }

        $share = round((1.0 - $probability) / (count($options) - 1), Thresholds::PRECISION);
        $probabilities = [];

        foreach ($options as $option) {
            $probabilities[$option] = $option === $value ? $probability : $share;
        }

        return $probabilities;
    }

    /**
     * @return array<string, float>
     */
    private static function even(Question $question): array
    {
        $options = $question->options();

        return array_fill_keys($options, round(1.0 / count($options), Thresholds::PRECISION));
    }
}
