<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Events\AnswerAccepted;
use Phoenix1331\Clorian\Events\AnswerNeedsReview;
use Phoenix1331\Clorian\Events\AnswerReviewed;
use Phoenix1331\Clorian\Events\WeighingFailed;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Jobs\WeighSubject;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Reviews\ReviewAction;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\ContactIntent;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

const EVENT_CLASSES = [AnswerAccepted::class, AnswerNeedsReview::class, AnswerReviewed::class, WeighingFailed::class];

const SECRET_BODY = 'My card 4111 1111 1111 1111 was charged twice';

beforeEach(function (): void {
    config()->set('clorian.driver', 'fake');
});

function eventTicket(): Ticket
{
    return Ticket::query()->create(['subject' => 'Charged twice', 'body' => SECRET_BODY]);
}

/**
 * category accepted, urgency sent to review (critical always reviews),
 * needs_human unresolved (0.55 is below the 0.60 review threshold).
 */
function scriptTriage(): void
{
    app(FakeDriver::class)
        ->answer('category', 'billing', 0.95)
        ->answer('urgency', 'critical', 0.97)
        ->answer('needs_human', true, 0.55);
}

function answerId(string $question): int
{
    return WeighingAnswer::query()->where('question', $question)->sole()->id;
}

it('fires AnswerAccepted and AnswerNeedsReview with ids and scalars', function (): void {
    Event::fake(EVENT_CLASSES);
    scriptTriage();
    $ticket = eventTicket();

    $weighing = Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    Event::assertDispatchedTimes(AnswerAccepted::class, 1);
    Event::assertDispatchedTimes(AnswerNeedsReview::class, 1);
    Event::assertNotDispatched(WeighingFailed::class);

    Event::assertDispatched(AnswerAccepted::class, fn (AnswerAccepted $e): bool => $e == new AnswerAccepted(
        $weighing->id, answerId('category'), TicketTriage::class, 'category', 'billing', 0.95, $ticket->getMorphClass(), $ticket->id,
    ));

    Event::assertDispatched(AnswerNeedsReview::class, fn (AnswerNeedsReview $e): bool => $e == new AnswerNeedsReview(
        $weighing->id, answerId('urgency'), TicketTriage::class, 'urgency', 'critical', 0.97, $ticket->getMorphClass(), $ticket->id,
    ));
});

it('gives boolean answers as true or false', function (): void {
    Event::fake(EVENT_CLASSES);
    app(FakeDriver::class)->answer('needs_human', false, 0.9);

    Clorian::now(TicketTriage::class, 'Thanks, all sorted');

    Event::assertDispatched(AnswerAccepted::class, fn (AnswerAccepted $e): bool => $e->question === 'needs_human'
        && $e->value === false
        && $e->subjectType === null
        && $e->subjectId === null);
});

it('fires nothing for unresolved answers', function (): void {
    Event::fake(EVENT_CLASSES);
    app(FakeDriver::class)->answer('intent', 'spam', 0.4);

    Clorian::now(ContactIntent::class, 'Hello');

    Event::assertNothingDispatched();
});

it('fires WeighingFailed when a sync weighing fails', function (): void {
    Event::fake(EVENT_CLASSES);
    app(FakeDriver::class)->fail('Jev timed out after 2 seconds.');
    $ticket = eventTicket();

    $weighing = Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    Event::assertDispatchedTimes(WeighingFailed::class, 1);
    Event::assertDispatched(WeighingFailed::class, fn (WeighingFailed $e): bool => $e == new WeighingFailed(
        $weighing->id, TicketTriage::class, 'Jev timed out after 2 seconds.', $ticket->getMorphClass(), $ticket->id,
    ));
    Event::assertNotDispatched(AnswerAccepted::class);
    Event::assertNotDispatched(AnswerNeedsReview::class);
});

it('fires WeighingFailed when a queued weighing runs out of retries', function (): void {
    Event::fake(EVENT_CLASSES);
    $ticket = eventTicket();

    (new WeighSubject($ticket))->failed(DriverException::retryable('Jev returned HTTP 529: Overloaded', 529));

    Event::assertDispatched(WeighingFailed::class, fn (WeighingFailed $e): bool => $e->weighingId === Weighing::query()->sole()->id
        && $e->error === 'Jev returned HTTP 529: Overloaded'
        && $e->subjectId === $ticket->id);
});

