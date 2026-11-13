<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Events\AnswerAccepted;
use Phoenix1331\Clorian\Events\AnswerNeedsReview;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Jobs\WeighSubject;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Note;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Reviewer;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Models\UntimedTicket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

beforeEach(function (): void {
    config()->set('clorian.driver', 'fake');
});

function rescanTicket(string $body = 'Refund please'): Ticket
{
    return Ticket::query()->create(['subject' => 'Charged twice', 'body' => $body]);
}

function scriptRescan(string $urgency = 'critical', float $probability = 0.97): void
{
    app(FakeDriver::class)
        ->answer('category', 'billing', 0.95)
        ->answer('urgency', $urgency, $probability)
        ->answer('needs_human', false, 0.9);
}

function latestAnswer(Ticket $ticket, string $question): WeighingAnswer
{
    $answer = $ticket->answer($question);

    expect($answer)->toBeInstanceOf(WeighingAnswer::class);

    return $answer instanceof WeighingAnswer ? $answer : new WeighingAnswer;
}

it('queues every model by default', function (): void {
    Queue::fake();
    config()->set('clorian.queue', 'clorian');
    rescanTicket();
    rescanTicket('Second');

    $this->artisan('clorian:rescan', ['model' => Ticket::class])
        ->expectsOutputToContain('Queued 2 Ticket for weighing.')
        ->assertSuccessful();

    Queue::assertPushedOn('clorian', WeighSubject::class);
    Queue::assertPushed(WeighSubject::class, 2);
});

it('weighs inline with --sync, and again even when nothing changed', function (): void {
    scriptRescan();
    $ticket = rescanTicket();
    Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    $this->artisan('clorian:rescan', ['model' => Ticket::class, '--sync' => true])
        ->expectsOutputToContain('Weighed 1 Ticket.')
        ->assertSuccessful();

    expect(Weighing::query()->count())->toBe(2);
});

it('weighs inline when clorian.mode is sync', function (): void {
    config()->set('clorian.mode', 'sync');
    rescanTicket();

    $this->artisan('clorian:rescan', ['model' => Ticket::class])->expectsOutputToContain('Weighed 1 Ticket.')->assertSuccessful();
});

it('keeps human decisions on unchanged content', function (): void {
    scriptRescan();
    $ticket = rescanTicket();
    $weighing = Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);
    $by = Reviewer::query()->create(['name' => 'Sam']);
    Clorian::review($weighing->answers->firstWhere('question', 'urgency') ?? new WeighingAnswer, ReviewAction::Overturned, 'high', $by);
    Clorian::review($weighing->answers->firstWhere('question', 'category') ?? new WeighingAnswer, ReviewAction::Missed, null, $by);

    expect($ticket->refresh()->priority)->toBe('high')
        ->and($ticket->queue)->toBe('triage');

    // Jev now says normal for urgency: the reviewer's "high" still stands.
    scriptRescan('normal', 0.95);
    Event::fake([AnswerAccepted::class, AnswerNeedsReview::class]);

    $this->artisan('clorian:rescan', ['model' => Ticket::class, '--sync' => true])->assertSuccessful();

    $urgency = latestAnswer($ticket, 'urgency');
    $category = latestAnswer($ticket, 'category');

    expect($urgency->weighing_id)->not->toBe($weighing->id)
        ->and($urgency->value)->toBe('normal')
        ->and($urgency->review_action)->toBe(ReviewAction::Overturned)
        ->and($urgency->reviewed_value)->toBe('high')
        ->and($urgency->reviewer?->is($by))->toBeTrue()
        ->and($urgency->reviewed_at?->equalTo($weighing->answers->firstWhere('question', 'urgency')?->refresh()->reviewed_at))->toBeTrue()
        ->and($category->review_action)->toBe(ReviewAction::Missed)
        ->and($category->reviewed_value)->toBeNull()
        ->and(latestAnswer($ticket, 'needs_human')->isReviewed())->toBeFalse()
        ->and($ticket->refresh()->priority)->toBe('high')
        ->and($ticket->queue)->toBe('triage')
        ->and(Ticket::pendingReview()->count())->toBe(0);

    // Only needs_human is news.
    Event::assertDispatchedTimes(AnswerAccepted::class, 1);
    Event::assertNotDispatched(AnswerNeedsReview::class);
});

it('marks a carried decision upheld when the new answer agrees with it', function (): void {
    scriptRescan();
    $ticket = rescanTicket();
    $weighing = Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);
    Clorian::review($weighing->answers->firstWhere('question', 'urgency') ?? new WeighingAnswer, ReviewAction::Overturned, 'high', Reviewer::query()->create(['name' => 'Sam']));

    scriptRescan('high', 0.95);
    $this->artisan('clorian:rescan', ['model' => Ticket::class, '--sync' => true])->assertSuccessful();

    expect(latestAnswer($ticket, 'urgency')->review_action)->toBe(ReviewAction::Upheld)
        ->and(latestAnswer($ticket, 'urgency')->reviewed_value)->toBe('high');
});

