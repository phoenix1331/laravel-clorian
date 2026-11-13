<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Clorian as Manager;
use Phoenix1331\Clorian\Facades\Clorian;

/**
 * Method name => return type, from the facade's @method lines.
 *
 * @return array<string, string>
 */
function facadeMethods(): array
{
    $doc = (string) (new ReflectionClass(Clorian::class))->getDocComment();
    preg_match_all('/@method\s+static\s+(\S+)\s+(\w+)\(/', $doc, $matches, PREG_SET_ORDER);

    $methods = [];

    foreach ($matches as [, $type, $name]) {
        $methods[$name] = ltrim($type, '\\');
    }

    ksort($methods);

    return $methods;
}

/**
 * Method name => return type, from the manager's public methods.
 *
 * @return array<string, string>
 */
function managerMethods(): array
{
    $methods = [];

    foreach ((new ReflectionClass(Manager::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() || $method->isStatic()) {
            continue;
        }

        $type = $method->getReturnType();
        $methods[$method->getName()] = $type instanceof ReflectionNamedType ? ($type->getName() === 'self' ? Manager::class : $type->getName()) : (string) $type;
    }

    ksort($methods);

    return $methods;
}

it('lists every manager method on the facade, with matching return types', function (): void {
    expect(facadeMethods())->toBe(managerMethods())
        ->and(managerMethods())->toHaveKeys(['now', 'driver', 'extend']);
});

it('lists every manager parameter on the facade in order', function (): void {
    $doc = (string) (new ReflectionClass(Clorian::class))->getDocComment();

    foreach ((new ReflectionClass(Manager::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor()) {
            continue;
        }

        preg_match('/@method\s+static\s+\S+\s+'.$method->getName().'\(([^)]*)\)/', $doc, $match);
        preg_match_all('/\$(\w+)/', $match[1] ?? '', $documented);

        expect($documented[1])->toBe(array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters()));
    }
});

arch('the manager and facade are final')
    ->expect([Manager::class, Clorian::class])
    ->toBeFinal();
