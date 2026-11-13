<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Phoenix1331\Clorian\Drivers\Driver;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Drivers\JevDriver;
use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Events\AnswerAccepted;
use Phoenix1331\Clorian\Events\AnswerNeedsReview;
use Phoenix1331\Clorian\Events\WeighingFailed;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Policies\Policy;
use Phoenix1331\Clorian\Policies\PolicyRegistry;
use Phoenix1331\Clorian\Resolution\Answer;
use Phoenix1331\Clorian\Resolution\Answers;
use Phoenix1331\Clorian\Resolution\ResolvedAnswers;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Resolution\ThresholdResolver;
use Phoenix1331\Clorian\Resolution\TunedThresholds;

/**
 * Weighs input against a policy: one driver call, every answer resolved
 * against its thresholds, the result stored, and the policy applied with
 * only the answers that can be trusted.
 */
final class Clorian
{
    /** The container alias the facade resolves. */
    public const string ALIAS = 'clorian';

    /** @var array<string, Closure(Container, array<mixed>): Driver> */
    private array $extensions = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly PolicyRegistry $policies,
        private readonly TunedThresholds $tuned,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Weigh now and wait for the answer, e.g. to route a contact form. Uses
     * clorian.sync_timeout; if the driver fails or is too slow, the weighing
     * is stored as failed and the policy's defaults apply.
     *
     * @param  class-string<Policy>|string  $policy
     * @param  string|array<string, mixed>  $input  a string, or field name => value
     *
     * @throws InvalidThresholds when the policy or config is invalid
     */
    public function now(string $policy, string|array $input, ?Model $subject = null): Weighing
    {
        try {
            return $this->weigh($policy, $input, $subject, $this->syncTimeout());
        } catch (DriverException $e) {
            return $this->failed($policy, $input, $subject, $e->getMessage());
        }
    }

    /**
     * Weigh, letting driver failures through so a job can retry them.
     *
     * @internal used by WeighSubject; call now() instead
     *
     * @param  string|array<string, mixed>  $input
     * @param  float|null  $timeout  seconds; null uses the driver's own setting
     *
     * @throws DriverException
     * @throws InvalidThresholds
     */
    public function weigh(string $policy, string|array $input, ?Model $subject = null, ?float $timeout = null): Weighing
    {
        $instance = $this->policies->get($policy);
        $questions = $this->policies->questions($instance);
        $driver = $this->driver();

        $scores = $driver->score($questions, $input, $instance->context($subject), $timeout);

        $resolved = (new ThresholdResolver($this->policies->defaultThresholds()))
            ->resolve($policy, $questions, $scores, $this->tuned->forPolicy($policy));

        $weighing = $this->store($policy, $driver, $input, $subject, $scores, $resolved);

        $instance->apply($subject, $resolved->values($this->policies->defaults($instance)));

        $this->announce($weighing, $resolved);

        return $weighing;
    }

    /**
     * Store a failed weighing and apply the policy's defaults.
     *
     * @internal used by now() and WeighSubject's final failure
     *
     * @param  string|array<string, mixed>  $input
     * @param  string  $error  never the content: driver messages are content-free
     */
    public function failed(string $policy, string|array $input, ?Model $subject, string $error): Weighing
    {
        $instance = $this->policies->get($policy);

        $weighing = $this->record($policy, $this->driver(), $input, $subject, [
            'status' => WeighingStatus::Failed,
            'error' => $error,
        ]);

        $instance->apply($subject, new Answers($this->policies->defaults($instance)));

        $this->events->dispatch(new WeighingFailed($weighing->id, $policy, $error, $weighing->subject_type, $weighing->subject_id));

        return $weighing;
    }

    /**
     * The hash stored as content_hash, so unchanged content is not weighed again.
     *
     * @param  string|array<string, mixed>  $input
     */
    public static function hash(string|array $input): string
    {
        return hash('sha256', (string) json_encode($input));
    }