it('does not carry decisions onto changed content', function (): void {
    scriptRescan();
    $ticket = rescanTicket();
    $weighing = Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);
    Clorian::review($weighing->answers->firstWhere('question', 'urgency') ?? new WeighingAnswer, ReviewAction::Overturned, 'high', Reviewer::query()->create(['name' => 'Sam']));

    $ticket->update(['body' => 'Actually the site is down for everyone']);
    $this->artisan('clorian:rescan', ['model' => Ticket::class, '--sync' => true])->assertSuccessful();

    expect(latestAnswer($ticket, 'urgency')->isReviewed())->toBeFalse()
        ->and(latestAnswer($ticket, 'urgency')->status)->toBe(Status::Review)
        ->and($ticket->refresh()->priority)->toBe('normal');
});

it('keeps decisions through weighNow() too', function (): void {
    scriptRescan();
    $ticket = rescanTicket();
    $weighing = Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);
    Clorian::review($weighing->answers->firstWhere('question', 'urgency') ?? new WeighingAnswer, ReviewAction::Upheld, null, Reviewer::query()->create(['name' => 'Sam']));

    $ticket->weighNow();

    expect(latestAnswer($ticket, 'urgency')->review_action)->toBe(ReviewAction::Upheld)
        ->and($ticket->refresh()->priority)->toBe('critical');
});

it('reads models in chunks', function (): void {
    foreach (range(1, 5) as $i) {
        rescanTicket("Ticket {$i}");
    }

    DB::enableQueryLog();

    $this->artisan('clorian:rescan', ['model' => Ticket::class, '--sync' => true, '--chunk' => '2'])
        ->expectsOutputToContain('Weighed 5 Ticket.')
        ->assertSuccessful();

    // Any quoting: SQLite and PostgreSQL use "tickets", MySQL `tickets`.
    $selects = array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/^select \* from .tickets. /', $query['query']) === 1);

    expect($selects)->toHaveCount(3)
        ->and(Weighing::query()->count())->toBe(5);
});

it('limits to models created since a date', function (): void {
    Queue::fake();
    rescanTicket()->forceFill(['created_at' => now()->subDays(10)])->save();
    rescanTicket('New');

    $this->artisan('clorian:rescan', ['model' => Ticket::class, '--since' => '3 days ago'])
        ->expectsOutputToContain('Queued 1 Ticket for weighing.')
        ->assertSuccessful();

    $this->artisan('clorian:rescan', ['model' => Ticket::class, '--since' => now()->addDay()->toDateString()])
        ->expectsOutputToContain('No Ticket to weigh.')
        ->assertSuccessful();
});

it('names the class to pass for an unknown model, and takes a leading backslash', function (): void {
    $this->artisan('clorian:rescan', ['model' => 'Ticket'])
        ->expectsOutputToContain('No model [Ticket]. Pass the class, e.g. App\Models\Ticket.')
        ->assertExitCode(2);

    // A leading backslash is fine.
    Queue::fake();
    rescanTicket();

    $this->artisan('clorian:rescan', ['model' => '\\'.Ticket::class])->assertSuccessful();
});

it('reports failures and applies defaults', function (): void {
    app(FakeDriver::class)->fail();
    rescanTicket();
    rescanTicket('Second');

    $this->artisan('clorian:rescan', ['model' => Ticket::class, '--sync' => true])
        ->expectsOutputToContain('2 of 2 failed; their defaults were applied. Run again once the driver is back.')
        ->assertFailed();
});

it('rejects what it cannot rescan', function (array $arguments, string $message): void {
    $this->artisan('clorian:rescan', $arguments)->expectsOutputToContain($message)->assertExitCode(2);
})->with([
    'not a model' => [['model' => 'App\Nothing'], 'No model [App\Nothing].'],
    'no attribute' => [['model' => Note::class], 'uses HasWeighings but has no #[Weigh] attribute'],
    'zero chunk' => [['model' => Ticket::class, '--chunk' => '0'], '--chunk must be a whole number above 0.'],
    'word chunk' => [['model' => Ticket::class, '--chunk' => 'lots'], '--chunk must be a whole number above 0.'],
    'bad date' => [['model' => Ticket::class, '--since' => 'the dawn of time'], '--since must be a date, e.g. 2026-01-31 or "30 days ago", got "the dawn of time".'],
]);

it('refuses --since on models without timestamps', function (): void {
    $this->artisan('clorian:rescan', ['model' => UntimedTicket::class, '--since' => 'yesterday'])
        ->expectsOutputToContain('has no created_at timestamp, so --since cannot be used.')
        ->assertExitCode(2);
});
