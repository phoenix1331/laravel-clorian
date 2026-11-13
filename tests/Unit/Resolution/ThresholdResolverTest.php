<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Questions\Thresholds;
use Phoenix1331\Clorian\Resolution\Answer;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Resolution\ThresholdResolver;
use Phoenix1331\Clorian\Resolution\TunedThresholds;

function resolver(): ThresholdResolver
{
    return new ThresholdResolver(new Thresholds(0.90, 0.60));
}

/**
 * @param  array<string, float>  $probabilities
 */
function resolveOne(Question $question, array $probabilities, array $tuned = []): Answer
{
    $name = $question->name();

    return resolver()->resolve('App\Policies\Test', [$name => $question], new Scores([$name => $probabilities]), [$name => $tuned])->get($name);
}

function tuned(?float $accept, ?float $review = null): ThresholdOverrides
{
    $overrides = new ThresholdOverrides;
    $accept === null ? $overrides->alwaysReview() : $overrides->accept($accept);

    if ($review !== null) {
        $overrides->review($review);
    }

    return $overrides;
}

it('resolves each status against the thresholds, counting equality as reaching', function (float $top, Status $status): void {
    $answer = resolveOne(Question::choice('q', ['a', 'b']), ['a' => $top, 'b' => round(1 - $top, 4)]);

    expect($answer->status)->toBe($status)
        ->and($answer->option)->toBe('a')
        ->and($answer->value)->toBe('a')
        ->and($answer->confidence)->toBe(round($top, 4));
})->with([
    'well above accept' => [0.97, Status::Accepted],
    'exactly accept' => [0.90, Status::Accepted],
    'just under accept' => [0.8999, Status::Review],
    'exactly review' => [0.60, Status::Review],
    'just under review' => [0.5999, Status::Unresolved],
]);

it('compares on 4 decimals, whatever float arithmetic did upstream', function (): void {
    expect(ThresholdResolver::status(0.1 + 0.2 + 0.3, new Thresholds(0.9, 0.6)))->toBe(Status::Review)
        ->and(ThresholdResolver::status(0.89999999999, new Thresholds(0.9, 0.6)))->toBe(Status::Accepted)
        ->and(ThresholdResolver::status(0.0, new Thresholds(0.9, 0.0)))->toBe(Status::Review);
});

it('picks the top option, ties going to the first listed', function (): void {
    $answer = resolveOne(Question::choice('q', ['a', 'b', 'c']), ['a' => 0.4, 'b' => 0.4, 'c' => 0.2]);

    expect($answer->option)->toBe('a')
        ->and($answer->status)->toBe(Status::Unresolved)
        ->and($answer->scores)->toBe(['a' => 0.4, 'b' => 0.4, 'c' => 0.2])
        ->and($answer->thresholds)->toEqual(new Thresholds(0.9, 0.6));
});

it('never accepts an always-review question', function (): void {
    $answer = resolveOne(Question::choice('q', ['a', 'b'])->alwaysReview(), ['a' => 1.0, 'b' => 0.0]);

    expect($answer->status)->toBe(Status::Review)
        ->and($answer->needsReview())->toBeTrue()
        ->and($answer->thresholds->alwaysReviews())->toBeTrue();
});

it('never accepts an always-review option, but accepts its siblings', function (): void {
    $question = Question::choice('urgency', ['normal', 'critical'])->option('critical', fn (ThresholdOverrides $o) => $o->alwaysReview());

    expect(resolveOne($question, ['normal' => 0.0, 'critical' => 1.0])->status)->toBe(Status::Review)
        ->and(resolveOne($question, ['normal' => 1.0, 'critical' => 0.0])->status)->toBe(Status::Accepted);
});

it('resolves booleans symmetrically as true or false', function (float $p, bool $value, float $confidence, Status $status): void {
    $answer = resolveOne(Question::boolean('needs_human')->accept(0.70), ['true' => $p, 'false' => round(1 - $p, 4)]);

    expect($answer->value)->toBe($value)
        ->and($answer->option)->toBe($value ? 'true' : 'false')
        ->and($answer->confidence)->toBe($confidence)
        ->and($answer->status)->toBe($status);
})->with([
    'confident yes' => [0.73, true, 0.73, Status::Accepted],
    'confident no' => [0.02, false, 0.98, Status::Accepted],
    'unsure yes' => [0.65, true, 0.65, Status::Review],
    'coin toss' => [0.5, true, 0.5, Status::Unresolved],
    'unsure no' => [0.35, false, 0.65, Status::Review],
]);

