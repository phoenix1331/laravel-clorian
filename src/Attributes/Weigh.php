<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Attributes;

use Attribute;
use Phoenix1331\Clorian\Exceptions\MissingWeighAttribute;
use ReflectionClass;

/**
 * Opts a model in to weighing: which policy to run and which attributes to
 * send. Pair it with the HasWeighings trait.
 *
 *     #[Weigh(TicketTriage::class, fields: ['subject', 'body'])]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Weigh
{
    /**
     * @param  string  $policy  the Policy class
     * @param  list<string>  $fields  model attributes sent as the content
     */
    public function __construct(
        public string $policy,
        public array $fields,
    ) {}

    /**
     * The attribute on a model class (or its parents).
     *
     * @param  class-string|object  $model
     *
     * @throws MissingWeighAttribute when there is none, or it lists no fields
     */
    public static function of(string|object $model): self
    {
        $class = is_object($model) ? $model::class : $model;
        $reflection = new ReflectionClass($class);

        do {
            $attributes = $reflection->getAttributes(self::class);

            if ($attributes !== []) {
                $weigh = $attributes[0]->newInstance();

                if ($weigh->fields === []) {
                    throw MissingWeighAttribute::noFields($class);
                }

                return $weigh;
            }

            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        throw MissingWeighAttribute::on($class);
    }
}
