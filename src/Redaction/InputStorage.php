<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Redaction;

use InvalidArgumentException;

/**
 * clorian.store_input: when the redacted input is kept, encrypted, on a
 * weighing. Model weighings can read their content from the model, so by
 * default only subjectless weighings keep it.
 */
enum InputStorage: string
{
    /** Never: only the content hash is kept. */
    case None = 'none';

    /** Only for weighings without a subject (the default). */
    case Subjectless = 'subjectless';

    /** Every weighing. */
    case Always = 'always';

    /**
     * @throws InvalidArgumentException for an unknown value
     */
    public static function fromConfig(mixed $value): self
    {
        $storage = is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;

        if ($storage === null) {
            throw new InvalidArgumentException(sprintf(
                'Set clorian.store_input (CLORIAN_STORE_INPUT) to "none", "subjectless" or "always", got %s.',
                is_scalar($value) ? '"'.$value.'"' : get_debug_type($value),
            ));
        }

        return $storage;
    }

    public function stores(bool $hasSubject): bool
    {
        return match ($this) {
            self::None => false,
            self::Subjectless => ! $hasSubject,
            self::Always => true,
        };
    }
}
