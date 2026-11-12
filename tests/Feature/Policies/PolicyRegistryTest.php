<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Policies\Policy;
use Phoenix1331\Clorian\Policies\PolicyRegistry;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Questions\Thresholds;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\ContactIntent;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

function registry(): PolicyRegistry
{
    return app(PolicyRegistry::class);
}

/**
 * @param  list<Question>  $questions
 * @param  array<string, mixed>  $defaults
 */
function policyWith(array $questions, array $defaults = []): string
{
    $policy = new class($questions, $defaults) extends Policy
    {
        /**
         * @param  list<Question>  $questions
         * @param  array<string, mixed>  $defaults
         */
        public function __construct(private array $questions = [], private array $defaults = []) {}

        public function questions(): array
        {
            return $this->questions;
        }

        public function defaults(): array
        {
            return $this->defaults;
        }
    };

    app()->instance($policy::class, $policy);

    return $policy::class;
}

it('is a singleton', function (): void {
    expect(registry())->toBe(registry());
});

it('resolves and validates the example policies', function (string $class): void {
    expect(registry()->get($class))->toBeInstanceOf($class);
})->with([TicketTriage::class, ContactIntent::class]);

it('resolves policies from the container', function (): void {
    $policy = new TicketTriage;
    app()->instance(TicketTriage::class, $policy);

    expect(registry()->get(TicketTriage::class))->toBe($policy);
});

it('validates each class once', function (): void {
    $class = policyWith([Question::choice('category', ['billing', 'bug'])]);
    registry()->get($class);

    config()->set('clorian.default_thresholds', ['accept' => 0.5, 'review' => 0.9]);

    expect(registry()->get($class))->toBeInstanceOf($class);

    registry()->flush();

    expect(fn () => registry()->get($class))->toThrow(InvalidThresholds::class, 'Invalid config [clorian.default_thresholds]');
});

it('rejects a class that is not a policy', function (string $class): void {
    expect(fn () => registry()->get($class))
        ->toThrow(InvalidThresholds::class, "Invalid policy [{$class}]: not a class extending Phoenix1331\\Clorian\\Policies\\Policy.");
})->with([stdClass::class, 'App\\Clorian\\Missing']);

it('rejects a container binding that is not a policy', function (): void {
    app()->bind(TicketTriage::class, fn () => new stdClass);

    expect(fn () => registry()->get(TicketTriage::class))
        ->toThrow(InvalidThresholds::class, 'the container resolved stdClass');
});

it('rejects a policy without questions', function (): void {
    expect(fn () => registry()->get(policyWith([])))
        ->toThrow(InvalidThresholds::class, 'questions() returned no questions');
});

it('rejects a question declared twice', function (): void {
    $class = policyWith([Question::boolean('spam'), Question::choice('spam', ['a', 'b'])]);

    expect(fn () => registry()->get($class))
        ->toThrow(InvalidThresholds::class, 'question [spam]: the question is declared twice');
});

it('validates every question against the config defaults', function (): void {
    $class = policyWith([
        Question::choice('urgency', ['low', 'high'])->option('high', fn (ThresholdOverrides $o) => $o->review(0.95)),
    ]);

    expect(fn () => registry()->get($class))
        ->toThrow(InvalidThresholds::class, 'question [urgency], option [high]: review (0.95) must be lower than accept (0.9)');
});

it('rejects defaults for questions the policy does not ask', function (): void {
    $class = policyWith([Question::boolean('spam')], ['urgency' => 'low']);

    expect(fn () => registry()->get($class))
        ->toThrow(InvalidThresholds::class, 'question [urgency]: defaults() names a question the policy does not ask');
});

it('rejects a choice default that is not an option', function (mixed $default): void {
    $class = policyWith([Question::choice('urgency', ['low', 'high'])], ['urgency' => $default]);

    expect(fn () => registry()->get($class))
        ->toThrow(InvalidThresholds::class, 'question [urgency]: the default must be null or one of the options (low, high)');
})->with(['medium', 1, true]);

it('rejects a boolean default that is not a boolean', function (mixed $default, string $type): void {
    $class = policyWith([Question::boolean('spam')], ['spam' => $default]);

    expect(fn () => registry()->get($class))
        ->toThrow(InvalidThresholds::class, "question [spam]: the default must be true, false or null, got {$type}");
})->with([['true', 'string'], [1, 'int']]);

it('accepts null defaults', function (): void {
    $class = policyWith([Question::boolean('spam'), Question::choice('urgency', ['low', 'high'])], ['spam' => null, 'urgency' => null]);

    expect(registry()->get($class))->toBeInstanceOf($class);
});

it('fills a default for every question, null where none is set', function (): void {
    expect(registry()->defaults(new TicketTriage))->toBe(['category' => null, 'urgency' => 'normal', 'needs_human' => false])
        ->and(registry()->defaults(new ContactIntent))->toBe(['intent' => null]);
});

it('keys questions by name in declared order', function (): void {
    expect(array_keys(registry()->questions(new TicketTriage)))->toBe(['category', 'urgency', 'needs_human']);
});

describe('default thresholds', function (): void {
    it('reads them from config', function (): void {
        expect(registry()->defaultThresholds())->toEqual(new Thresholds(0.9, 0.6));
    });

    it('accepts integers and a null accept', function (): void {
        config()->set('clorian.default_thresholds', ['accept' => null, 'review' => 0]);

        expect(registry()->defaultThresholds())->toEqual(Thresholds::alwaysReview(0.0));
    });

    it('rejects unusable config', function (mixed $value, string $reason): void {
        config()->set('clorian.default_thresholds', $value);

        expect(fn () => registry()->defaultThresholds())
            ->toThrow(InvalidThresholds::class, "Invalid config [clorian.default_thresholds]: {$reason}.");
    })->with([
        'not an array' => [0.9, 'expected an array with accept and review'],
        'missing review' => [['accept' => 0.9], 'accept must be a number or null, review a number'],
        'string accept' => [['accept' => '0.9', 'review' => 0.6], 'accept must be a number or null, review a number'],
        'crossed' => [['accept' => 0.5, 'review' => 0.6], 'review (0.6) must be lower than accept (0.5)'],
    ]);
});
