<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Clorian;
use Phoenix1331\Clorian\Drivers\Driver;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Policies\Policy;
use PHPUnit\Framework\Assert;

/**
 * Swapped in by Clorian::fake(). Weighings run for real (resolution,
 * storage, apply()) against a scripted FakeDriver, and every weighing,
 * through the facade, a job or the container, is recorded for assertions.
 */
final class ClorianFake
{
    /** @var list<Weighing> */
    private array $weighings = [];

    public function __construct(
        private readonly Clorian $manager,
        private readonly FakeDriver $driver,
    ) {}

    /**
     * @param  class-string<Policy>|string  $policy
     * @param  string|array<string, mixed>  $input
     */
    public function now(string $policy, string|array $input, ?Model $subject = null): Weighing
    {
        return $this->manager->now($policy, $input, $subject);
    }

    public function driver(?string $name = null): Driver
    {
        return $this->manager->driver($name);
    }

    /**
     * @param  Closure(Container, array<mixed>): Driver  $factory
     */
    public function extend(string $name, Closure $factory): self
    {
        $this->manager->extend($name, $factory);

        return $this;
    }

    /**
     * Answer a question with a value at a probability; the other options
     * share the rest. Booleans take true or false.
     */
    public function answer(string $question, string|bool $value, float $probability): self
    {
        $this->driver->answer($question, $value, $probability);

        return $this;
    }

    /**
     * @param  array<string, float>  $probabilities  option => probability
     */
    public function distribution(string $question, array $probabilities): self
    {
        $this->driver->distribution($question, $probabilities);

        return $this;
    }

    /**
     * Make the driver fail, e.g. to test the fallback to defaults.
     */
    public function fail(string $message = 'The fake driver was told to fail.', bool $retryable = true): self
    {
        $this->driver->fail($message, $retryable);

        return $this;
    }

    /**
     * Assert the policy was weighed at least once, optionally for a subject
     * or where the callback returns true.
     *
     * @param  class-string<Policy>|string  $policy
     * @param  (Closure(Weighing): bool)|Model|null  $filter
     */
    public function assertWeighed(string $policy, Closure|Model|null $filter = null): void
    {
        Assert::assertNotEmpty($this->weighed($policy, $filter), "The policy [{$policy}] was not weighed".self::describe($filter).'.');
    }

    /**
     * @param  class-string<Policy>|string  $policy
     * @param  (Closure(Weighing): bool)|Model|null  $filter
     */
    public function assertNotWeighed(string $policy, Closure|Model|null $filter = null): void
    {
        Assert::assertEmpty($this->weighed($policy, $filter), "The policy [{$policy}] was weighed".self::describe($filter).' unexpectedly.');
    }

    /**
     * @param  class-string<Policy>|string  $policy
     * @param  (Closure(Weighing): bool)|Model|null  $filter
     */
    public function assertWeighedTimes(string $policy, int $times, Closure|Model|null $filter = null): void
    {
        $actual = count($this->weighed($policy, $filter));

        Assert::assertSame($times, $actual, "Expected the policy [{$policy}] to be weighed {$times} times".self::describe($filter).", weighed {$actual}.");
    }

    public function assertNothingWeighed(): void
    {
        $policies = array_unique(array_map(static fn (Weighing $weighing): string => $weighing->policy, $this->weighings));

        Assert::assertEmpty($this->weighings, 'Weighings were made unexpectedly: '.implode(', ', $policies).'.');
    }

    /**
     * Every weighing since fake(), oldest first, optionally for one policy.
     *
     * @param  class-string<Policy>|string|null  $policy
     * @return list<Weighing>
     */
    public function weighings(?string $policy = null): array
    {
        return $policy === null ? $this->weighings : $this->weighed($policy);
    }

    /**
     * @internal called for each new weighing
     */
    public function record(Weighing $weighing): void
    {
        $this->weighings[] = $weighing;
    }

    /**
     * @param  (Closure(Weighing): bool)|Model|null  $filter
     * @return list<Weighing>
     */
    private function weighed(string $policy, Closure|Model|null $filter = null): array
    {
        return array_values(array_filter($this->weighings, static fn (Weighing $weighing): bool => $weighing->policy === $policy && match (true) {
            $filter === null => true,
            $filter instanceof Model => self::isSubject($weighing, $filter),
            default => $filter($weighing) === true,
        }));
    }

    private static function isSubject(Weighing $weighing, Model $subject): bool
    {
        $key = $subject->getKey();

        return $weighing->subject_type === $subject->getMorphClass() && is_scalar($key) && (string) $weighing->subject_id === (string) $key;
    }

    private static function describe(Closure|Model|null $filter): string
    {
        return match (true) {
            $filter === null => '',
            $filter instanceof Model => sprintf(' for [%s #%s]', $filter::class, is_scalar($filter->getKey()) ? $filter->getKey() : '?'),
            default => ' matching the callback',
        };
    }
}
