<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Questions\Question;

/**
 * @return array<string, Question>
 */
function fakeQuestions(): array
{
    return [
        'category' => Question::choice('category', ['billing', 'bug', 'account', 'feature']),
        'urgency' => Question::choice('urgency', ['low', 'normal', 'high', 'critical']),
        'needs_human' => Question::boolean('needs_human'),
    ];
}

function scoreWith(FakeDriver $driver): Scores
{
    return $driver->score(fakeQuestions(), ['subject' => 'Charged twice', 'body' => 'Refund please'], ['plan' => 'team']);
}

it('is named fake and reports a fake model version', function (): void {
    $scores = scoreWith(new FakeDriver);

    expect((new FakeDriver)->name())->toBe('fake')
        ->and($scores->modelVersion)->toBe('fake')
        ->and($scores->latencyMs)->toBe(0);
});

it('spreads unscripted questions evenly', function (): void {
    expect(scoreWith(new FakeDriver)->probabilities)->toBe([
        'category' => ['billing' => 0.25, 'bug' => 0.25, 'account' => 0.25, 'feature' => 0.25],
        'urgency' => ['low' => 0.25, 'normal' => 0.25, 'high' => 0.25, 'critical' => 0.25],
        'needs_human' => ['true' => 0.5, 'false' => 0.5],
    ]);
});

it('answers scripted questions and shares the rest evenly', function (): void {
    $scores = scoreWith((new FakeDriver)->answer('category', 'billing', 0.93)->answer('urgency', 'high', 0.72));

    expect($scores->for('category'))->toBe(['billing' => 0.93, 'bug' => 0.0233, 'account' => 0.0233, 'feature' => 0.0233])
        ->and($scores->top('urgency'))->toBe(['high', 0.72])
        ->and($scores->for('needs_human'))->toBe(['true' => 0.5, 'false' => 0.5]);
});

it('answers booleans with true or false', function (bool $value, array $expected): void {
    expect(scoreWith((new FakeDriver)->answer('needs_human', $value, 0.9))->for('needs_human'))->toBe($expected);
})->with([
    'yes' => [true, ['true' => 0.9, 'false' => 0.1]],
    'no' => [false, ['true' => 0.1, 'false' => 0.9]],
]);

it('scores exact distributions', function (): void {
    $scores = scoreWith((new FakeDriver)->distribution('urgency', ['low' => 0.09, 'normal' => 0.6, 'high' => 0.27, 'critical' => 0.04]));

    expect($scores->for('urgency'))->toBe(['low' => 0.09, 'normal' => 0.6, 'high' => 0.27, 'critical' => 0.04]);
});

it('lets the latest script for a question win', function (): void {
    $driver = (new FakeDriver)
        ->distribution('urgency', ['low' => 1.0, 'normal' => 0.0, 'high' => 0.0, 'critical' => 0.0])
        ->answer('urgency', 'critical', 0.86);

    expect(scoreWith($driver)->top('urgency'))->toBe(['critical', 0.86]);

    $driver->distribution('urgency', ['low' => 1.0, 'normal' => 0.0, 'high' => 0.0, 'critical' => 0.0]);

    expect(scoreWith($driver)->top('urgency'))->toBe(['low', 1.0]);
});

it('decides answers from the input with using()', function (): void {
    $driver = (new FakeDriver)
        ->answer('urgency', 'low', 0.8)
        ->using(fn (array $questions, string|array $content, array $context): array => is_array($content) && str_contains((string) $content['body'], 'Refund')
            ? ['category' => ['billing', 0.97], 'needs_human' => [true, 0.66]]
            : []);

    $scores = scoreWith($driver);

    expect($scores->top('category'))->toBe(['billing', 0.97])
        ->and($scores->top('needs_human'))->toBe(['true', 0.66])
        ->and($scores->top('urgency'))->toBe(['low', 0.8]);
});

it('is deterministic', function (): void {
    $driver = (new FakeDriver)->answer('category', 'bug', 0.61);

    expect(scoreWith($driver))->toEqual(scoreWith($driver));
});

it('rejects a value that is not an option', function (): void {
    expect(fn () => scoreWith((new FakeDriver)->answer('urgency', 'urgent', 0.9)))
        ->toThrow(InvalidArgumentException::class, '[urgent] is not an option of question [urgency]. Options: low, normal, high, critical.');
});

it('rejects a probability outside 0 to 1', function (): void {
    expect(fn () => (new FakeDriver)->answer('urgency', 'high', 1.2))
        ->toThrow(InvalidArgumentException::class, 'The probability for [urgency] must be between 0 and 1, got 1.2.');
});

it('validates hand-written distributions like any driver', function (): void {
    expect(fn () => scoreWith((new FakeDriver)->distribution('urgency', ['low' => 1.0])))
        ->toThrow(DriverException::class, 'The fake driver sent scores this package does not understand: question [urgency] scored options (low)');
});

it('gives a one-option question the probability it was told', function (): void {
    $scores = (new FakeDriver)->answer('q', 'only', 0.7)->score(['q' => Question::choice('q', ['only'])], 'x', []);

    expect($scores->for('q'))->toBe(['only' => 0.7]);
});

it('fails on demand, retryable unless told otherwise', function (): void {
    $driver = (new FakeDriver)->fail('Overloaded');

    try {
        scoreWith($driver);
        $this->fail('No exception thrown.');
    } catch (DriverException $e) {
        expect($e->getMessage())->toBe('Overloaded')->and($e->retryable)->toBeTrue();
    }

    $driver->fail(retryable: false);

    expect(fn () => scoreWith($driver))->toThrow(DriverException::class, 'The fake driver was told to fail.');
});

it('records every call, failed ones too', function (): void {
    $driver = new FakeDriver;
    $driver->score(fakeQuestions(), 'first', [], 2.0);
    $driver->fail();

    try {
        scoreWith($driver);
    } catch (DriverException) {
    }

    expect($driver->calls())->toHaveCount(2)
        ->and($driver->calls()[0])->toMatchArray(['content' => 'first', 'context' => [], 'timeout' => 2.0])
        ->and(array_keys($driver->calls()[0]['questions']))->toBe(['category', 'urgency', 'needs_human'])
        ->and($driver->calls()[1])->toMatchArray(['content' => ['subject' => 'Charged twice', 'body' => 'Refund please'], 'context' => ['plan' => 'team'], 'timeout' => null]);
});

it('forgets scripts, failures and calls on reset', function (): void {
    $driver = (new FakeDriver)->answer('category', 'bug', 0.9)->distribution('urgency', ['low' => 1.0])->using(fn (): array => [])->fail();

    $driver->reset();

    expect(scoreWith($driver)->top('category'))->toBe(['billing', 0.25])
        ->and($driver->calls())->toHaveCount(1);
});
