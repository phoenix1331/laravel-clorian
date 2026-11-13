<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Questions\Thresholds;
use Phoenix1331\Clorian\Resolution\Answer;
use Phoenix1331\Clorian\Resolution\ResolvedAnswers;
use Phoenix1331\Clorian\Resolution\Status;

function resolvedAnswer(string $question, string|bool $value, Status $status): Answer
{
    $option = is_bool($value) ? ($value ? 'true' : 'false') : $value;

    return new Answer($question, $option, $value, 0.8, [$option => 0.8], new Thresholds(0.9, 0.6), $status);
}

function resolved(): ResolvedAnswers
{
    return new ResolvedAnswers([
        'category' => resolvedAnswer('category', 'billing', Status::Accepted),
        'urgency' => resolvedAnswer('urgency', 'critical', Status::Review),
        'needs_human' => resolvedAnswer('needs_human', false, Status::Accepted),
        'language' => resolvedAnswer('language', 'cy', Status::Unresolved),
    ]);
}

it('reads answers as properties or with get()', function (): void {
    expect(resolved()->urgency->value)->toBe('critical')
        ->and(resolved()->get('category')->isAccepted())->toBeTrue()
        ->and(resolved()->get('language')->isUnresolved())->toBeTrue()
        ->and(isset(resolved()->urgency))->toBeTrue()
        ->and(isset(resolved()->urgncy))->toBeFalse()
        ->and(array_keys(resolved()->all()))->toBe(['category', 'urgency', 'needs_human', 'language']);
});

it('throws on a question with no answer', function (): void {
    expect(fn () => resolved()->urgncy)
        ->toThrow(InvalidArgumentException::class, 'No answer for question [urgncy]. Questions: category, urgency, needs_human, language.');
});

it('filters answers by status', function (): void {
    expect(array_keys(resolved()->accepted()))->toBe(['category', 'needs_human'])
        ->and(array_keys(resolved()->needingReview()))->toBe(['urgency'])
        ->and(array_keys(resolved()->withStatus(Status::Unresolved)))->toBe(['language']);
});

it('merges accepted values over the defaults, leaving unsure answers out', function (): void {
    $values = resolved()->values(['category' => null, 'urgency' => 'normal', 'needs_human' => true, 'language' => 'en']);

    expect($values->all())->toBe(['category' => 'billing', 'urgency' => 'normal', 'needs_human' => false, 'language' => 'en']);
});
