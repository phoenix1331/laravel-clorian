<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Facades;

use Illuminate\Support\Facades\Facade;
use Phoenix1331\Clorian\Clorian as Manager;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Testing\ClorianFake;
use RuntimeException;

/**
 * @method static \Phoenix1331\Clorian\Models\Weighing now(string $policy, string|array<string, mixed> $input, ?\Illuminate\Database\Eloquent\Model $subject = null)
 * @method static \Phoenix1331\Clorian\Drivers\Driver driver(?string $name = null)
 * @method static \Phoenix1331\Clorian\Clorian extend(string $name, \Closure $factory)
 * @method static \Phoenix1331\Clorian\Testing\ClorianFake answer(string $question, string|bool $value, float $probability)
 * @method static \Phoenix1331\Clorian\Testing\ClorianFake distribution(string $question, array<string, float> $probabilities)
 * @method static \Phoenix1331\Clorian\Testing\ClorianFake fail(string $message = 'The fake driver was told to fail.', bool $retryable = true)
 * @method static void assertWeighed(string $policy, \Closure|\Illuminate\Database\Eloquent\Model|null $filter = null)
 * @method static void assertNotWeighed(string $policy, \Closure|\Illuminate\Database\Eloquent\Model|null $filter = null)
 * @method static void assertWeighedTimes(string $policy, int $times, \Closure|\Illuminate\Database\Eloquent\Model|null $filter = null)
 * @method static void assertNothingWeighed()
 * @method static list<\Phoenix1331\Clorian\Models\Weighing> weighings(?string $policy = null)
 *
 * @see Manager
 * @see ClorianFake
 */
final class Clorian extends Facade
{
    /**
     * Score with a scripted FakeDriver and record every weighing. Nothing
     * leaves the app; weighings are still stored and policies applied.
     */
    public static function fake(): ClorianFake
    {
        $app = self::getFacadeApplication();

        if ($app === null) {
            throw new RuntimeException('Clorian::fake() needs a Laravel application.');
        }

        $driver = new FakeDriver;
        $app->instance(FakeDriver::class, $driver);
        $app->make('config')->set('clorian.driver', FakeDriver::NAME);

        $fake = new ClorianFake($app->make(Manager::class), $driver);
        Weighing::created(static fn (Weighing $weighing) => $fake->record($weighing));

        self::swap($fake);

        return $fake;
    }

    /**
     * An alias, not the class: swap() rebinds the accessor, and fake() must
     * not replace the manager that jobs and observers resolve by type.
     */
    protected static function getFacadeAccessor(): string
    {
        return Manager::ALIAS;
    }
}
