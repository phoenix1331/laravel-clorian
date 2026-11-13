<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Facades;

use Illuminate\Support\Facades\Facade;
use Phoenix1331\Clorian\Clorian as Manager;

/**
 * @method static \Phoenix1331\Clorian\Models\Weighing now(string $policy, string|array<string, mixed> $input, ?\Illuminate\Database\Eloquent\Model $subject = null)
 * @method static \Phoenix1331\Clorian\Drivers\Driver driver(?string $name = null)
 * @method static \Phoenix1331\Clorian\Clorian extend(string $name, \Closure $factory)
 *
 * @see Manager
 */
final class Clorian extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
