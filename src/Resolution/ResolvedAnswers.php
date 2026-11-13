<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Resolution;

use InvalidArgumentException;

/**
 * Every answer of one weighing, keyed by question. Read them as properties
 * ($resolved->urgency) or with get(); values() gives what Policy::apply()
 * acts on.
 */
final readonly class ResolvedAnswers
{
    /**
     * @param  array<string, Answer>  $answers  question name => answer
     */
    public function __construct(private array $answers) {}

    public function __get(string $question): Answer
    {
        return $this->get($question);
    }

    public function __isset(string $question): bool
    {
        return isset($this->answers[$question]);
    }

    public function get(string $question): Answer
    {
        if (! isset($this->answers[$question])) {
            throw new InvalidArgumentException(sprintf('No answer for question [%s]. Questions: %s.', $question, implode(', ', array_keys($this->answers))));
        }

        return $this->answers[$question];
    }

    /**
     * @return array<string, Answer>
     */
    public function all(): array
    {
        return $this->answers;
    }

    /**
     * @return array<string, Answer>
     */
    public function accepted(): array
    {
        return $this->withStatus(Status::Accepted);
    }

    /**
     * @return array<string, Answer>
     */
    public function needingReview(): array
    {
        return $this->withStatus(Status::Review);
    }

    /**
     * @return array<string, Answer>
     */
    public function withStatus(Status $status): array
    {
        return array_filter($this->answers, static fn (Answer $answer): bool => $answer->status === $status);
    }

    /**
     * Accepted values over the policy's defaults: unsure answers never
     * change anything.
     *
     * @param  array<string, mixed>  $defaults  question name => default, as PolicyRegistry::defaults() gives
     */
    public function values(array $defaults): Answers
    {
        $values = $defaults;

        foreach ($this->accepted() as $question => $answer) {
            $values[$question] = $answer->value;
        }

        return new Answers($values);
    }
}
