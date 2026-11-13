<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Exceptions;

use InvalidArgumentException;

/**
 * A review that does not fit its answer: a value the question cannot take,
 * or an action the value contradicts. Nothing is recorded.
 */
final class InvalidReview extends InvalidArgumentException
{
    public static function upheldWithOtherValue(string $question, string $value, string $given): self
    {
        return new self(sprintf('Upholding [%s] keeps its value [%s]; to choose [%s], overturn it instead.', $question, $value, $given));
    }

    public static function overturnedWithoutValue(string $question): self
    {
        return new self(sprintf('Overturning [%s] needs the right value. Pass one of its options, or mark it missed if none fits.', $question));
    }

    public static function overturnedToSameValue(string $question, string $value): self
    {
        return new self(sprintf('Overturning [%s] to [%s] keeps the same value; uphold it instead.', $question, $value));
    }

    public static function missedWithValue(string $question): self
    {
        return new self(sprintf('Marking [%s] missed means no option fits, so it takes no value. To choose one, overturn it instead.', $question));
    }

    /**
     * @param  list<string>  $options
     */
    public static function notAnOption(string $question, string $value, array $options): self
    {
        return new self(sprintf('[%s] is not an option of [%s]. Options: %s.', $value, $question, implode(', ', $options)));
    }

    public static function unknownQuestion(string $policy, string $question): self
    {
        return new self(sprintf('%s no longer asks [%s], so the answer cannot be reviewed.', $policy, $question));
    }
}
