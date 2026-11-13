<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Attributes\Weigh;
use Phoenix1331\Clorian\Exceptions\MissingWeighAttribute;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Note;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

#[Weigh('App\Policies\Parent', fields: ['title'])]
class WeighedParent {}

final class WeighedChild extends WeighedParent {}

#[Weigh('App\Policies\Empty', fields: [])]
final class WeighedWithoutFields {}

it('reads the attribute from a class or an instance', function (): void {
    expect(Weigh::of(Ticket::class))->toEqual(new Weigh(TicketTriage::class, ['subject', 'body']))
        ->and(Weigh::of(new Ticket)->fields)->toBe(['subject', 'body']);
});

it('inherits the attribute from a parent class', function (): void {
    expect(Weigh::of(WeighedChild::class)->policy)->toBe('App\Policies\Parent');
});

it('throws when the attribute is missing or lists no fields', function (): void {
    expect(fn () => Weigh::of(Note::class))
        ->toThrow(MissingWeighAttribute::class, Note::class." uses HasWeighings but has no #[Weigh] attribute. Add #[Weigh(YourPolicy::class, fields: ['title', 'body'])] to the class.")
        ->and(fn () => Weigh::of(WeighedWithoutFields::class))
        ->toThrow(MissingWeighAttribute::class, "The #[Weigh] attribute on WeighedWithoutFields lists no fields. Name the attributes to send, e.g. fields: ['title', 'body'].");
});