    /**
     * The configured driver (clorian.driver), or the one named.
     *
     * @throws InvalidArgumentException for a driver nobody registered
     */
    public function driver(?string $name = null): Driver
    {
        $name ??= $this->config->get('clorian.driver');

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Set clorian.driver (CLORIAN_DRIVER) to "jev", "fake" or a driver added with Clorian::extend().');
        }

        $config = $this->config->get("clorian.drivers.{$name}");
        $config = is_array($config) ? $config : [];

        if (isset($this->extensions[$name])) {
            return ($this->extensions[$name])($this->container, $config);
        }

        return match ($name) {
            JevDriver::NAME => JevDriver::fromConfig($config),
            FakeDriver::NAME => $this->container->make(FakeDriver::class),
            default => throw new InvalidArgumentException(sprintf('No Clorian driver [%s]. Use "jev", "fake" or add one with Clorian::extend().', $name)),
        };
    }

    /**
     * Add a driver, or replace a built-in one. The closure gets the
     * container and the clorian.drivers.{name} config array.
     *
     * @param  Closure(Container, array<mixed>): Driver  $factory
     */
    public function extend(string $name, Closure $factory): self
    {
        $this->extensions[$name] = $factory;

        return $this;
    }

    private function syncTimeout(): ?float
    {
        $timeout = $this->config->get('clorian.sync_timeout');

        return is_numeric($timeout) && (float) $timeout > 0.0 ? (float) $timeout : null;
    }

    /**
     * @param  string|array<string, mixed>  $input
     */
    private function store(string $policy, Driver $driver, string|array $input, ?Model $subject, Scores $scores, ResolvedAnswers $resolved): Weighing
    {
        return (new Weighing)->getConnection()->transaction(function () use ($policy, $driver, $input, $subject, $scores, $resolved): Weighing {
            $weighing = $this->record($policy, $driver, $input, $subject, [
                'status' => WeighingStatus::Complete,
                'model_version' => $scores->modelVersion,
                'meta' => $scores->meta() === [] ? null : $scores->meta(),
            ]);

            $answers = $weighing->answers()->createMany(array_values(array_map(static fn (Answer $answer): array => [
                'question' => $answer->question,
                'value' => $answer->option,
                'confidence' => $answer->confidence,
                'scores' => $answer->scores,
                'accept_threshold' => $answer->thresholds->accept,
                'review_threshold' => $answer->thresholds->review,
                'status' => $answer->status,
            ], $resolved->all())));

            $weighing->setRelation('answers', $answers);

            return $weighing;
        });
    }

    /**
     * AnswerAccepted and AnswerNeedsReview for each answer; unresolved
     * answers are only recorded.
     */
    private function announce(Weighing $weighing, ResolvedAnswers $resolved): void
    {
        $stored = $weighing->answers->keyBy(static fn (WeighingAnswer $answer): string => $answer->question);

        foreach ($resolved->all() as $answer) {
            $row = $stored->get($answer->question);

            if (! $row instanceof WeighingAnswer || $answer->status === Status::Unresolved) {
                continue;
            }

            $event = $answer->status === Status::Accepted ? AnswerAccepted::class : AnswerNeedsReview::class;

            $this->events->dispatch(new $event(
                $weighing->id,
                $row->id,
                $weighing->policy,
                $answer->question,
                $answer->value,
                $answer->confidence,
                $weighing->subject_type,
                $weighing->subject_id,
            ));
        }
    }

    /**
     * @param  string|array<string, mixed>  $input
     * @param  array<string, mixed>  $attributes
     */
    private function record(string $policy, Driver $driver, string|array $input, ?Model $subject, array $attributes): Weighing
    {
        $weighing = new Weighing([
            'policy' => $policy,
            'driver' => $driver->name(),
            'content_hash' => self::hash($input),
            ...$attributes,
        ]);

        if ($subject !== null) {
            $weighing->subject()->associate($subject);
        }

        $weighing->save();
        $weighing->setRelation('answers', $weighing->answers()->getRelated()->newCollection());

        return $weighing;
    }
}
