<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Exceptions\MissingWeighAttribute;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Note;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

function weighedTicket(string $subject = 'Charged twice'): Ticket
{
    return Ticket::query()->create(['subject' => $subject, 'body' => 'Refund please']);
}

/**
 * @param  array<string, Status|array{0: Status, 1: ReviewAction}>  $answers  question => status, or [status, review action]
 */
function weighingOf(Ticket $ticket, array $answers): Weighing
{
    $weighing = Weighing::factory()->forSubject($ticket)->create();

    foreach ($answers as $question => $status) {
        [$status, $action] = is_array($status) ? $status : [$status, null];

        WeighingAnswer::factory()->for($weighing)->create([
            'question' => $question,
            'status' => $status,
            'review_action' => $action,
            'reviewed_at' => $action === null ? null : now(),
        ]);
    }

    return $weighing;
}

it('relates a model to its weighings and the latest one', function (): void {
    $ticket = weighedTicket();
    $first = weighingOf($ticket, []);
    $second = weighingOf($ticket, []);
    weighingOf(weighedTicket('Other'), []);

    expect($ticket->weighings()->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($ticket->latestWeighing?->is($second))->toBeTrue()
        ->and(weighedTicket('New')->latestWeighing)->toBeNull();
});

it('finds the latest answer to a question for this model only', function (): void {
    $ticket = weighedTicket();
    weighingOf($ticket, ['urgency' => Status::Review]);
    $latest = weighingOf($ticket, ['urgency' => Status::Accepted, 'category' => Status::Unresolved]);
    weighingOf(weighedTicket('Other'), ['urgency' => Status::Review]);

    expect($ticket->answer('urgency')?->weighing_id)->toBe($latest->id)
        ->and($ticket->answer('urgency')?->status)->toBe(Status::Accepted)
        ->and($ticket->answer('language'))->toBeNull()
        ->and(weighedTicket('New')->answer('urgency'))->toBeNull();
});

it('weighs on demand with the #[Weigh] policy and fields', function (): void {
    $ticket = weighedTicket();
    $fake = Clorian::fake()->answer('category', 'billing', 0.95);

    $weighing = $ticket->weighNow();

    expect($weighing->subject?->is($ticket))->toBeTrue()
        ->and($weighing->policy)->toBe(TicketTriage::class)
        ->and($ticket->weighPolicy())->toBe(TicketTriage::class)
        ->and($ticket->refresh()->queue)->toBe('billing')
        ->and(app(FakeDriver::class)->calls()[0]['content'])
        ->toBe(['subject' => 'Charged twice', 'body' => 'Refund please']);

    $fake->assertWeighed(TicketTriage::class, $ticket);
});

it('sends plain values for enums, dates and stringables', function (): void {
    $ticket = (new Ticket)->forceFill([
        'subject' => Str::of('Charged twice'),
        'body' => Carbon::parse('2026-11-13 09:30:00', 'UTC'),
    ]);

    expect($ticket->weighInput())->toBe(['subject' => 'Charged twice', 'body' => '2026-11-13T09:30:00+00:00']);

    $ticket->forceFill(['subject' => Status::Review, 'body' => WeighFixtureUnit::Alpha]);

    expect($ticket->weighInput())->toBe(['subject' => 'review', 'body' => 'Alpha']);
});

it('lists only models whose latest weighing has an answer waiting for review', function (): void {
    $waiting = weighedTicket('waiting');
    weighingOf($waiting, ['category' => Status::Accepted, 'urgency' => Status::Review]);

    $reviewed = weighedTicket('reviewed');
    weighingOf($reviewed, ['urgency' => [Status::Review, ReviewAction::Upheld]]);

    $accepted = weighedTicket('accepted');
    weighingOf($accepted, ['urgency' => Status::Accepted, 'category' => Status::Unresolved]);

    $superseded = weighedTicket('superseded');
    weighingOf($superseded, ['urgency' => Status::Review]);
    weighingOf($superseded, ['urgency' => Status::Accepted]);

    $requeued = weighedTicket('requeued');
    weighingOf($requeued, ['urgency' => Status::Accepted]);
    weighingOf($requeued, ['urgency' => Status::Review]);

    weighedTicket('never weighed');

    expect(Ticket::query()->pendingReview()->orderBy('id')->pluck('subject')->all())->toBe(['waiting', 'requeued']);
});

it('explains a missing #[Weigh] attribute', function (Closure $call): void {
    $note = new Note(['subject' => 'x', 'body' => 'y']);

    expect(fn () => $call($note))->toThrow(MissingWeighAttribute::class, Note::class.' uses HasWeighings but has no #[Weigh] attribute.');
})->with([
    'weighNow' => [fn (Note $note) => $note->weighNow()],
    'weighInput' => [fn (Note $note) => $note->weighInput()],
    'weighPolicy' => [fn (Note $note) => $note->weighPolicy()],
]);

enum WeighFixtureUnit
{
    case Alpha;
}