it('does not fire WeighingFailed while a job still has retries left', function (): void {
    Event::fake(EVENT_CLASSES);
    app(FakeDriver::class)->fail();

    $job = (new WeighSubject(eventTicket()))->withFakeQueueInteractions();
    $job->handle(app('clorian'));

    $job->assertReleased();
    Event::assertNotDispatched(WeighingFailed::class);
});

it('waits for the surrounding transaction to commit', function (): void {
    $fired = [];
    Event::listen(AnswerAccepted::class, function (AnswerAccepted $e) use (&$fired): void {
        $fired[] = $e->question;
    });
    app(FakeDriver::class)->answer('intent', 'billing', 0.95);

    DB::transaction(function () use (&$fired): void {
        Clorian::now(ContactIntent::class, 'Refund please');

        expect($fired)->toBe([]);
    });

    expect($fired)->toBe(['intent']);
});

it('never fires for a weighing that was rolled back', function (): void {
    $fired = 0;
    Event::listen(AnswerAccepted::class, function () use (&$fired): void {
        $fired++;
    });
    app(FakeDriver::class)->answer('intent', 'billing', 0.95);

    rescue(fn () => DB::transaction(function (): void {
        Clorian::now(ContactIntent::class, 'Refund please');

        throw new RuntimeException('Rolled back');
    }), report: false);

    expect($fired)->toBe(0)
        ->and(Weighing::query()->count())->toBe(0);
});

it('serialises every payload without the weighed content', function (): void {
    $events = [];
    Event::listen(EVENT_CLASSES, function (object $e) use (&$events): void {
        $events[] = $e;
    });
    scriptTriage();
    $ticket = eventTicket();
    Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);
    app(FakeDriver::class)->fail('Jev returned HTTP 529: Overloaded');
    Clorian::now(TicketTriage::class, ['subject' => 'Charged twice', 'body' => SECRET_BODY]);
    $events[] = new AnswerReviewed(1, 2, TicketTriage::class, 'urgency', ReviewAction::Overturned, 'critical', 'high', 'users', 7, 'tickets', 3);

    expect($events)->toHaveCount(4);

    foreach ($events as $event) {
        $serialised = serialize($event);
        $json = (string) json_encode($event);

        expect(unserialize($serialised))->toEqual($event)
            ->and($serialised)->not->toContain('4111')->not->toContain('charged twice')
            ->and($json)->not->toContain('4111')->not->toContain('charged twice')
            ->and($json)->not->toContain('Charged twice');
    }
});

it('holds only ids, scalars and the review action', function (string $class): void {
    foreach ((new ReflectionClass($class))->getProperties() as $property) {
        $type = $property->getType();
        $names = $type instanceof ReflectionUnionType
            ? array_map(static fn (ReflectionNamedType $t): string => $t->getName(), $type->getTypes())
            : [$type instanceof ReflectionNamedType ? $type->getName() : 'mixed'];

        expect(array_diff($names, ['int', 'string', 'float', 'bool', 'null', ReviewAction::class]))
            ->toBe([], "{$class}::\${$property->getName()} is not a scalar");
    }

    expect(is_subclass_of($class, ShouldDispatchAfterCommit::class))->toBeTrue();
})->with(EVENT_CLASSES);

arch('events are final and readonly')
    ->expect('Phoenix1331\Clorian\Events')
    ->toBeFinal()
    ->toBeReadonly();

arch('events never carry models or content objects')
    ->expect('Phoenix1331\Clorian\Events')
    ->not->toUse([
        'Phoenix1331\Clorian\Models',
        'Phoenix1331\Clorian\Resolution',
        'Illuminate\Database',
        'Illuminate\Queue\SerializesModels',
    ]);

it('reaches Event::fake() made after the manager was resolved', function (): void {
    app('clorian');
    Event::fake(EVENT_CLASSES);
    app(FakeDriver::class)->answer('intent', 'billing', 0.95);

    Clorian::now(ContactIntent::class, 'Refund please');

    Event::assertDispatched(AnswerAccepted::class);
});
