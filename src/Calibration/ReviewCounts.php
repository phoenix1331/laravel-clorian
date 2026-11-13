<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Calibration;

use Phoenix1331\Clorian\Reviews\ReviewAction;

/**
 * How humans judged a set of answers. Rates are shares of the reviewed
 * answers, null when none were reviewed; missed counts against upheld.
 */
final readonly class ReviewCounts
{
    public function __construct(
        public int $upheld = 0,
        public int $overturned = 0,
        public int $missed = 0,
    ) {}

    public function with(ReviewAction $action): self
    {
        return new self(
            $this->upheld + ($action === ReviewAction::Upheld ? 1 : 0),
            $this->overturned + ($action === ReviewAction::Overturned ? 1 : 0),
            $this->missed + ($action === ReviewAction::Missed ? 1 : 0),
        );
    }

    public function plus(self $other): self
    {
        return new self($this->upheld + $other->upheld, $this->overturned + $other->overturned, $this->missed + $other->missed);
    }

    public function reviewed(): int
    {
        return $this->upheld + $this->overturned + $this->missed;
    }

    public function upheldRate(): ?float
    {
        return $this->rate($this->upheld);
    }

    public function overturnedRate(): ?float
    {
        return $this->rate($this->overturned);
    }

    public function missedRate(): ?float
    {
        return $this->rate($this->missed);
    }

    private function rate(int $count): ?float
    {
        return $this->reviewed() === 0 ? null : $count / $this->reviewed();
    }
}