it('judges a boolean on the thresholds of the value given', function (): void {
    $question = Question::boolean('refund')->accept(0.70)->option('true', fn (ThresholdOverrides $o) => $o->alwaysReview());

    expect(resolveOne($question, ['true' => 0.99, 'false' => 0.01])->status)->toBe(Status::Review)
        ->and(resolveOne($question, ['true' => 0.01, 'false' => 0.99])->status)->toBe(Status::Accepted);
});

it('applies each level of precedence over the one below', function (string $option, array $tuned, ?float $accept, float $review): void {
    $question = Question::choice('q', ['a', 'b', 'c'])->accept(0.80)->review(0.50)
        ->option('a', fn (ThresholdOverrides $o) => $o->accept(0.70));

    expect(resolver()->thresholdsFor('App\Policies\Test', $question, $option, $tuned))->toEqual(new Thresholds($accept, $review));
})->with([
    'policy question over config' => ['b', [], 0.80, 0.50],
    'policy option over policy question' => ['a', [], 0.70, 0.50],
    'tuned question over policy option' => ['a', ['' => tuned(0.65)], 0.65, 0.50],
    'tuned option over tuned question' => ['a', ['' => tuned(0.65), 'a' => tuned(0.60, 0.40)], 0.60, 0.40],
    'tuned option only touches its option' => ['b', ['a' => tuned(0.60, 0.40)], 0.80, 0.50],
    'tuned review alone keeps the accept below' => ['a', ['a' => tuned(0.70, 0.30)], 0.70, 0.30],
    'tuned null accept means always review' => ['c', ['' => tuned(null)], null, 0.50],
    'a tuned option can restore auto-accept' => ['b', ['' => tuned(null), 'b' => tuned(0.95)], 0.95, 0.50],
]);

it('falls back to the config defaults when nothing else is set', function (): void {
    expect(resolver()->thresholdsFor('App\Policies\Test', Question::choice('q', ['a', 'b']), 'a'))->toEqual(new Thresholds(0.90, 0.60));
});

it('uses the tuned thresholds when resolving', function (): void {
    $answer = resolveOne(Question::choice('q', ['a', 'b']), ['a' => 0.86, 'b' => 0.14], [TunedThresholds::WHOLE_QUESTION => tuned(0.85)]);

    expect($answer->status)->toBe(Status::Accepted)
        ->and($answer->thresholds)->toEqual(new Thresholds(0.85, 0.60));

    $answer = resolveOne(Question::choice('q', ['a', 'b']), ['a' => 1.0, 'b' => 0.0], ['a' => tuned(null)]);

    expect($answer->status)->toBe(Status::Review);
});

it('rejects tuning that crosses the thresholds below it', function (): void {
    expect(fn () => resolveOne(Question::choice('q', ['a', 'b']), ['a' => 1.0, 'b' => 0.0], ['a' => tuned(null, 1.5)]))
        ->toThrow(InvalidThresholds::class, 'with tuned thresholds, review must be between 0 and 1, got 1.5');

    expect(fn () => resolveOne(Question::choice('q', ['a', 'b']), ['a' => 1.0, 'b' => 0.0], ['' => tuned(null, 0.95), 'a' => tuned(0.90)]))
        ->toThrow(InvalidThresholds::class, 'review (0.95) must be lower than accept (0.9)');
});

it('resolves every question of a policy from one set of scores', function (): void {
    $questions = [
        'category' => Question::choice('category', ['billing', 'bug', 'account', 'feature']),
        'urgency' => Question::choice('urgency', ['low', 'normal', 'high', 'critical'])->option('critical', fn (ThresholdOverrides $o) => $o->alwaysReview()),
        'needs_human' => Question::boolean('needs_human')->accept(0.70),
    ];

    $resolved = resolver()->resolve('App\Policies\TicketTriage', $questions, new Scores([
        'category' => ['billing' => 0.0, 'bug' => 0.36, 'account' => 0.64, 'feature' => 0.0],
        'urgency' => ['low' => 0.0, 'normal' => 0.01, 'high' => 0.13, 'critical' => 0.86],
        'needs_human' => ['true' => 0.95, 'false' => 0.05],
    ]));

    expect(array_map(static fn (Answer $a): array => [$a->value, $a->status], $resolved->all()))->toBe([
        'category' => ['account', Status::Review],
        'urgency' => ['critical', Status::Review],
        'needs_human' => [true, Status::Accepted],
    ]);
});
