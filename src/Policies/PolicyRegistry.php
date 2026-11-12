<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Policies;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Questions\BooleanQuestion;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\Thresholds;

/**
 * Resolves policies from the container and validates each class the first
 * time it is used: questions, thresholds over the config defaults, and
 * default values. Later calls for the same class skip validation.
 *
 * @internal
 */
final class PolicyRegistry
{
    public const string DEFAULTS_KEY = 'clorian.default_thresholds';

    /** @var array<class-string<Policy>, true> */
    private array $validated = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    /**
     * @param  class-string<Policy>|string  $class
     *
     * @throws InvalidThresholds
     */
    public function get(string $class): Policy
    {
        if (! is_subclass_of($class, Policy::class)) {
            throw InvalidThresholds::forPolicy($class, sprintf('not a class extending %s', Policy::class));
        }

        $policy = $this->container->make($class);

        if (! $policy instanceof Policy) {
            throw InvalidThresholds::forPolicy($class, sprintf('the container resolved %s, not a %s', get_debug_type($policy), Policy::class));
        }

        if (! isset($this->validated[$class])) {
            $this->validate($class, $policy);
            $this->validated[$class] = true;
        }

        return $policy;
    }

    /**
     * The thresholds any question or option without its own falls back to.
     *
     * @throws InvalidThresholds when config/clorian.php holds something unusable
     */
    public function defaultThresholds(): Thresholds
    {
        $defaults = $this->config->get(self::DEFAULTS_KEY);

        if (! is_array($defaults)) {
            throw InvalidThresholds::inConfig(self::DEFAULTS_KEY, 'expected an array with accept and review');
        }

        $accept = $defaults['accept'] ?? null;
        $review = $defaults['review'] ?? null;

        if (! (is_float($accept) || is_int($accept) || $accept === null) || ! (is_float($review) || is_int($review))) {
            throw InvalidThresholds::inConfig(self::DEFAULTS_KEY, 'accept must be a number or null, review a number');
        }

        $thresholds = new Thresholds($accept === null ? null : (float) $accept, (float) $review);
        $problem = $thresholds->problem();

        if ($problem !== null) {
            throw InvalidThresholds::inConfig(self::DEFAULTS_KEY, $problem);
        }

        return $thresholds;
    }

    /**
     * Every question's default, null where the policy sets none.
     *
     * @return array<string, mixed>
     */
    public function defaults(Policy $policy): array
    {
        $defaults = [];

        foreach ($this->questions($policy) as $name => $question) {
            $defaults[$name] = $policy->defaults()[$name] ?? null;
        }

        return $defaults;
    }

    /**
     * @return array<string, Question> keyed by question name, in declared order
     */
    public function questions(Policy $policy): array
    {
        $questions = [];

        foreach ($policy->questions() as $question) {
            $questions[$question->name()] = $question;
        }

        return $questions;
    }

    /**
     * Forget which classes were validated, e.g. after changing config defaults.
     */
    public function flush(): void
    {
        $this->validated = [];
    }

    /**
     * @throws InvalidThresholds
     */
    private function validate(string $class, Policy $policy): void
    {
        $base = $this->defaultThresholds();
        $questions = $policy->questions();

        if ($questions === []) {
            throw InvalidThresholds::forPolicy($class, 'questions() returned no questions');
        }

        $names = [];

        foreach ($questions as $question) {
            if (isset($names[$question->name()])) {
                throw InvalidThresholds::forQuestion($class, $question->name(), 'the question is declared twice');
            }

            $names[$question->name()] = $question;
            $question->validate($class, $base);
        }

        foreach ($policy->defaults() as $name => $default) {
            $question = $names[$name] ?? null;

            if ($question === null) {
                throw InvalidThresholds::forQuestion($class, (string) $name, 'defaults() names a question the policy does not ask');
            }

            $this->validateDefault($class, $question, $default);
        }
    }

    private function validateDefault(string $class, Question $question, mixed $default): void
    {
        if ($default === null) {
            return;
        }

        if ($question instanceof BooleanQuestion) {
            if (! is_bool($default)) {
                throw InvalidThresholds::forQuestion($class, $question->name(), sprintf('the default must be true, false or null, got %s', get_debug_type($default)));
            }

            return;
        }

        if (! is_string($default) || ! in_array($default, $question->options(), true)) {
            throw InvalidThresholds::forQuestion($class, $question->name(), sprintf('the default must be null or one of the options (%s)', implode(', ', $question->options())));
        }
    }
}
