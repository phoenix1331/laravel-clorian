<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Jobs\WeighSubject;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\ContactIntent;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\LossyRedaction;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\SupportReply;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

beforeEach(function (): void {
    config()->set('clorian.driver', 'fake');
});

function redactionDriver(): FakeDriver
{
    return app(FakeDriver::class);
}

function personalTicket(): Ticket
{
    return Ticket::query()->create(['subject' => 'Refund for jo@example.com', 'body' => 'Card 4111 1111 1111 1111, call 07700 900123']);
}

it('sends the driver redacted string input only', function (): void {
    Clorian::now(ContactIntent::class, 'I am jo@example.com, call 07700 900123');

    expect(redactionDriver()->calls()[0]['content'])->toBe('I am [email], call [phone]');
});

it('sends the driver redacted model fields only', function (): void {
    $ticket = personalTicket();

    Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    expect(redactionDriver()->calls()[0]['content'])->toBe([
        'subject' => 'Refund for [email]',
        'body' => 'Card [number], call [phone]',
    ]);
});

it('applies the policy redact() to input and context', function (): void {
    Clorian::now(SupportReply::class, 'Jo Bloggs here, jo@example.com');

    expect(redactionDriver()->calls()[0]['content'])->toBe('[name] here, [email]')
        ->and(redactionDriver()->calls()[0]['context'])->toBe(['customer' => '[name]', 'email' => '[email]', 'plan' => 'pro']);
});

it('redacts queued weighings too', function (): void {
    $ticket = personalTicket();

    (new WeighSubject($ticket))->handle(app('clorian'));

    expect(redactionDriver()->calls()[0]['content'])->toBe([
        'subject' => 'Refund for [email]',
        'body' => 'Card [number], call [phone]',
    ]);
});

it('rejects a redact() that loses a string input before calling the driver', function (): void {
    expect(fn () => Clorian::now(LossyRedaction::class, 'Hello'))->toThrow(
        LogicException::class,
        LossyRedaction::class.'::redact() must return "content" as a string when the input is a string.',
    );

    expect(redactionDriver()->calls())->toBe([])
        ->and(Weighing::query()->count())->toBe(0);
});

it('hashes the original input, so unchanged content is still recognised', function (): void {
    $weighing = Clorian::now(ContactIntent::class, 'call 07700 900123');

    expect($weighing->content_hash)->toBe(hash('sha256', '"call 07700 900123"'));
});

it('stores input per clorian.store_input', function (string $mode, bool $subjectless, bool $withSubject): void {
    config()->set('clorian.store_input', $mode);
    $ticket = personalTicket();

    $without = Clorian::now(ContactIntent::class, 'I am jo@example.com');
    $with = Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    expect($without->refresh()->input)->toBe($subjectless ? ['content' => 'I am [email]'] : null)
        ->and($with->refresh()->input)->toBe($withSubject ? ['subject' => 'Refund for [email]', 'body' => 'Card [number], call [phone]'] : null);
})->with([
    'none' => ['none', false, false],
    'subjectless' => ['subjectless', true, false],
    'always' => ['always', true, true],
]);

it('stores only subjectless input by default', function (): void {
    expect(config('clorian.store_input'))->toBe('subjectless');

    $ticket = personalTicket();

    expect(Clorian::now(ContactIntent::class, 'Hello')->input)->toBe(['content' => 'Hello'])
        ->and(Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket)->input)->toBeNull();
});

it('stores redacted input for failed weighings too', function (): void {
    config()->set('clorian.store_input', 'always');
    redactionDriver()->fail();
    $ticket = personalTicket();

    $weighing = Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    expect($weighing->status)->toBe(WeighingStatus::Failed)
        ->and($weighing->refresh()->input)->toBe(['subject' => 'Refund for [email]', 'body' => 'Card [number], call [phone]']);
});

it('keeps stored input encrypted at rest', function (): void {
    $weighing = Clorian::now(ContactIntent::class, 'My order 123456789 never arrived');

    $raw = DB::table('clorian_weighings')->where('id', $weighing->id)->value('input');

    expect($raw)->toBeString()
        ->not->toContain('never arrived')
        ->and(json_decode(Crypt::decryptString((string) $raw), true))->toBe(['content' => 'My order [number] never arrived']);
});

it('fails fast on an invalid store_input before calling the driver', function (): void {
    config()->set('clorian.store_input', 'sometimes');

    expect(fn () => Clorian::now(ContactIntent::class, 'Hello'))->toThrow(InvalidArgumentException::class, 'clorian.store_input');

    expect(redactionDriver()->calls())->toBe([]);
});
