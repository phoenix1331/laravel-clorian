<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Events\AnswerReviewed;
use Phoenix1331\Clorian\Exceptions\InvalidReview;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Reviews\ReviewAction;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Reviewer;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\ContactIntent;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

beforeEach(function (): void {
    config()->set('clorian.driver', 'fake');
});

function makeReviewer(string $name = 'Sam'): Reviewer
{
    return Reviewer::query()->create(['name' => $name]);
}

/**
 * A weighed ticket: category accepted (billing), urgency in review (critical
 * always reviews, so priority keeps the default "normal"), needs_human
 * unresolved (default false).
 *
 * @return array{0: Ticket, 1: Weighing}
 */
function reviewableTicket(): array
{
    app(FakeDriver::class)
        ->answer('category', 'billing', 0.95)
        ->answer('urgency', 'critical', 0.97)
        ->answer('needs_human', true, 0.55);

    $ticket = Ticket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);

    return [$ticket, Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket)];
}

function reviewAnswer(Weighing $weighing, string $question): WeighingAnswer
{
    return $weighing->answers()->where('question', $question)->sole();
}

it('upholds an answer and applies it', function (): void {
    [$ticket, $weighing] = reviewableTicket();
    $by = makeReviewer();

    expect($ticket->refresh()->priority)->toBe('normal');

    $answer = Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Upheld, null, $by);

    expect($answer->review_action)->toBe(ReviewAction::Upheld)
        ->and($answer->reviewed_value)->toBe('critical')
        ->and($answer->reviewer?->is($by))->toBeTrue()
        ->and($answer->reviewed_at)->not->toBeNull()
        ->and($answer->refresh()->reviewed_value)->toBe('critical')
        ->and($ticket->refresh()->priority)->toBe('critical')
        ->and($ticket->queue)->toBe('billing')
        ->and($weighing->refresh()->accepted('urgency', 'critical'))->toBeTrue();
});

it('upholds with the same value given', function (): void {
    [, $weighing] = reviewableTicket();

    $answer = Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Upheld, 'critical', makeReviewer());

    expect($answer->reviewed_value)->toBe('critical');
});

it('overturns an answer and applies the human value', function (): void {
    [$ticket, $weighing] = reviewableTicket();

    Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Overturned, 'high', makeReviewer());

    expect(reviewAnswer($weighing, 'urgency')->reviewed_value)->toBe('high')
        ->and($ticket->refresh()->priority)->toBe('high');
});

it('overturns an accepted answer too', function (): void {
    [$ticket, $weighing] = reviewableTicket();

    Clorian::review(reviewAnswer($weighing, 'category'), ReviewAction::Overturned, 'account', makeReviewer());

    expect($ticket->refresh()->queue)->toBe('account')
        ->and($ticket->priority)->toBe('normal');
});

it('fills in an unresolved boolean answer', function (): void {
    [$ticket, $weighing] = reviewableTicket();

    expect($ticket->refresh()->needs_human)->toBeFalse();

    Clorian::review(reviewAnswer($weighing, 'needs_human'), ReviewAction::Upheld, true, makeReviewer());

    expect(reviewAnswer($weighing, 'needs_human')->reviewed_value)->toBe('true')
        ->and($ticket->refresh()->needs_human)->toBeTrue();
});

it('marks an answer missed, so the default applies', function (): void {
    [$ticket, $weighing] = reviewableTicket();

    Clorian::review(reviewAnswer($weighing, 'category'), ReviewAction::Missed, null, makeReviewer());

    expect(reviewAnswer($weighing, 'category')->review_action)->toBe(ReviewAction::Missed)
        ->and(reviewAnswer($weighing, 'category')->reviewed_value)->toBeNull()
        ->and($ticket->refresh()->queue)->toBe('triage')
        ->and($weighing->refresh()->accepted('category', 'billing'))->toBeFalse();
});

it('keeps every human decision when re-applying', function (): void {
    [$ticket, $weighing] = reviewableTicket();

    Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Overturned, 'low', makeReviewer());
    Clorian::review(reviewAnswer($weighing, 'category'), ReviewAction::Overturned, 'bug', makeReviewer());

    expect($ticket->refresh()->priority)->toBe('low')
        ->and($ticket->queue)->toBe('bug');
});

it('replaces an earlier review', function (): void {
    [$ticket, $weighing] = reviewableTicket();
    $second = makeReviewer('Alex');

    Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Overturned, 'high', makeReviewer());
    $this->travel(5)->minutes();
    Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Upheld, null, $second);

    $answer = reviewAnswer($weighing, 'urgency');

    expect($answer->review_action)->toBe(ReviewAction::Upheld)
        ->and($answer->reviewed_value)->toBe('critical')
        ->and($answer->reviewer?->is($second))->toBeTrue()
        ->and($answer->reviewed_at?->greaterThan($answer->created_at))->toBeTrue()
        ->and($ticket->refresh()->priority)->toBe('critical');
});

