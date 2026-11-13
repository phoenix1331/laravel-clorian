<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Questions\Question;

/**
 * @return array<string, Question>
 */
function triageQuestions(): array
{
    return [
        'category' => Question::choice('category', ['billing', 'bug', 'account', 'feature']),
        'needs_human' => Question::boolean('needs_human'),
    ];
}

function borderline(): Scores
{
    return new Scores([
        'category' => ['billing' => 0.03, 'bug' => 0.89, 'account' => 0.06, 'feature' => 0.02],
        'needs_human' => ['true' => 0.73, 'false' => 0.27],
    ], 'jev-1.14.0', 170, 452, 107, 'req_123');
}

it('reads one question\'s scores', function (): void {
    expect(borderline()->for('category'))->toBe(['billing' => 0.03, 'bug' => 0.89, 'account' => 0.06, 'feature' => 0.02]);
});

it('throws for a question it has no scores for', function (): void {
    expect(fn () => borderline()->for('urgency'))->toThrow(InvalidArgumentException::class, 'No scores for question [urgency].');
});

it('finds the top option', function (): void {
    expect(borderline()->top('category'))->toBe(['bug', 0.89])
        ->and(borderline()->top('needs_human'))->toBe(['true', 0.73]);
});

it('breaks ties in favour of the option listed first', function (): void {
    $scores = new Scores(['q' => ['true' => 0.5, 'false' => 0.5], 'r' => ['b' => 0.4, 'a' => 0.4, 'c' => 0.2]]);

    expect($scores->top('q'))->toBe(['true', 0.5])
        ->and($scores->top('r'))->toBe(['b', 0.4]);
});

it('throws when a question has no options scored', function (): void {
    expect(fn () => (new Scores(['q' => []]))->top('q'))->toThrow(InvalidArgumentException::class, 'No options scored for question [q].');
});

it('reports call metadata without nulls', function (): void {
    expect(borderline()->meta())->toBe(['latency_ms' => 170, 'input_tokens' => 452, 'output_tokens' => 107, 'request_id' => 'req_123'])
        ->and((new Scores([], latencyMs: 0))->meta())->toBe(['latency_ms' => 0]);
});

it('accepts scores that cover every question and option', function (): void {
    borderline()->assertCovers(triageQuestions(), 'jev');
})->throwsNoExceptions();

it('ignores scores for questions that were not asked', function (): void {
    borderline()->assertCovers(['needs_human' => Question::boolean('needs_human')], 'jev');
})->throwsNoExceptions();

it('rejects scores that miss a question', function (): void {
    $scores = new Scores(['category' => borderline()->for('category')], requestId: 'req_9');

    try {
        $scores->assertCovers(triageQuestions(), 'jev');
        $this->fail('No exception thrown.');
    } catch (DriverException $e) {
        expect($e->getMessage())->toBe('The jev driver sent scores this package does not understand: no scores for question [needs_human].')
            ->and($e->retryable)->toBeFalse()
            ->and($e->requestId)->toBe('req_9');
    }
});

it('rejects scores with missing or unknown options', function (array $options, string $given): void {
    $scores = new Scores(['category' => $options, 'needs_human' => ['true' => 1.0, 'false' => 0.0]]);

    expect(fn () => $scores->assertCovers(triageQuestions(), 'jev'))
        ->toThrow(DriverException::class, "question [category] scored options ({$given}), expected (account, billing, bug, feature)");
})->with([
    'missing' => [['billing' => 0.5, 'bug' => 0.5], 'billing, bug'],
    'unknown' => [['billing' => 0.25, 'bug' => 0.25, 'account' => 0.25, 'feature' => 0.2, 'sales' => 0.05], 'account, billing, bug, feature, sales'],
]);

it('rejects probabilities outside 0 to 1', function (float $probability, string $shown): void {
    $scores = new Scores(['needs_human' => ['true' => $probability, 'false' => 0.5]]);

    expect(fn () => $scores->assertCovers(['needs_human' => Question::boolean('needs_human')], 'jev'))
        ->toThrow(DriverException::class, "question [needs_human] option [true] has probability {$shown}, outside 0 to 1");
})->with([
    'below 0' => [-0.01, '-0.01'],
    'above 1' => [1.01, '1.01'],
    'NAN, without coercing it to a string (a PHP 8.5 warning)' => [NAN, 'NAN'],
]);
