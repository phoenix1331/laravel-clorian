<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Resolution\Answers;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\ContactIntent;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

it('has safe defaults for everything but questions()', function (): void {
    $policy = new ContactIntent;

    expect($policy->defaults())->toBe([])
        ->and($policy->context(null))->toBe([])
        ->and($policy->redact(['content' => 'Hello']))->toBe(['content' => 'Hello']);

    $policy->apply(null, new Answers(['intent' => 'sales']));
});

it('applies answers to its subject', function (): void {
    $ticket = Ticket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);

    (new TicketTriage)->apply($ticket, new Answers(['category' => 'billing', 'urgency' => 'high', 'needs_human' => false]));

    expect($ticket->fresh())
        ->queue->toBe('billing')
        ->priority->toBe('high');
});

it('falls back inside apply() when an answer is null', function (): void {
    $ticket = Ticket::query()->create(['subject' => 'Hm', 'body' => '?']);

    (new TicketTriage)->apply($ticket, new Answers(['category' => null, 'urgency' => 'normal', 'needs_human' => false]));

    expect($ticket->fresh()?->queue)->toBe('triage');
});

it('adds context from its subject', function (): void {
    expect((new TicketTriage)->context(null))->toBe(['plan' => 'team']);
});
