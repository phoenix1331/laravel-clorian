<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Phoenix1331\Clorian\Clorian as Manager;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Jobs\WeighSubject;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Support\Weighable;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

beforeEach(function (): void {
    config()->set('clorian.enabled', true);
});

function observedTicket(string $subject = 'Charged twice'): Ticket
{
    return Ticket::query()->create(['subject' => $subject, 'body' => 'Refund please']);
}

function weighedAs(Ticket $ticket, WeighingStatus $status = WeighingStatus::Complete): void
{
    Weighing::factory()->forSubject($ticket)->create(['status' => $status, 'content_hash' => Manager::hash(Weighable::input($ticket))]);
}

it('queues a weighing after the save commits, on the configured queue', function (): void {
    Queue::fake();
    config()->set('clorian.queue', 'clorian');

    $ticket = observedTicket();

    Queue::assertPushedOn('clorian', WeighSubject::class, fn (WeighSubject $job): bool => $job->subject->is($ticket) && $job->afterCommit === true);
});

it('uses the connection default queue when none is set', function (): void {
    Queue::fake();
    config()->set('clorian.queue', '');

    observedTicket();

    Queue::assertPushed(WeighSubject::class, fn (WeighSubject $job): bool => $job->queue === null);
});

it('weighs again only when a #[Weigh] field changes', function (): void {
    Queue::fake();
    $ticket = observedTicket();

    $ticket->update(['queue' => 'billing', 'priority' => 'high']);
    Queue::assertPushed(WeighSubject::class, 1);

    $ticket->update(['body' => 'Refund the second payment please']);
    Queue::assertPushed(WeighSubject::class, 2);
});

it('skips content that was already weighed successfully', function (): void {
    Queue::fake();
    config()->set('clorian.enabled', false);
    $ticket = observedTicket();
    weighedAs($ticket);
    $ticket->update(['subject' => 'Changed while disabled']);
    config()->set('clorian.enabled', true);

    $ticket->update(['subject' => 'Charged twice']);

    Queue::assertNothingPushed();
});

it('weighs unchanged content again when the last weighing failed', function (): void {
    Queue::fake();
    config()->set('clorian.enabled', false);
    $ticket = observedTicket();
    weighedAs($ticket, WeighingStatus::Failed);
    $ticket->update(['subject' => 'Changed while disabled']);
    config()->set('clorian.enabled', true);

    $ticket->update(['subject' => 'Charged twice']);

    Queue::assertPushed(WeighSubject::class, 1);
});

it('does nothing while disabled', function (): void {
    Queue::fake();
    config()->set('clorian.enabled', false);

    observedTicket()->update(['body' => 'Changed']);

    Queue::assertNothingPushed();
});

it('weighs inline in sync mode', function (): void {
    Queue::fake();
    config()->set('clorian.mode', 'sync');
    Clorian::fake()->answer('category', 'billing', 0.95);

    $ticket = observedTicket();

    Queue::assertNothingPushed();
    Clorian::assertWeighed(TicketTriage::class, $ticket);

    expect($ticket->refresh()->queue)->toBe('billing');
});

it('dispatches nothing when the transaction rolls back', function (): void {
    Clorian::fake()->answer('category', 'billing', 0.95);

    try {
        DB::transaction(function (): void {
            observedTicket('rolled back');

            throw new RuntimeException('Abort the save');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    Clorian::assertNothingWeighed();

    DB::transaction(fn () => observedTicket('committed'));

    Clorian::assertWeighedTimes(TicketTriage::class, 1);

    expect(Ticket::query()->sole()->queue)->toBe('billing');
});
