<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Questions;

use Phoenix1331\Clorian\Exceptions\InvalidThresholds;

/**
 * Pick exactly one of 2 to 255 options. Judged on the top option's
 * probability.
 */
final class ChoiceQuestion extends Question
{
    /** @var list<string> */
    private readonly array $options;

    /** @var array<string, string|null> */
    private readonly array $descriptions;

    /**
     * @param  array<int|string, string|null>  $options  a list of names, or name => description
     */
    public function __construct(string $name, array $options)
    {
        parent::__construct($name);

        if (array_is_list($options)) {
            $names = array_map(static fn (?string $option): string => (string) $option, $options);
            $this->options = $names;
            $this->descriptions = array_fill_keys($names, null);

            return;
        }

        $names = [];
        $descriptions = [];

        foreach ($options as $option => $description) {
            // Collected separately: PHP turns numeric string keys back into integers.
            $names[] = (string) $option;
            $descriptions[(string) $option] = $description === '' ? null : $description;
        }

        $this->options = $names;
        $this->descriptions = $descriptions;
    }

    public function options(): array
    {
        return $this->options;
    }

    public function descriptions(): array
    {
        return $this->descriptions;
    }

    protected function validateOptions(string $policy): void
    {
        $count = count($this->options);

        if ($count < self::MIN_OPTIONS || $count > self::MAX_OPTIONS) {
            throw InvalidThresholds::forQuestion($policy, $this->name(), sprintf('a choice needs %d to %d options, got %d', self::MIN_OPTIONS, self::MAX_OPTIONS, $count));
        }

        if (count(array_unique($this->options)) !== $count) {
            throw InvalidThresholds::forQuestion($policy, $this->name(), 'options must be unique');
        }

        foreach ($this->options as $option) {
            if (trim($option) === '') {
                throw InvalidThresholds::forQuestion($policy, $this->name(), 'an option name is empty');
            }

            if (mb_strlen($option) > self::MAX_OPTION_LENGTH) {
                throw InvalidThresholds::forOption($policy, $this->name(), $option, sprintf('the option name is longer than %d characters', self::MAX_OPTION_LENGTH));
            }
        }
    }
}
