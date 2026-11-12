<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Phoenix1331\Clorian\Database\Factories\WeighingAnswerFactory;
use Phoenix1331\Clorian\Models\TunedThreshold;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;

function ticket(): Ticket
{
    return Ticket::query()->create(['subject' => 'Charged twice', 'body' => 'My card was charged twice in March.']);
}

describe('Weighing', function (): void {
    it('casts its status, meta and input', function (): void {
        $weighing = Weighing::factory()->withInput(['content' => ['body' => 'Refund please']])->create()->fresh();

        expect($weighing?->status)->toBe(WeighingStatus::Complete)
            ->and($weighing?->meta)->toHaveKey('latency_ms')
            ->and($weighing?->input)->toBe(['content' => ['body' => 'Refund please']]);
    });

    it('stores input encrypted, never as readable text', function (): void {
        Weighing::factory()->withInput(['content' => ['body' => 'Refund please']])->create();

        $raw = DB::table('clorian_weighings')->value('input');

        expect($raw)->toBeString()
            ->not->toContain('Refund please')
            ->and(json_decode(decrypt($raw, false), true))->toBe(['content' => ['body' => 'Refund please']]);
    });

    it('belongs to its subject through a morph', function (): void {
        $ticket = ticket();

        $weighing = Weighing::factory()->forSubject($ticket)->create();

        expect($weighing->subject)->toBeInstanceOf(Ticket::class)
            ->and($weighing->subject?->getKey())->toBe($ticket->getKey());
    });

    it('has no subject when weighed without one', function (): void {
        expect(Weighing::factory()->create()->subject)->toBeNull();
    });

    it('has many answers', function (): void {
        $weighing = Weighing::factory()->create();
        WeighingAnswer::factory()->count(3)->for($weighing)->create();

        expect($weighing->answers)->toHaveCount(3)
            ->each->toBeInstanceOf(WeighingAnswer::class);
    });

    it('records failures without a model version', function (): void {
        $weighing = Weighing::factory()->failed('timed out')->create();

        expect($weighing->status)->toBe(WeighingStatus::Failed)
            ->and($weighing->error)->toBe('timed out')
            ->and($weighing->model_version)->toBeNull();
    });

    it('can be created for a named policy', function (): void {
        expect(Weighing::factory()->policy('App\\Clorian\\ContactIntent')->create()->policy)->toBe('App\\Clorian\\ContactIntent');
    });
});

describe('WeighingAnswer', function (): void {
    it('casts confidence, scores, thresholds and status', function (): void {
        $answer = WeighingAnswer::factory()->create()->fresh();

        expect($answer?->confidence)->toBe(0.95)
            ->and($answer?->scores)->toBe(['billing' => 0.95, 'bug' => 0.0167, 'account' => 0.0167, 'feature' => 0.0167])
            ->and($answer?->accept_threshold)->toBe(0.9)
            ->and($answer?->review_threshold)->toBe(0.6)
            ->and($answer?->status)->toBe(Status::Accepted)
            ->and($answer?->review_action)->toBeNull()
            ->and($answer?->isReviewed())->toBeFalse();
    });

    it('stores a null accept threshold for always-review options', function (): void {
        $answer = WeighingAnswer::factory()->needsReview()->create(['accept_threshold' => null])->fresh();

        expect($answer?->accept_threshold)->toBeNull();
    });

    it('has a state for every status', function (Closure $state, Status $status): void {
        expect($state(WeighingAnswer::factory())->create()->status)->toBe($status);
    })->with([
        'accepted' => [fn (WeighingAnswerFactory $factory) => $factory->accepted(), Status::Accepted],
        'review' => [fn (WeighingAnswerFactory $factory) => $factory->needsReview(), Status::Review],
        'unresolved' => [fn (WeighingAnswerFactory $factory) => $factory->unresolved(), Status::Unresolved],
    ]);

    it('records a review with its reviewer', function (): void {
        $reviewer = ticket();

        $answer = WeighingAnswer::factory()->needsReview()->reviewed(ReviewAction::Overturned, 'billing', $reviewer)->create()->fresh();

        expect($answer?->review_action)->toBe(ReviewAction::Overturned)
            ->and($answer?->reviewed_value)->toBe('billing')
            ->and($answer?->reviewed_at)->toBeInstanceOf(Carbon::class)
            ->and($answer?->reviewer)->toBeInstanceOf(Ticket::class)
            ->and($answer?->isReviewed())->toBeTrue();
    });

    it('keeps the answered value when a review upholds it', function (): void {
        $answer = WeighingAnswer::factory()->needsReview()->reviewed()->create();

        expect($answer->review_action)->toBe(ReviewAction::Upheld)
            ->and($answer->reviewed_value)->toBe('bug');
    });

    it('builds scores for any question', function (): void {
        $answer = WeighingAnswer::factory()->answering('needs_human', 'true', 0.9, ['true', 'false'])->create();

        expect($answer->question)->toBe('needs_human')
            ->and($answer->scores)->toBe(['true' => 0.9, 'false' => 0.1]);
    });

    it('gives a one-option question all the probability', function (): void {
        expect(WeighingAnswerFactory::scores('only', 1.0, ['only']))->toBe(['only' => 1.0]);
    });
});

describe('TunedThreshold', function (): void {
    it('reads the whole question as a null option', function (): void {
        $threshold = TunedThreshold::factory()->create()->fresh();

        expect($threshold?->option)->toBeNull()
            ->and($threshold?->accept)->toBe(0.85)
            ->and($threshold?->review)->toBe(0.55);
    });

    it('keeps a named option', function (): void {
        expect(TunedThreshold::factory()->forOption('critical')->create()->fresh()?->option)->toBe('critical');
    });

    it('stores always review as a null accept', function (): void {
        expect(TunedThreshold::factory()->alwaysReview()->create()->fresh()?->accept)->toBeNull();
    });

    it('records who tuned it', function (): void {
        $by = ticket();

        $threshold = TunedThreshold::factory()->create(['tuned_by_type' => $by->getMorphClass(), 'tuned_by_id' => $by->getKey()]);

        expect($threshold->tunedBy)->toBeInstanceOf(Ticket::class);
    });
});
