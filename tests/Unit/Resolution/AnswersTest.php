<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Resolution\Answers;

function answers(): Answers
{
    return new Answers(['category' => null, 'urgency' => 'high', 'needs_human' => false]);
}

it('reads values as properties', function (): void {
    expect(answers()->urgency)->toBe('high')
        ->and(answers()->needs_human)->toBeFalse()
        ->and(answers()->category)->toBeNull();
});

it('supports a fallback for null values with ??', function (): void {
    expect(answers()->category ?? 'triage')->toBe('triage')
        ->and(answers()->urgency ?? 'normal')->toBe('high')
        ->and(isset(answers()->needs_human))->toBeTrue();
});

it('throws on a question the policy does not ask, so typos fail loudly', function (): void {
    expect(fn () => answers()->urgncy)
        ->toThrow(InvalidArgumentException::class, 'No question [urgncy]. Questions: category, urgency, needs_human.');
});

it('reads values with get(), has() and all()', function (): void {
    expect(answers()->get('urgency'))->toBe('high')
        ->and(answers()->get('category', 'triage'))->toBe('triage')
        ->and(answers()->get('missing', 'x'))->toBe('x')
        ->and(answers()->has('category'))->toBeTrue()
        ->and(answers()->has('missing'))->toBeFalse()
        ->and(answers()->all())->toBe(['category' => null, 'urgency' => 'high', 'needs_human' => false]);
});
