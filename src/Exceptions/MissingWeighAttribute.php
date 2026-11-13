<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Exceptions;

use LogicException;

/**
 * A model uses HasWeighings but does not say what to weigh.
 */
final class MissingWeighAttribute extends LogicException
{
    public static function on(string $model): self
    {
        return new self(sprintf('%s uses HasWeighings but has no #[Weigh] attribute. Add #[Weigh(YourPolicy::class, fields: [\'title\', \'body\'])] to the class.', $model));
    }

    public static function noFields(string $model): self
    {
        return new self(sprintf('The #[Weigh] attribute on %s lists no fields. Name the attributes to send, e.g. fields: [\'title\', \'body\'].', $model));
    }
}
