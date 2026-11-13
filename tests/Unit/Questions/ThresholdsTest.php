<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Questions\Thresholds;

it('rounds to the database precision', function (): void {
    $thresholds = new Thresholds(0.123456, 0.0000449);

    expect($thresholds->accept)->toBe(0.1235)
        ->and($thresholds->review)->toBe(0.0);
});

it('treats a null accept as always review', function (): void {
    expect(Thresholds::alwaysReview(0.6))
        ->accept->toBeNull()
        ->review->toBe(0.6)
        ->alwaysReviews()->toBeTrue()
        ->and((new Thresholds(0.9, 0.6))->alwaysReviews())->toBeFalse();
});

it('accepts valid thresholds', function (?float $accept, float $review): void {
    expect((new Thresholds($accept, $review))->problem())->toBeNull();
})->with([
    'defaults' => [0.9, 0.6],
    'full range' => [1.0, 0.0],
    'always review' => [null, 0.6],
    'always review from zero' => [null, 0.0],
]);

it('explains invalid thresholds', function (?float $accept, float $review, string $problem): void {
    expect((new Thresholds($accept, $review))->problem())->toBe($problem);
})->with([
    'review equal to accept' => [0.8, 0.8, 'review (0.8) must be lower than accept (0.8)'],
    'review above accept' => [0.7, 0.75, 'review (0.75) must be lower than accept (0.7)'],
    'accept above 1' => [1.2, 0.6, 'accept must be between 0 and 1, got 1.2'],
    'accept below 0' => [-0.1, 0.0, 'accept must be between 0 and 1, got -0.1'],
    'review above 1' => [null, 1.5, 'review must be between 0 and 1, got 1.5'],
    'review below 0' => [0.9, -0.25, 'review must be between 0 and 1, got -0.25'],
    'accept NAN' => [NAN, 0.6, 'accept must be between 0 and 1, got NAN'],
    'review NAN' => [0.9, NAN, 'review must be between 0 and 1, got NAN'],
]);

it('layers overrides over the values below', function (): void {
    $base = new Thresholds(0.9, 0.6);

    expect($base->with(new ThresholdOverrides))->toEqual($base)
        ->and($base->with((new ThresholdOverrides)->accept(0.75)))->toEqual(new Thresholds(0.75, 0.6))
        ->and($base->with((new ThresholdOverrides)->review(0.5)))->toEqual(new Thresholds(0.9, 0.5))
        ->and($base->with((new ThresholdOverrides)->alwaysReview()))->toEqual(Thresholds::alwaysReview(0.6))
        ->and(Thresholds::alwaysReview(0.6)->with((new ThresholdOverrides)->accept(0.8)))->toEqual(new Thresholds(0.8, 0.6));
});

it('reports what an override sets', function (): void {
    expect((new ThresholdOverrides)->setsAnything())->toBeFalse()
        ->and((new ThresholdOverrides)->review(0.5)->setsAnything())->toBeTrue()
        ->and((new ThresholdOverrides)->alwaysReview())
        ->setsAccept()->toBeTrue()
        ->acceptValue()->toBeNull()
        ->setsAnything()->toBeTrue();
});
