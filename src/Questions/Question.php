<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Questions;

use Phoenix1331\Clorian\Exceptions\InvalidThresholds;

/**
 * A typed question a policy asks about its input, with the thresholds its
 * answer is judged against. Build one with Question::choice() or
 * Question::boolean(); anything not set falls back to config defaults.
 */
abstract class Question
{
    /** Fits clorian_answers.question. */
    public const int MAX_NAME_LENGTH = 100;

    /** Fits clorian_answers.value. */
    public const int MAX_OPTION_LENGTH = 191;

    public const int MIN_OPTIONS = 2;

    /** Jev's limit per choice question. */
    public const int MAX_OPTIONS = 255;

    protected ?string $instructions = null;

    private readonly ThresholdOverrides $overrides;

    /** @var array<string, ThresholdOverrides> */
    private array $optionOverrides = [];

    protected function __construct(private readonly string $name)
    {
        $this->overrides = new ThresholdOverrides;
    }

    /**
     * Pick one option. Pass a list of names, or a map of name to description:
     * descriptions are sent to the model and are the main accuracy lever.
     *
     * @param  array<int|string, string|null>  $options
     */
    public static function choice(string $name, array $options): ChoiceQuestion
    {
        return new ChoiceQuestion($name, $options);
    }

    /**
     * A yes or no question, answered "true" or "false". The instructions
     * default to the name in words ("needs_human" asks "Needs human").
     */
    public static function boolean(string $name, ?string $instructions = null): BooleanQuestion
    {
        return new BooleanQuestion($name, $instructions);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * What the model should decide. Reference input parts in backticks:
     * `content`, `context`.
     */
    public function instructions(string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function accept(float $accept): static
    {
        $this->overrides->accept($accept);

        return $this;
    }

    public function review(float $review): static
    {
        $this->overrides->review($review);

        return $this;
    }

    public function alwaysReview(): static
    {
        $this->overrides->alwaysReview();

        return $this;
    }

    /**
     * Thresholds for one option, over the question's own:
     * ->option('critical', fn ($o) => $o->alwaysReview()).
     *
     * @param  callable(ThresholdOverrides): mixed  $configure
     */
    public function option(string $option, callable $configure): static
    {
        $configure($this->optionOverrides[$option] ??= new ThresholdOverrides);

        return $this;
    }

    /**
     * The option names an answer can take, in the order sent to the model.
     *
     * @return list<string>
     */
    abstract public function options(): array;

    /**
     * Option name to description (null: the name alone), as sent to the model.
     *
     * @return array<string, string|null>
     */
    abstract public function descriptions(): array;

    /**
     * The thresholds this question sets for an option, over the given base
     * (config defaults). Tuned values from the database go on top of these.
     */
    public function thresholdsFor(string $option, Thresholds $base): Thresholds
    {
        $thresholds = $base->with($this->overrides);

        if (isset($this->optionOverrides[$option])) {
            return $thresholds->with($this->optionOverrides[$option]);
        }

        return $thresholds;
    }

    /**
     * @throws InvalidThresholds naming the policy, question and option at fault
     */
    public function validate(string $policy, Thresholds $base): void
    {
        $this->validateName($policy);
        $this->validateOptions($policy);

        $problem = $base->with($this->overrides)->problem();

        if ($problem !== null) {
            throw InvalidThresholds::forQuestion($policy, $this->name, $problem);
        }

        foreach (array_keys($this->optionOverrides) as $option) {
            if (! in_array($option, $this->options(), true)) {
                throw InvalidThresholds::forOption($policy, $this->name, $option, sprintf('not one of the options (%s)', implode(', ', $this->options())));
            }

            $problem = $this->thresholdsFor($option, $base)->problem();

            if ($problem !== null) {
                throw InvalidThresholds::forOption($policy, $this->name, $option, $problem);
            }
        }
    }

    /**
     * @throws InvalidThresholds
     */
    protected function validateOptions(string $policy): void {}

    private function validateName(string $policy): void
    {
        if (trim($this->name) === '') {
            throw InvalidThresholds::forQuestion($policy, $this->name, 'the question name is empty');
        }

        if (mb_strlen($this->name) > self::MAX_NAME_LENGTH) {
            throw InvalidThresholds::forQuestion($policy, $this->name, sprintf('the question name is longer than %d characters', self::MAX_NAME_LENGTH));
        }
    }
}
