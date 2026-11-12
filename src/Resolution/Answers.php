<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Resolution;

use InvalidArgumentException;

/**
 * The values Policy::apply() acts on, one per question: accepted or
 * human-reviewed answers merged over the policy's defaults. Read them as
 * properties ($answers->urgency) or with get(). Booleans are true or false.
 */
final readonly class Answers
{
    /**
     * @param  array<string, mixed>  $values  question name => value
     */
    public function __construct(private array $values) {}

    public function __get(string $question): mixed
    {
        if (! array_key_exists($question, $this->values)) {
            throw new InvalidArgumentException(sprintf('No question [%s]. Questions: %s.', $question, implode(', ', array_keys($this->values))));
        }

        return $this->values[$question];
    }

    /**
     * True when the question has a non-null value, so $answers->category ?? 'triage' works.
     */
    public function __isset(string $question): bool
    {
        return isset($this->values[$question]);
    }

    public function get(string $question, mixed $default = null): mixed
    {
        return $this->values[$question] ?? $default;
    }

    public function has(string $question): bool
    {
        return array_key_exists($question, $this->values);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
