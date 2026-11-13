<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Clorian as Manager;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Testing\ClorianFake;

/**
 * Method name => [return type, parameter names], from the facade's @method lines.
 *
 * @return array<string, array{0: string, 1: list<string>}>
 */
function facadeMethods(): array
{
    $doc = (string) (new ReflectionClass(Clorian::class))->getDocComment();
    preg_match_all('/@method\s+static\s+(\S+)\s+(\w+)\(([^)]*)\)/', $doc, $matches, PREG_SET_ORDER);

    $methods = [];

    foreach ($matches as [, $type, $name, $parameters]) {
        preg_match_all('/\$(\w+)/', $parameters, $names);
        // Generics dropped: list<Weighing> is a native array.
        $native = ltrim(preg_replace('/<.*>/', '', $type) ?? $type, '\\');
        $methods[$name] = [$native === 'list' ? 'array' : $native, $names[1]];
    }

    ksort($methods);

    return $methods;
}

/**
 * Method name => [return type, parameter names] for a class's public API:
 * no constructor, statics or @internal methods.
 *
 * @param  class-string  $class
 * @return array<string, array{0: string, 1: list<string>}>
 */
function publicMethods(string $class): array
{
    $methods = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() || $method->isStatic() || str_contains((string) $method->getDocComment(), '@internal')) {
            continue;
        }

        $type = $method->getReturnType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

        $methods[$method->getName()] = [
            $name === 'self' ? $class : $name,
            array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters()),
        ];
    }

    ksort($methods);

    return $methods;
}

it('documents every manager and fake method on the facade', function (): void {
    $expected = [...publicMethods(ClorianFake::class), ...publicMethods(Manager::class)];
    ksort($expected);

    expect(facadeMethods())->toBe($expected)
        ->and(facadeMethods())->toHaveKeys(['now', 'driver', 'extend', 'answer', 'assertWeighed', 'assertNothingWeighed', 'assertWeighedTimes']);
});

it('gives the fake every manager method with the same parameters', function (): void {
    $fake = publicMethods(ClorianFake::class);

    foreach (publicMethods(Manager::class) as $name => [$type, $parameters]) {
        expect($fake)->toHaveKey($name)
            ->and($fake[$name][1])->toBe($parameters);
    }
});

arch('the manager, facade and fake are final')
    ->expect([Manager::class, Clorian::class, ClorianFake::class])
    ->toBeFinal();
