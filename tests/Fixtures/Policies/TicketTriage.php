<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Policies;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Policies\Policy;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Resolution\Answers;

/**
 * The ticket triage policy from the README.
 */
final class TicketTriage extends Policy
{
    public function questions(): array
    {
        return [
            Question::choice('category', ['billing', 'bug', 'account', 'feature']),

            Question::choice('urgency', ['low', 'normal', 'high', 'critical'])
                ->accept(0.90)->review(0.60)
                ->option('critical', fn (ThresholdOverrides $o) => $o->alwaysReview())
                ->option('low', fn (ThresholdOverrides $o) => $o->accept(0.75)),

            // accept(0.60) would equal the default review threshold (0.60), which validation rejects.
            Question::boolean('needs_human')->accept(0.70),
        ];
    }

    public function defaults(): array
    {
        return ['category' => null, 'urgency' => 'normal', 'needs_human' => false];
    }

    public function context(?Model $subject): array
    {
        return ['plan' => 'team'];
    }

    public function apply(?Model $subject, Answers $answers): void
    {
        $subject?->update([
            'queue' => $answers->category ?? 'triage',
            'priority' => $answers->urgency,
        ]);
    }
}
