<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Phoenix1331\Clorian\Calibration\Calibrator;
use Phoenix1331\Clorian\Calibration\Observation;
use Phoenix1331\Clorian\Calibration\Observations;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;
use Phoenix1331\Clorian\Tuning\EloquentObservations;

/**
 * @return list<Observation>
 */
function readObservations(?string $policy = null, ?DateTimeInterface $since = null): array
{
    $observations = [];

    foreach (app(Observations::class)->read($policy, $since) as $observation) {
        $observations[] = $observation;
    }

    return $observations;
}

it('is bound to the database reader', function (): void {
    expect(app(Observations::class))->toBeInstanceOf(EloquentObservations::class);
});

it('reads every answer with its policy and review', function (): void {
    $weighing = Weighing::factory()->policy('App\Policies\Triage')->create();
    WeighingAnswer::factory()->for($weighing)->accepted()->create();
    WeighingAnswer::factory()->for($weighing)->answering('urgency', 'high', 0.72, ['low', 'high'])
        ->state(['status' => Status::Review])->reviewed(ReviewAction::Overturned, 'low')->create();

    expect(readObservations())->toEqual([
        new Observation('App\Policies\Triage', 'category', 'billing', 0.95, Status::Accepted),
        new Observation('App\Policies\Triage', 'urgency', 'high', 0.72, Status::Review, ReviewAction::Overturned),
    ]);
});

it('filters by policy and by time', function (): void {
    $old = Weighing::factory()->policy('App\Policies\Triage')->create();
    WeighingAnswer::factory()->for($old)->create(['created_at' => now()->subDays(40)]);
    WeighingAnswer::factory()->for(Weighing::factory()->policy('App\Policies\Triage'))->create();
    WeighingAnswer::factory()->for(Weighing::factory()->policy('App\Policies\Abuse'))->create();

    expect(readObservations())->toHaveCount(3)
        ->and(readObservations('App\Policies\Triage'))->toHaveCount(2)
        ->and(readObservations('App\Policies\Abuse'))->toHaveCount(1)
        ->and(readObservations(since: now()->subDays(30)))->toHaveCount(2)
        ->and(readObservations('App\Policies\Triage', now()->subDays(30)))->toHaveCount(1)
        ->and(readObservations('App\Policies\Unknown'))->toBe([]);
});

it('skips rows it cannot read', function (): void {
    WeighingAnswer::factory()->create();
    WeighingAnswer::factory()->create(['value' => null]);
    $odd = WeighingAnswer::factory()->create();
    DB::table('clorian_answers')->where('id', $odd->id)->update(['status' => 'pending', 'review_action' => 'shrugged']);
    $unknownReview = WeighingAnswer::factory()->create();
    DB::table('clorian_answers')->where('id', $unknownReview->id)->update(['review_action' => 'shrugged']);

    $observations = readObservations();

    expect($observations)->toHaveCount(2)
        ->and($observations[1]->review)->toBeNull();
});

it('reads past one chunk without losing or repeating rows', function (): void {
    $weighing = Weighing::factory()->create();
    WeighingAnswer::factory()->for($weighing)->count(EloquentObservations::CHUNK + 5)->create();

    expect(readObservations())->toHaveCount(EloquentObservations::CHUNK + 5);
});

it('feeds the calibrator end to end', function (): void {
    $weighing = Weighing::factory()->policy('App\Policies\Triage')->create();
    WeighingAnswer::factory()->for($weighing)->count(30)->accepted()->reviewed()->create();

    $stats = app(Calibrator::class)->calibrate(app(Observations::class)->read('App\Policies\Triage'));

    expect($stats)->toHaveCount(1)
        ->and($stats[0]->accepted)->toBe(30)
        ->and($stats[0]->reviews->upheld)->toBe(30)
        ->and($stats[0]->suggestedAccept)->toBe(0.9);
});

it('builds the calibrator from config', function (): void {
    expect(app(Calibrator::class)->target)->toBe(0.95)
        ->and(app(Calibrator::class)->minSample)->toBe(30);

    config()->set('clorian.calibration', ['target' => '0.9', 'min_sample' => '5']);

    expect(app(Calibrator::class)->target)->toBe(0.9)
        ->and(app(Calibrator::class)->minSample)->toBe(5);

    config()->set('clorian.calibration', ['target' => 'high', 'min_sample' => null]);

    expect(app(Calibrator::class)->target)->toBe(Calibrator::DEFAULT_TARGET)
        ->and(app(Calibrator::class)->minSample)->toBe(Calibrator::DEFAULT_MIN_SAMPLE);

    config()->set('clorian.calibration.target', 2);

    expect(fn () => app(Calibrator::class))->toThrow(InvalidArgumentException::class, 'The calibration target must be above 0 and at most 1, got 2.');
});
