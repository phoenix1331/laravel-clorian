<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Models\SubjectStatus;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Reviews\ReviewAction;
use Phoenix1331\Clorian\Support\StatusColumn;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Reviewer;
use Phoenix1331\Clorian\Tests\Fixtures\Models\StatusTicket;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\ContactIntent;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

beforeEach(function (): void {
    config()->set('clorian.driver', 'fake');
});

function withStatusColumn(string $name = 'clorian_status'): void
{
    config()->set('clorian.status_column', ['enabled' => true, 'name' => $name]);
}

/**
 * Category accepted; urgency in review unless $urgency is accepted.
 */
function statusTicket(string $urgency = 'critical'): StatusTicket
{
    app(FakeDriver::class)->answer('category', 'billing', 0.95)->answer('urgency', $urgency, 0.97)->answer('needs_human', false, 0.9);
    $ticket = StatusTicket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);
    Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    return $ticket;
}

function storedStatus(StatusTicket $ticket, string $column = 'clorian_status'): mixed
{
    return DB::table('status_tickets')->where('id', $ticket->id)->value($column);
}

it('is off by default', function (): void {
    expect(config('clorian.status_column.enabled'))->toBeFalse();

    expect(storedStatus(statusTicket()))->toBeNull();
});

it('records review after weighing, then complete once reviewed', function (): void {
    withStatusColumn();
    $ticket = statusTicket();

    expect(storedStatus($ticket))->toBe('review')
        ->and($ticket->getAttribute('clorian_status'))->toBe('review');

    $answer = $ticket->answer('urgency');
    Clorian::review($answer instanceof WeighingAnswer ? $answer : new WeighingAnswer, ReviewAction::Upheld, null, Reviewer::query()->create(['name' => 'Sam']));

    expect(storedStatus($ticket))->toBe('complete');
});

it('records complete when nothing waits for a human', function (): void {
    withStatusColumn();

    expect(storedStatus(statusTicket('high')))->toBe(SubjectStatus::Complete->value);
});

it('records failed when the driver fails', function (): void {
    withStatusColumn();
    app(FakeDriver::class)->fail();
    $ticket = StatusTicket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);

    Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    expect(storedStatus($ticket))->toBe('failed');
});

it('follows the latest weighing', function (): void {
    withStatusColumn();
    $ticket = statusTicket();

    app(FakeDriver::class)->answer('urgency', 'high', 0.97);
    $ticket->weighNow();

    expect(storedStatus($ticket))->toBe('complete');
});

it('reads the status of the latest weighing when an older one is reviewed', function (): void {
    withStatusColumn();
    $ticket = statusTicket();
    $old = $ticket->answer('urgency');
    $ticket->update(['body' => 'Site down for everyone']);
    $ticket->weighNow();

    Clorian::review($old instanceof WeighingAnswer ? $old : new WeighingAnswer, ReviewAction::Upheld, null, Reviewer::query()->create(['name' => 'Sam']));

    expect(storedStatus($ticket))->toBe('review');
});

it('writes without model events or touching updated_at', function (): void {
    withStatusColumn();
    $this->travelTo(now()->subHour());
    $ticket = StatusTicket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);
    $this->travelBack();
    $updatedAt = $ticket->updated_at;
    Weighing::factory()->forSubject($ticket)->failed()->create();
    Event::fake(['eloquent.updating: '.StatusTicket::class, 'eloquent.saving: '.StatusTicket::class]);

    // On its own: the fixture policy's apply() updates the ticket itself.
    app(StatusColumn::class)->sync($ticket);

    Event::assertNotDispatched('eloquent.updating: '.StatusTicket::class);
    Event::assertNotDispatched('eloquent.saving: '.StatusTicket::class);
    expect(storedStatus($ticket))->toBe('failed')
        ->and($ticket->isDirty())->toBeFalse()
        ->and($ticket->refresh()->updated_at?->equalTo($updatedAt))->toBeTrue();
});

it('clears the status when the subject has no weighings', function (): void {
    withStatusColumn();
    $ticket = StatusTicket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please', 'clorian_status' => 'review']);

    app(StatusColumn::class)->sync($ticket);

    expect(storedStatus($ticket))->toBeNull();
});

it('makes pendingReview() read the column', function (): void {
    withStatusColumn();
    $waiting = statusTicket();
    statusTicket('high');

    $sql = StatusTicket::pendingReview()->toSql();

    expect($sql)->toContain('clorian_status')
        ->not->toContain('clorian_answers')
        ->and(StatusTicket::pendingReview()->pluck('id')->all())->toBe([$waiting->id]);
});

it('uses a configured column name', function (): void {
    withStatusColumn('triage_state');

    expect(storedStatus(statusTicket(), 'triage_state'))->toBe('review');
});

it('does nothing for tables without the column', function (): void {
    withStatusColumn();
    app(FakeDriver::class)->answer('urgency', 'critical', 0.97);
    $ticket = Ticket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);
    Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    expect(Schema::hasColumn('tickets', 'clorian_status'))->toBeFalse()
        ->and(Ticket::pendingReview()->pluck('id')->all())->toBe([$ticket->id]);
});

it('skips subjectless weighings', function (): void {
    withStatusColumn();
    app(FakeDriver::class)->answer('intent', 'billing', 0.95);

    Clorian::now(ContactIntent::class, 'Refund please');

    expect(DB::table('status_tickets')->whereNotNull('clorian_status')->count())->toBe(0);
});