it('rejects a review that does not fit', function (string $question, ReviewAction $action, string|bool|null $value, string $message): void {
    [$ticket, $weighing] = reviewableTicket();
    $answer = reviewAnswer($weighing, $question);

    expect(fn () => Clorian::review($answer, $action, $value, makeReviewer()))->toThrow(InvalidReview::class, $message);

    expect($answer->refresh()->review_action)->toBeNull()
        ->and($ticket->refresh()->priority)->toBe('normal');
})->with([
    'not an option' => ['urgency', ReviewAction::Overturned, 'urgent', '[urgent] is not an option of [urgency]. Options: low, normal, high, critical.'],
    'boolean not an option' => ['category', ReviewAction::Overturned, true, '[true] is not an option of [category].'],
    'overturned without a value' => ['urgency', ReviewAction::Overturned, null, 'Overturning [urgency] needs the right value.'],
    'overturned to the same value' => ['urgency', ReviewAction::Overturned, 'critical', 'Overturning [urgency] to [critical] keeps the same value; uphold it instead.'],
    'upheld with another value' => ['urgency', ReviewAction::Upheld, 'low', 'Upholding [urgency] keeps its value [critical]; to choose [low], overturn it instead.'],
    'missed with a value' => ['urgency', ReviewAction::Missed, 'low', 'Marking [urgency] missed means no option fits'],
    'boolean as a string' => ['needs_human', ReviewAction::Overturned, 'yes', '[yes] is not an option of [needs_human]. Options: true, false.'],
]);

it('rejects an answer to a question the policy no longer asks', function (): void {
    $weighing = Weighing::factory()->create(['policy' => TicketTriage::class]);
    $answer = WeighingAnswer::factory()->for($weighing)->create(['question' => 'mood', 'value' => 'calm']);

    expect(fn () => Clorian::review($answer, ReviewAction::Upheld, null, makeReviewer()))
        ->toThrow(InvalidReview::class, TicketTriage::class.' no longer asks [mood], so the answer cannot be reviewed.');
});

it('ignores stored answers to questions the policy dropped when re-applying', function (): void {
    [$ticket, $weighing] = reviewableTicket();
    WeighingAnswer::factory()->for($weighing)->create(['question' => 'mood', 'value' => 'calm']);

    Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Upheld, null, makeReviewer());

    expect($ticket->refresh()->priority)->toBe('critical');
});

it('fires AnswerReviewed with ids and scalars', function (): void {
    [$ticket, $weighing] = reviewableTicket();
    $by = makeReviewer();
    Event::fake([AnswerReviewed::class]);

    $answer = Clorian::review(reviewAnswer($weighing, 'needs_human'), ReviewAction::Overturned, false, $by);

    Event::assertDispatchedTimes(AnswerReviewed::class, 1);
    Event::assertDispatched(AnswerReviewed::class, fn (AnswerReviewed $e): bool => $e == new AnswerReviewed(
        $weighing->id, $answer->id, TicketTriage::class, 'needs_human', ReviewAction::Overturned, true, false,
        $by->getMorphClass(), $by->id, $ticket->getMorphClass(), $ticket->id,
    ));
});

it('fires nothing for a rejected review', function (): void {
    [, $weighing] = reviewableTicket();
    Event::fake([AnswerReviewed::class]);

    rescue(fn () => Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Overturned, 'urgent', makeReviewer()), report: false);

    Event::assertNotDispatched(AnswerReviewed::class);
});

it('accepts a reviewer that is not an Eloquent model', function (): void {
    [, $weighing] = reviewableTicket();

    // An integer id: reviewer_id follows the app's morph key type (bigint by default).
    $answer = Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Upheld, null, new GenericUser(['id' => 7]));

    expect($answer->reviewer_type)->toBe(GenericUser::class)
        ->and($answer->refresh()->reviewer_id)->toBe(7);
});

it('stores no reviewer id it cannot read', function (): void {
    [, $weighing] = reviewableTicket();

    $answer = Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Upheld, null, new GenericUser(['id' => ['odd']]));

    expect($answer->reviewer_id)->toBeNull();
});

it('reviews subjectless weighings, applying with no subject', function (): void {
    app(FakeDriver::class)->answer('intent', 'sales', 0.7);
    $weighing = Clorian::now(ContactIntent::class, 'How much is the pro plan?');

    $answer = Clorian::review(reviewAnswer($weighing, 'intent'), ReviewAction::Overturned, 'billing', makeReviewer());

    expect($answer->reviewed_value)->toBe('billing')
        ->and($weighing->refresh()->accepted('intent', 'billing'))->toBeTrue();
});

it('records the review but skips apply() when the subject is gone', function (): void {
    [$ticket, $weighing] = reviewableTicket();
    $ticket->delete();

    $answer = Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Overturned, 'high', makeReviewer());

    expect($answer->refresh()->reviewed_value)->toBe('high')
        ->and(Ticket::query()->count())->toBe(0);
});

it('leaves the model out of the pending review scope once reviewed', function (): void {
    [$ticket, $weighing] = reviewableTicket();

    expect(Ticket::pendingReview()->pluck('id')->all())->toBe([$ticket->id]);

    Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Upheld, null, makeReviewer());

    expect(Ticket::pendingReview()->count())->toBe(0);
});

it('reviews through the fake too', function (): void {
    Clorian::fake()->answer('urgency', 'critical', 0.97);
    $ticket = Ticket::query()->create(['subject' => 'Down', 'body' => 'Site is down']);
    $weighing = Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    Clorian::review(reviewAnswer($weighing, 'urgency'), ReviewAction::Upheld, null, makeReviewer());

    expect($ticket->refresh()->priority)->toBe('critical');
});
