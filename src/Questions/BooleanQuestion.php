<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Questions;

/**
 * A yes or no question. Jev returns the probability of true; the answer is
 * "true" at 0.5 or above, else "false", judged on the probability of that
 * value. So accept(0.7) accepts above 0.7 or below 0.3, and
 * option('true', fn ($o) => $o->alwaysReview()) sends every yes to a human.
 */
final class BooleanQuestion extends Question
{
    public const string TRUE = 'true';

    public const string FALSE = 'false';

    private ?string $whenTrue = null;

    private ?string $whenFalse = null;

    public function __construct(string $name, ?string $instructions = null)
    {
        parent::__construct($name);

        $this->instructions = $instructions ?? self::humanise($name);
    }

    /**
     * What counts as a yes and as a no, sent to the model with the instructions.
     */
    public function when(?string $true = null, ?string $false = null): self
    {
        $this->whenTrue = $true;
        $this->whenFalse = $false;

        return $this;
    }

    public function options(): array
    {
        return [self::TRUE, self::FALSE];
    }

    public function descriptions(): array
    {
        return [self::TRUE => $this->whenTrue, self::FALSE => $this->whenFalse];
    }

    /**
     * The answer for a probability of true, and the probability of that answer.
     *
     * @return array{0: string, 1: float}
     */
    public static function answerFor(float $probabilityOfTrue): array
    {
        return $probabilityOfTrue >= 0.5
            ? [self::TRUE, $probabilityOfTrue]
            : [self::FALSE, round(1.0 - $probabilityOfTrue, Thresholds::PRECISION)];
    }

    private static function humanise(string $name): string
    {
        return ucfirst(trim((string) preg_replace('/[\s_\-]+/', ' ', $name)));
    }
}
