<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Questions;

/**
 * The thresholds one answer is judged against. Confidence at or above accept
 * is accepted, at or above review goes to a human, below review is
 * unresolved. A null accept means always review.
 *
 * Values are rounded to 4 decimals (the database precision), so comparisons
 * with Jev's 2-decimal probabilities are exact.
 */
final readonly class Thresholds
{
    public const int PRECISION = 4;

    public ?float $accept;

    public float $review;

    public function __construct(?float $accept, float $review)
    {
        $this->accept = $accept === null ? null : round($accept, self::PRECISION);
        $this->review = round($review, self::PRECISION);
    }

    public static function alwaysReview(float $review): self
    {
        return new self(null, $review);
    }

    public function alwaysReviews(): bool
    {
        return $this->accept === null;
    }

    /**
     * These thresholds with any values the overrides set on top.
     */
    public function with(ThresholdOverrides $overrides): self
    {
        return new self(
            $overrides->setsAccept() ? $overrides->acceptValue() : $this->accept,
            $overrides->reviewValue() ?? $this->review,
        );
    }

    /**
     * Why these thresholds cannot be used, or null when they are valid.
     */
    public function problem(): ?string
    {
        // Negated so NAN, which fails every comparison, is rejected too.
        if (! ($this->review >= 0.0 && $this->review <= 1.0)) {
            return sprintf('review must be between 0 and 1, got %s', self::format($this->review));
        }

        if ($this->accept === null) {
            return null;
        }

        if (! ($this->accept >= 0.0 && $this->accept <= 1.0)) {
            return sprintf('accept must be between 0 and 1, got %s', self::format($this->accept));
        }

        if ($this->review >= $this->accept) {
            return sprintf('review (%s) must be lower than accept (%s)', self::format($this->review), self::format($this->accept));
        }

        return null;
    }

    private static function format(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        return rtrim(rtrim(number_format($value, self::PRECISION, '.', ''), '0'), '.');
    }
}
