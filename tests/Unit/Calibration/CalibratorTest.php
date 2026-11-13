<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Calibration\Band;
use Phoenix1331\Clorian\Calibration\Calibrator;
use Phoenix1331\Clorian\Calibration\Observation;
use Phoenix1331\Clorian\Calibration\OptionStats;
use Phoenix1331\Clorian\Calibration\ReviewCounts;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;

/**
 * $count answers for category = billing at one confidence.
 *
 * @return list<Observation>
 */
function observed(int $count, float $confidence, ?ReviewAction $review = null, Status $status = Status::Review, string $option = 'billing', string $question = 'category', string $policy = 'App\Policies\Triage'): array
{
    return array_fill(0, $count, new Observation($policy, $question, $option, $confidence, $status, $review));
}

/**
 * @param  list<Observation>  $observations
 */
function calibrateOne(array $observations, ?Calibrator $calibrator = null): OptionStats
{
    $stats = ($calibrator ?? new Calibrator)->calibrate($observations);

    expect($stats)->toHaveCount(1);

    return $stats[0];
}

/**
 * Band label => answers in it.
 *
 * @return array<string, int>
 */
function bandAnswers(OptionStats $stats): array
{
    return array_combine(
        array_map(static fn (Band $band): string => $band->label(), $stats->bands),
        array_map(static fn (Band $band): int => $band->answers, $stats->bands),
    );
}

it('counts answers by status', function (): void {
    $stats = calibrateOne([
        ...observed(3, 0.95, status: Status::Accepted),
        ...observed(2, 0.72, status: Status::Review),
        ...observed(1, 0.40, status: Status::Unresolved),
    ]);

    expect($stats->policy)->toBe('App\Policies\Triage')
        ->and($stats->question)->toBe('category')
        ->and($stats->option)->toBe('billing')
        ->and($stats->accepted)->toBe(3)
        ->and($stats->review)->toBe(2)
        ->and($stats->unresolved)->toBe(1)
        ->and($stats->total())->toBe(6);
});

it('rates reviews, with missed counting against upheld', function (): void {
    $stats = calibrateOne([
        ...observed(6, 0.8, ReviewAction::Upheld),
        ...observed(3, 0.8, ReviewAction::Overturned),
        ...observed(1, 0.8, ReviewAction::Missed),
        ...observed(5, 0.8),
    ]);

    expect($stats->reviews)->toEqual(new ReviewCounts(6, 3, 1))
        ->and($stats->reviews->reviewed())->toBe(10)
        ->and($stats->reviews->upheldRate())->toBe(0.6)
        ->and($stats->reviews->overturnedRate())->toBe(0.3)
        ->and($stats->reviews->missedRate())->toBe(0.1);
});

it('has no rates without reviews', function (): void {
    $reviews = calibrateOne(observed(4, 0.8))->reviews;

    expect($reviews->reviewed())->toBe(0)
        ->and($reviews->upheldRate())->toBeNull()
        ->and($reviews->overturnedRate())->toBeNull()
        ->and($reviews->missedRate())->toBeNull();
});

it('puts each confidence in the right band', function (float $confidence, ?string $band): void {
    $answers = bandAnswers(calibrateOne(observed(1, $confidence)));

    expect(array_keys($answers))->toBe(['0.60-0.70', '0.70-0.80', '0.80-0.90', '0.90+'])
        ->and(array_keys(array_filter($answers)))->toBe($band === null ? [] : [$band]);
})->with([
    'below the lowest band' => [0.5999, null],
    'lowest edge' => [0.6, '0.60-0.70'],
    'just below 0.7' => [0.6999, '0.60-0.70'],
    '0.7 exactly' => [0.7, '0.70-0.80'],
    '0.7 with float noise below' => [0.7 - 1e-12, '0.70-0.80'],
    '0.7 with float noise above' => [0.7 + 1e-12, '0.70-0.80'],
    '0.8 exactly' => [0.8, '0.80-0.90'],
    'just below 0.9' => [0.8999, '0.80-0.90'],
    '0.9 exactly' => [0.9, '0.90+'],
    'certain' => [1.0, '0.90+'],
]);

it('rates each band from its own reviews', function (): void {
    $stats = calibrateOne([
        ...observed(4, 0.65, ReviewAction::Overturned),
        ...observed(9, 0.95, ReviewAction::Upheld),
        ...observed(1, 0.95, ReviewAction::Missed),
    ]);

    [$low, , , $top] = $stats->bands;

    expect($low->from)->toBe(0.6)
        ->and($low->to)->toBe(0.7)
        ->and($low->reviews->overturnedRate())->toBe(1.0)
        ->and($top->from)->toBe(0.9)
        ->and($top->to)->toBeNull()
        ->and($top->answers)->toBe(10)
        ->and($top->reviews->upheldRate())->toBe(0.9)
        ->and($stats->bands[1]->reviews->upheldRate())->toBeNull();
});

it('groups by policy, question and option, in order', function (): void {
    $stats = (new Calibrator)->calibrate([
        ...observed(1, 0.9, option: 'bug'),
        ...observed(1, 0.9, option: 'billing'),
        ...observed(1, 0.9, option: 'high', question: 'urgency'),
        ...observed(2, 0.9, option: 'billing', policy: 'App\Policies\Abuse'),
    ]);

    expect(array_map(static fn (OptionStats $s): string => "{$s->policy} {$s->question} {$s->option} {$s->total()}", $stats))->toBe([
        'App\Policies\Abuse category billing 2',
        'App\Policies\Triage category billing 1',
        'App\Policies\Triage category bug 1',
        'App\Policies\Triage urgency high 1',
    ]);
});

