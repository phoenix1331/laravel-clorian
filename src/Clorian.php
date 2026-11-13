<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Phoenix1331\Clorian\Drivers\Driver;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Drivers\JevDriver;
use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Events\AnswerAccepted;
use Phoenix1331\Clorian\Events\AnswerNeedsReview;
use Phoenix1331\Clorian\Events\AnswerReviewed;
use Phoenix1331\Clorian\Events\WeighingFailed;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Exceptions\InvalidReview;
use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Policies\Policy;
use Phoenix1331\Clorian\Policies\PolicyRegistry;
use Phoenix1331\Clorian\Questions\BooleanQuestion;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Redaction\InputStorage;
use Phoenix1331\Clorian\Resolution\Answer;
use Phoenix1331\Clorian\Resolution\Answers;
use Phoenix1331\Clorian\Resolution\ResolvedAnswers;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Resolution\ThresholdResolver;
use Phoenix1331\Clorian\Resolution\TunedThresholds;
use Phoenix1331\Clorian\Reviews\ReviewAction;

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
        $storage = $this->inputStorage();
        $driver = $this->driver();
        $content = $this->redact($instance, $input);

        $scores = $driver->score($questions, $content, $instance->redact($instance->context($subject)), $timeout);

        $resolved = (new ThresholdResolver($this->policies->defaultThresholds()))
            ->resolve($policy, $questions, $scores, $this->tuned->forPolicy($policy));

        $weighing = $this->store($policy, $driver, $input, $subject, $scores, $resolved, [
            'input' => $storage->stores($subject !== null) ? self::snapshot($content) : null,
        ]);

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
        $stores = $this->inputStorage()->stores($subject !== null);

        $weighing = $this->record($policy, $this->driver(), $input, $subject, [
            'status' => WeighingStatus::Failed,
            'error' => $error,
            'input' => $stores ? self::snapshot($this->redact($instance, $input)) : null,
        ]);

        $instance->apply($subject, new Answers($this->policies->defaults($instance)));

        $this->events()->dispatch(new WeighingFailed($weighing->id, $policy, $error, $weighing->subject_type, $weighing->subject_id));

        return $weighing;
    }

    /**
     * Record a human decision on an answer, then run the policy's apply()
     * again with every reviewed value in place of what Jev said. Reviewing
     * again replaces the earlier decision.
     *
     * - Upheld: the answer was right; $value is null or the same value.
     * - Overturned: $value is the right option.
     * - Missed: no option fits; $value is null and the default applies.
     *
     * @param  string|bool|null  $value  an option, or true/false for a boolean question
     *
     * @throws InvalidReview when the value does not fit the action or the question
     * @throws InvalidThresholds when the weighing's policy is no longer valid
     */
    public function review(WeighingAnswer $answer, ReviewAction $action, string|bool|null $value, Authenticatable $by): WeighingAnswer
    {
        $weighing = $answer->weighing;
        $instance = $this->policies->get($weighing->policy);
        $question = $this->policies->questions($instance)[$answer->question] ?? throw InvalidReview::unknownQuestion($weighing->policy, $answer->question);

        $answer->forceFill([
            'review_action' => $action,
            'reviewed_value' => self::reviewedOption($question, $answer, $action, $value),
            'reviewer_type' => $by instanceof Model ? $by->getMorphClass() : $by::class,
            'reviewer_id' => self::identifier($by),
            'reviewed_at' => $answer->freshTimestamp(),
        ])->save();

        $this->reapply($weighing, $instance);

        $this->events()->dispatch(new AnswerReviewed(
            $weighing->id,
            $answer->id,
            $weighing->policy,
            $answer->question,
            $action,
            self::valueOf($question, $answer->value),
            self::valueOf($question, $answer->reviewed_value),
            $answer->reviewer_type,
            $answer->reviewer_id,
            $weighing->subject_type,
            $weighing->subject_id,
        ));

        return $answer;
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

    /**
     * The stored option for a review, checked against the action and the question.
     *
     * @throws InvalidReview
     */
    private static function reviewedOption(Question $question, WeighingAnswer $answer, ReviewAction $action, string|bool|null $value): ?string
    {
        $name = $answer->question;
        $original = (string) $answer->value;
        $option = is_bool($value) ? ($value ? BooleanQuestion::TRUE : BooleanQuestion::FALSE) : $value;

        if ($option !== null && ! in_array($option, $question->options(), true)) {
            throw InvalidReview::notAnOption($name, $option, $question->options());
        }

        return match ($action) {
            ReviewAction::Upheld => $option === null || $option === $original ? $original : throw InvalidReview::upheldWithOtherValue($name, $original, $option),
            ReviewAction::Overturned => match ($option) {
                null => throw InvalidReview::overturnedWithoutValue($name),
                $original => throw InvalidReview::overturnedToSameValue($name, $original),
                default => $option,
            },
            ReviewAction::Missed => $option === null ? null : throw InvalidReview::missedWithValue($name),
        };
    }

    /**
     * apply() again for a reviewed weighing: reviewed values first, then
     * accepted answers, then defaults. Skipped when the subject is gone.
     */
    private function reapply(Weighing $weighing, Policy $policy): void
    {
        $subject = $weighing->subject;

        if ($weighing->subject_type !== null && ! $subject instanceof Model) {
            return;
        }

        $questions = $this->policies->questions($policy);
        $values = $this->policies->defaults($policy);

        foreach ($weighing->answers()->get() as $answer) {
            $question = $questions[$answer->question] ?? null;

            if (! $question instanceof Question) {
                continue;
            }

            $option = match (true) {
                $answer->isReviewed() => $answer->reviewed_value,
                $answer->status === Status::Accepted => $answer->value,
                default => null,
            };

            if ($option !== null) {
                $values[$answer->question] = self::valueOf($question, $option);
            }
        }

        $policy->apply($subject, new Answers($values));
    }

    /**
     * A stored option as apply() and events see it: true/false for booleans.
     */
    private static function valueOf(Question $question, ?string $option): string|bool|null
    {
        if ($option === null || ! $question instanceof BooleanQuestion) {
            return $option;
        }

        return $option === BooleanQuestion::TRUE;
    }

    private static function identifier(Authenticatable $by): int|string|null
    {
        $id = $by->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * Resolved per dispatch, not injected: Event::fake() swaps the dispatcher
     * after this singleton exists.
     */
    private function events(): Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }

    private function inputStorage(): InputStorage
    {
        return InputStorage::fromConfig($this->config->get('clorian.store_input'));
    }

    /**
     * Policy::redact() works on arrays, so a string travels as ['content' => string].
     *
     * @param  string|array<string, mixed>  $input
     * @return string|array<string, mixed>
     *
     * @throws LogicException when redact() loses a string input
     */
    private function redact(Policy $policy, string|array $input): string|array
    {
        if (is_array($input)) {
            return $policy->redact($input);
        }

        $redacted = $policy->redact(['content' => $input])['content'] ?? null;

        if (! is_string($redacted)) {
            throw new LogicException(sprintf('%s::redact() must return "content" as a string when the input is a string.', $policy::class));
        }

        return $redacted;
    }

    /**
     * The redacted input as stored: arrays as they are, a string as ['content' => string].
     *
     * @param  string|array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function snapshot(string|array $content): array
    {
        return is_string($content) ? ['content' => $content] : $content;
    }

    private function syncTimeout(): ?float
    {
        $timeout = $this->config->get('clorian.sync_timeout');

        return is_numeric($timeout) && (float) $timeout > 0.0 ? (float) $timeout : null;
    }

    /**
     * @param  string|array<string, mixed>  $input
     * @param  array<string, mixed>  $attributes
     */
    private function store(string $policy, Driver $driver, string|array $input, ?Model $subject, Scores $scores, ResolvedAnswers $resolved, array $attributes): Weighing
    {
        return (new Weighing)->getConnection()->transaction(function () use ($policy, $driver, $input, $subject, $scores, $resolved, $attributes): Weighing {
            $weighing = $this->record($policy, $driver, $input, $subject, [
                ...$attributes,
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

            $this->events()->dispatch(new $event(
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
