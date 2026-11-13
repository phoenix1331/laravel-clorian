<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Redaction\InputStorage;

it('reads the config value', function (mixed $value, InputStorage $expected): void {
    expect(InputStorage::fromConfig($value))->toBe($expected);
})->with([
    'none' => ['none', InputStorage::None],
    'subjectless' => ['subjectless', InputStorage::Subjectless],
    'always' => ['always', InputStorage::Always],
    'any case and spacing' => [' Always ', InputStorage::Always],
]);

it('rejects anything else with the values it accepts', function (mixed $value, string $got): void {
    expect(fn () => InputStorage::fromConfig($value))->toThrow(
        InvalidArgumentException::class,
        "Set clorian.store_input (CLORIAN_STORE_INPUT) to \"none\", \"subjectless\" or \"always\", got {$got}.",
    );
})->with([
    'unknown' => ['sometimes', '"sometimes"'],
    'empty' => ['', '""'],
    'null' => [null, 'null'],
    'array' => [['always'], 'array'],
]);

it('stores per mode and subject', function (InputStorage $storage, bool $withSubject, bool $withoutSubject): void {
    expect($storage->stores(true))->toBe($withSubject)
        ->and($storage->stores(false))->toBe($withoutSubject);
})->with([
    'none' => [InputStorage::None, false, false],
    'subjectless' => [InputStorage::Subjectless, false, true],
    'always' => [InputStorage::Always, true, true],
]);
