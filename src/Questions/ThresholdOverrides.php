<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Questions;

/**
 * Thresholds a question or option sets on top of the level below it. Anything
 * left unset falls through: option to question, question to config defaults.
 * Passed to the closure in Question::option().
 */
final class ThresholdOverrides
{
    private bool $setsAccept = false;

    private ?float $accept = null;

    private ?float $review = null;

    public function accept(float $accept): self
    {
        $this->setsAccept = true;
        $this->accept = $accept;

        return $this;
    }

    public function review(float $review): self
    {
        $this->review = $review;

        return $this;
    }

    /**
     * Never accept automatically: anything at or above review goes to a human.
     */
    public function alwaysReview(): self
    {
        $this->setsAccept = true;
        $this->accept = null;

        return $this;
    }

    public function setsAccept(): bool
    {
        return $this->setsAccept;
    }

    public function setsAnything(): bool
    {
        return $this->setsAccept || $this->review !== null;
    }

    /**
     * Only meaningful when setsAccept() is true; null then means always review.
     */
    public function acceptValue(): ?float
    {
        return $this->accept;
    }

    public function reviewValue(): ?float
    {
        return $this->review;
    }
}
