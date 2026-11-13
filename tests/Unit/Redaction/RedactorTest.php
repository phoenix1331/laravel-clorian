<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Redaction\Redactor;

it('redacts personal data in a string', function (string $input, string $expected): void {
    expect((new Redactor)->redactString($input))->toBe($expected);
})->with([
    'email' => ['Email jo.bloggs+urgent@example.co.uk now', 'Email [email] now'],
    'email with apostrophe' => ["Write to o'neil@mail.example.org", 'Write to [email]'],
    'uk mobile' => ['Call 07700 900123 today', 'Call [phone] today'],
    'uk mobile unbroken' => ['Call 07700900123', 'Call [phone]'],
    'international' => ['Ring +44 20 7946 0958', 'Ring [phone]'],
    'us with brackets' => ['Ring (555) 123-4567', 'Ring [phone]'],
    'us split' => ['Ring 555-123-4567', 'Ring [phone]'],
    'card' => ['Card 4111 1111 1111 1111 declined', 'Card [number] declined'],
    'amex' => ['Amex 3782 822463 10005', 'Amex [number]'],
    'account number' => ['Account 12345678', 'Account [number]'],
    'order number' => ['Order 123456789 late', 'Order [number] late'],
    'inside a reference' => ['Ref ABC123456789', 'Ref ABC[number]'],
    'two in one string' => ['a@b.io and 07700 900123', '[email] and [phone]'],
]);

it('leaves dates, times, prices, sort codes and short numbers alone', function (string $input): void {
    expect((new Redactor)->redactString($input))->toBe($input);
})->with([
    'iso date' => ['Charged on 2026-11-13 at 14:30'],
    'uk date' => ['Since 13/11/2026'],
    'price' => ['I paid £1,299.99'],
    'sort code' => ['Sort code 12-34-56'],
    'seven digits' => ['Ticket 1234567'],
    'version' => ['Running v1.2.3'],
    'no personal data' => ['The export button does nothing'],
    'at sign alone' => ['Meet @ 5'],
]);

it('redacts every string in nested arrays and keeps keys and other values', function (): void {
    $input = [
        'subject' => 'Contact jo@example.com',
        'meta' => ['phone' => '07700 900123', 'tags' => ['a@b.io', 'vip']],
        'count' => 123456789,
        'urgent' => true,
        'note' => null,
    ];

    expect((new Redactor)->redact($input))->toBe([
        'subject' => 'Contact [email]',
        'meta' => ['phone' => '[phone]', 'tags' => ['[email]', 'vip']],
        'count' => 123456789,
        'urgent' => true,
        'note' => null,
    ]);
});

it('handles multibyte text', function (): void {
    expect((new Redactor)->redactString('Grüße, rufen Sie 0170 1234567 an, José'))
        ->toBe('Grüße, rufen Sie [phone] an, José');
});