it('reads any iterable, a generator included', function (): void {
    $generator = (static function (): Generator {
        yield from observed(2, 0.9);
    })();

    expect((new Calibrator)->calibrate($generator)[0]->total())->toBe(2)
        ->and((new Calibrator)->calibrate([]))->toBe([]);
});

it('suggests nothing under the minimum sample', function (): void {
    expect(calibrateOne(observed(29, 0.95, ReviewAction::Upheld))->suggestedAccept)->toBeNull()
        ->and(calibrateOne(observed(30, 0.95, ReviewAction::Upheld))->suggestedAccept)->toBe(0.9);
});

it('counts only reviewed answers towards the sample', function (): void {
    expect(calibrateOne([...observed(29, 0.95, ReviewAction::Upheld), ...observed(50, 0.95)])->suggestedAccept)->toBeNull();
});

it('suggests the lowest qualifying band', function (): void {
    $stats = calibrateOne([
        ...observed(40, 0.95, ReviewAction::Upheld),
        ...observed(40, 0.85, ReviewAction::Upheld),
        ...observed(30, 0.75, ReviewAction::Upheld),
        ...observed(10, 0.75, ReviewAction::Overturned),
    ]);

    // The 0.70 band is upheld 75% of the time on its own, under 95%.
    expect($stats->suggestedAccept)->toBe(0.8);
});

it('pools reviews above an edge, so a small band can still qualify', function (): void {
    $stats = calibrateOne([
        ...observed(25, 0.85, ReviewAction::Upheld),
        ...observed(10, 0.75, ReviewAction::Upheld),
    ]);

    // 25 above 0.80 is under the sample; 35 above 0.70 is enough.
    expect($stats->suggestedAccept)->toBe(0.7);
});

it('will not suggest an edge that would also accept a weak band above it', function (): void {
    $stats = calibrateOne([
        ...observed(20, 0.95, ReviewAction::Upheld),
        ...observed(20, 0.95, ReviewAction::Overturned),
        ...observed(100, 0.85, ReviewAction::Upheld),
    ]);

    // From 0.80: 120 of 140 (86%); from 0.90: 20 of 40.
    expect($stats->suggestedAccept)->toBeNull();
});

it('counts reaching the target exactly', function (): void {
    $calibrator = new Calibrator(target: 0.95, minSample: 20);

    expect(calibrateOne([...observed(19, 0.95, ReviewAction::Upheld), ...observed(1, 0.95, ReviewAction::Missed)], $calibrator)->suggestedAccept)->toBe(0.9)
        ->and(calibrateOne([...observed(18, 0.95, ReviewAction::Upheld), ...observed(1, 0.95, ReviewAction::Missed)], $calibrator)->suggestedAccept)->toBeNull();
});

it('stops at a weak band, however strong the bands around it', function (): void {
    $stats = calibrateOne([
        ...observed(100, 0.95, ReviewAction::Upheld),
        ...observed(5, 0.85, ReviewAction::Upheld),
        ...observed(5, 0.85, ReviewAction::Overturned),
        ...observed(100, 0.75, ReviewAction::Upheld),
    ]);

    // From 0.70 the pool is 205 of 210 (98%), but 0.80-0.90 is upheld half the time.
    expect($stats->suggestedAccept)->toBe(0.9);
});

it('never suggests a band with no reviews of its own', function (): void {
    $stats = calibrateOne([...observed(40, 0.95, ReviewAction::Upheld), ...observed(20, 0.65)]);

    expect($stats->suggestedAccept)->toBe(0.9);
});

it('ignores reviews below the lowest band when suggesting', function (): void {
    expect(calibrateOne(observed(100, 0.5, ReviewAction::Upheld))->suggestedAccept)->toBeNull();
});

it('takes its own target, sample and bands', function (): void {
    $calibrator = new Calibrator(target: 0.8, minSample: 5, bands: [0.5, 0.75]);
    $stats = calibrateOne([...observed(4, 0.6, ReviewAction::Upheld), ...observed(1, 0.6, ReviewAction::Overturned)], $calibrator);

    expect(array_keys(bandAnswers($stats)))->toBe(['0.50-0.75', '0.75+'])
        ->and($stats->suggestedAccept)->toBe(0.5);
});

it('rejects settings it cannot use', function (float $target, int $minSample, array $bands, string $message): void {
    expect(fn () => new Calibrator($target, $minSample, $bands))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero target' => [0.0, 30, Calibrator::DEFAULT_BANDS, 'The calibration target must be above 0 and at most 1, got 0.'],
    'target over 1' => [1.5, 30, Calibrator::DEFAULT_BANDS, 'got 1.5'],
    'no sample' => [0.95, 0, Calibrator::DEFAULT_BANDS, 'The calibration min_sample must be at least 1, got 0.'],
    'no bands' => [0.95, 30, [], 'Calibration bands must be ascending'],
    'descending' => [0.95, 30, [0.8, 0.6], 'Calibration bands must be ascending'],
    'duplicate' => [0.95, 30, [0.6, 0.6], 'Calibration bands must be ascending'],
    'negative' => [0.95, 30, [-0.1, 0.5], 'Calibration bands must be ascending'],
    'one' => [0.95, 30, [0.5, 1.0], 'Calibration bands must be ascending'],
]);

it('keeps its results immutable', function (): void {
    foreach ([Calibrator::class, Observation::class, OptionStats::class, Band::class, ReviewCounts::class] as $class) {
        expect((new ReflectionClass($class))->isReadOnly())->toBeTrue()
            ->and((new ReflectionClass($class))->isFinal())->toBeTrue();
    }
});
