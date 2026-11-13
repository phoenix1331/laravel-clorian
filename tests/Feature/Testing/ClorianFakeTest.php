<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Phoenix1331\Clorian\Clorian as Manager;
use Phoenix1331\Clorian\Drivers\Driver;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Testing\ClorianFake;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\ContactIntent;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;
use PHPUnit\Framework\AssertionFailedError;

function fakedTicket(): Ticket
{
    return Ticket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);
}

it('swaps in a fake that scripts answers and still weighs for real', function (): void {
    config()->set('clorian.driver', 'jev');
    $ticket = fakedTicket();

    $fake = Clorian::fake()->answer('category', 'billing', 0.93)->answer('urgency', 'high', 0.95);

    $weighing = Clorian::now(TicketTriage::class, ['body' => $ticket->body], $ticket);

    expect($fake)->toBeInstanceOf(ClorianFake::class)
        ->and(Clorian::getFacadeRoot())->toBe($fake)
        ->and($weighing->driver)->toBe('fake')
        ->and($weighing->accepted('category', 'billing'))->toBeTrue()
        ->and($weighing->accepted('urgency', 'high'))->toBeTrue()
        ->and(Weighing::query()->count())->toBe(1)
        ->and($ticket->refresh()->queue)->toBe('billing')
        ->and($ticket->priority)->toBe('high');

    Http::assertNothingSent();
});

it('records weighings made through the container, as jobs do', function (): void {
    Clorian::fake();

    app(Manager::class)->now(ContactIntent::class, 'Hello');

    Clorian::assertWeighedTimes(ContactIntent::class, 1);
});

it('asserts a policy was weighed, for a subject or a callback', function (): void {
    $ticket = fakedTicket();
    $other = fakedTicket();
    Clorian::fake()->answer('category', 'bug', 0.97);

    Clorian::now(TicketTriage::class, ['body' => $ticket->body], $ticket);

    Clorian::assertWeighed(TicketTriage::class);
    Clorian::assertWeighed(TicketTriage::class, $ticket);
    Clorian::assertWeighed(TicketTriage::class, fn (Weighing $w): bool => $w->accepted('category', 'bug'));
    Clorian::assertNotWeighed(ContactIntent::class);
    Clorian::assertNotWeighed(TicketTriage::class, $other);
    Clorian::assertNotWeighed(TicketTriage::class, fn (Weighing $w): bool => $w->accepted('category', 'billing'));

    expect(fn () => Clorian::assertWeighed(ContactIntent::class))
        ->toThrow(AssertionFailedError::class, 'The policy ['.ContactIntent::class.'] was not weighed.')
        ->and(fn () => Clorian::assertWeighed(TicketTriage::class, $other))
        ->toThrow(AssertionFailedError::class, 'was not weighed for ['.Ticket::class." #{$other->id}].")
        ->and(fn () => Clorian::assertWeighed(TicketTriage::class, fn (Weighing $w): bool => false))
        ->toThrow(AssertionFailedError::class, 'was not weighed matching the callback.')
        ->and(fn () => Clorian::assertNotWeighed(TicketTriage::class, $ticket))
        ->toThrow(AssertionFailedError::class, 'was weighed for ['.Ticket::class." #{$ticket->id}] unexpectedly.");
});

it('counts weighings per policy', function (): void {
    Clorian::fake();

    // Ids from the weighing itself: MySQL and PostgreSQL do not restart them per test.
    $first = Clorian::now(ContactIntent::class, 'One');
    Clorian::now(ContactIntent::class, 'Two');
    Clorian::now(TicketTriage::class, 'Three');

    Clorian::assertWeighedTimes(ContactIntent::class, 2);
    Clorian::assertWeighedTimes(TicketTriage::class, 1);
    Clorian::assertWeighedTimes(ContactIntent::class, 1, fn (Weighing $w): bool => $w->id === $first->id);

    expect(Clorian::weighings())->toHaveCount(3)
        ->and(Clorian::weighings(ContactIntent::class))->toHaveCount(2)
        ->and(fn () => Clorian::assertWeighedTimes(ContactIntent::class, 3))
        ->toThrow(AssertionFailedError::class, 'Expected the policy ['.ContactIntent::class.'] to be weighed 3 times, weighed 2.');
});

it('asserts nothing was weighed', function (): void {
    Clorian::fake();

    Clorian::assertNothingWeighed();

    Clorian::now(ContactIntent::class, 'Hello');
    Clorian::now(ContactIntent::class, 'Again');

    expect(fn () => Clorian::assertNothingWeighed())
        ->toThrow(AssertionFailedError::class, 'Weighings were made unexpectedly: '.ContactIntent::class.'.');
});

it('records failed weighings too', function (): void {
    Clorian::fake()->fail('Down for maintenance');

    $weighing = Clorian::now(ContactIntent::class, 'Hello');

    expect($weighing->status)->toBe(WeighingStatus::Failed)
        ->and($weighing->error)->toBe('Down for maintenance');

    Clorian::assertWeighed(ContactIntent::class, fn (Weighing $w): bool => $w->status === WeighingStatus::Failed);
});

it('scores exact distributions', function (): void {
    Clorian::fake()->distribution('intent', ['billing' => 0.6, 'account_access' => 0.1, 'bug_report' => 0.1, 'sales' => 0.1, 'spam' => 0.1]);

    $answer = Clorian::now(ContactIntent::class, 'Invoice?')->answers->sole();

    expect([$answer->value, $answer->confidence])->toBe(['billing', 0.6]);
});

it('passes driver() and extend() through to the manager', function (): void {
    $fake = Clorian::fake();

    expect(Clorian::driver())->toBe(app(FakeDriver::class))
        ->and(Clorian::extend('other', fn (Container $app, array $config): Driver => new FakeDriver))->toBe($fake)
        ->and(Clorian::driver('other'))->toBeInstanceOf(FakeDriver::class)
        ->and(Clorian::driver('other'))->not->toBe(app(FakeDriver::class));
});

it('needs a Laravel application', function (): void {
    $app = Facade::getFacadeApplication();
    Facade::setFacadeApplication(null);

    try {
        expect(fn () => Clorian::fake())->toThrow(RuntimeException::class, 'Clorian::fake() needs a Laravel application.');
    } finally {
        Facade::setFacadeApplication($app);
    }
});
