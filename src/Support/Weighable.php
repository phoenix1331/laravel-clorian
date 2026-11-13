<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Attributes\Weigh;
use Phoenix1331\Clorian\Exceptions\MissingWeighAttribute;
use Stringable;
use UnitEnum;

/**
 * Reads what to weigh from a model's #[Weigh] attribute. Shared by
 * HasWeighings, the observer and the job.
 *
 * @internal
 */
final class Weighable
{
    /**
     * @throws MissingWeighAttribute
     */
    public static function policy(Model $model): string
    {
        return Weigh::of($model)->policy;
    }

    /**
     * The #[Weigh] fields as sent to the driver: field => value, with enums,
     * dates and stringables turned into plain values.
     *
     * @return array<string, mixed>
     *
     * @throws MissingWeighAttribute
     */
    public static function input(Model $model): array
    {
        $input = [];

        foreach (Weigh::of($model)->fields as $field) {
            $value = $model->getAttribute($field);

            $input[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof UnitEnum => $value->name,
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
                $value instanceof Stringable => (string) $value,
                default => $value,
            };
        }

        return $input;
    }
}
