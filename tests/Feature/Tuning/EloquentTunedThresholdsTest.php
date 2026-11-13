<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Models\TunedThreshold;
use Phoenix1331\Clorian\Policies\PolicyRegistry;
use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Resolution\NoTunedThresholds;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Resolution\ThresholdResolver;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;
use Phoenix1331\Clorian\Tuning\EloquentTunedThresholds;

it('reads a policy\'s tuned thresholds in one query', function (): void {
    TunedThreshold::factory()->create(['policy' => TicketTriage::class, 'question' => 'urgency', 'accept' => 0.85, 'review' => 0.55]);
    TunedThreshold::factory()->forOption('critical')->alwaysReview()->create(['policy' => TicketTriage::class, 'question' => 'urgency', 'review' => null]);
    TunedThreshold::factory()->create(['policy' => 'App\Other', 'question' => 'urgency']);

    DB::enableQueryLog();
    $tuned = (new EloquentTunedThresholds)->forPolicy(TicketTriage::class);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(array_keys($tuned))->toBe(['urgency'])
        ->and(array_keys($tuned['urgency']))->toEqualCanonicalizing(['', 'critical']);

    $question = $tuned['urgency'][''];
    $critical = $tuned['urgency']['critical'];

    expect($question)->toBeInstanceOf(ThresholdOverrides::class)
        ->and($question->acceptValue())->toBe(0.85)
        ->and($question->reviewValue())->toBe(0.55)
        ->and($critical->setsAccept())->toBeTrue()
        ->and($critical->acceptValue())->toBeNull()
        ->and($critical->reviewValue())->toBeNull();
});

it('returns nothing for an untuned policy', function (): void {
    expect((new EloquentTunedThresholds)->forPolicy(TicketTriage::class))->toBe([])
        ->and((new NoTunedThresholds)->forPolicy(TicketTriage::class))->toBe([]);
});

it('lets database tuning beat the policy when resolving', function (): void {
    $registry = app(PolicyRegistry::class);
    $policy = $registry->get(TicketTriage::class);
    $resolver = new ThresholdResolver($registry->defaultThresholds());
    $scores = new Scores([
        'category' => ['billing' => 0.86, 'bug' => 0.14, 'account' => 0.0, 'feature' => 0.0],
        'urgency' => ['low' => 0.0, 'normal' => 0.01, 'high' => 0.13, 'critical' => 0.86],
        'needs_human' => ['true' => 0.9, 'false' => 0.1],
    ]);

    $before = $resolver->resolve(TicketTriage::class, $registry->questions($policy), $scores, (new EloquentTunedThresholds)->forPolicy(TicketTriage::class));

    TunedThreshold::factory()->create(['policy' => TicketTriage::class, 'question' => 'category', 'accept' => 0.85, 'review' => null]);
    TunedThreshold::factory()->forOption('critical')->create(['policy' => TicketTriage::class, 'question' => 'urgency', 'accept' => 0.80, 'review' => null]);
    TunedThreshold::factory()->forOption('true')->alwaysReview()->create(['policy' => TicketTriage::class, 'question' => 'needs_human', 'review' => null]);

    $after = $resolver->resolve(TicketTriage::class, $registry->questions($policy), $scores, (new EloquentTunedThresholds)->forPolicy(TicketTriage::class));

    expect([$before->category->status, $before->urgency->status, $before->needs_human->status])->toBe([Status::Review, Status::Review, Status::Accepted])
        ->and([$after->category->status, $after->urgency->status, $after->needs_human->status])->toBe([Status::Accepted, Status::Accepted, Status::Review])
        ->and($after->urgency->thresholds->accept)->toBe(0.8)
        ->and($after->urgency->thresholds->review)->toBe(0.6);
});
