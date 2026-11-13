<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Models\TunedThreshold;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\ContactIntent;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

/**
 * Triage history: 30 billing answers accepted and upheld at 0.95, 4 bug
 * answers in review overturned at 0.72, 3 unresolved account answers, and
 * 2 low urgency answers in review, unreviewed, at 0.65.
 */
function triageHistory(): Weighing
{
    $weighing = Weighing::factory()->policy(TicketTriage::class)->create();
    WeighingAnswer::factory()->for($weighing)->count(30)->accepted()->reviewed()->create();
    WeighingAnswer::factory()->for($weighing)->count(4)->needsReview()->reviewed(ReviewAction::Overturned, 'billing')->create();
    WeighingAnswer::factory()->for($weighing)->count(3)->unresolved()->create();
    WeighingAnswer::factory()->for($weighing)->count(2)
        ->answering('urgency', 'low', 0.65, ['low', 'normal', 'high', 'critical'])
        ->state(['status' => Status::Review])->create();

    return $weighing;
}

it('prints counts, rates, thresholds now and suggestions', function (): void {
    triageHistory();

    $this->artisan('clorian:stats')
        ->expectsOutputToContain(TicketTriage::class.', the last 30 days')
        ->expectsTable(
            ['Question', 'Option', 'Accepted', 'Review', 'Unresolved', 'Reviewed', 'Upheld', 'Overturned', 'Missed', 'Accept now', 'Suggested'],
            [
                ['category', 'account', '0', '0', '3', '0', '-', '-', '-', '0.90', '-'],
                ['category', 'billing', '30', '0', '0', '30', '100%', '0%', '0%', '0.90', '0.90'],
                ['category', 'bug', '0', '4', '0', '4', '0%', '100%', '0%', '0.90', '-'],
                ['urgency', 'low', '0', '2', '0', '0', '-', '-', '-', '0.75', '-'],
            ],
        )
        ->expectsTable(
            ['Question', 'Option', '0.60-0.70', '0.70-0.80', '0.80-0.90', '0.90+'],
            [
                ['category', 'account', '-', '-', '-', '-'],
                ['category', 'billing', '-', '-', '-', '100% of 30'],
                ['category', 'bug', '-', '0% of 4', '-', '-'],
                ['urgency', 'low', '2 unreviewed', '-', '-', '-'],
            ],
        )
        ->expectsOutputToContain('upheld at least 95% of the time over 30 or more reviews')
        ->expectsOutputToContain('php artisan clorian:tune')
        ->assertSuccessful();
});

it('shows always review and tuned values as the threshold now', function (): void {
    $weighing = Weighing::factory()->policy(TicketTriage::class)->create();
    WeighingAnswer::factory()->for($weighing)->answering('urgency', 'critical', 0.97, ['low', 'normal', 'high', 'critical'])->create();
    WeighingAnswer::factory()->for($weighing)->accepted()->create();
    TunedThreshold::factory()->create(['policy' => TicketTriage::class, 'question' => 'category', 'option' => null, 'accept' => 0.8, 'review' => null]);

    $this->artisan('clorian:stats')
        ->expectsTable(
            ['Question', 'Option', 'Accepted', 'Review', 'Unresolved', 'Reviewed', 'Upheld', 'Overturned', 'Missed', 'Accept now', 'Suggested'],
            [
                ['category', 'billing', '1', '0', '0', '0', '-', '-', '-', '0.80', '-'],
                ['urgency', 'critical', '1', '0', '0', '0', '-', '-', '-', 'always review', '-'],
            ],
        )
        ->assertSuccessful();
});

it('shows "-" for policies and questions that no longer exist', function (): void {
    WeighingAnswer::factory()->for(Weighing::factory()->policy('App\Policies\Retired'))->create();
    WeighingAnswer::factory()->for(Weighing::factory()->policy(TicketTriage::class))->answering('mood', 'calm', 0.9, ['calm', 'angry'])->create();
    WeighingAnswer::factory()->for(Weighing::factory()->policy(TicketTriage::class))->answering('category', 'refunds', 0.9, ['refunds', 'bug'])->create();

    $headers = ['Question', 'Option', 'Accepted', 'Review', 'Unresolved', 'Reviewed', 'Upheld', 'Overturned', 'Missed', 'Accept now', 'Suggested'];

    $this->artisan('clorian:stats')
        ->expectsOutputToContain('App\Policies\Retired, the last 30 days')
        ->expectsTable($headers, [['category', 'billing', '1', '0', '0', '0', '-', '-', '-', '-', '-']])
        ->expectsTable($headers, [
            ['category', 'refunds', '1', '0', '0', '0', '-', '-', '-', '-', '-'],
            ['mood', 'calm', '1', '0', '0', '0', '-', '-', '-', '-', '-'],
        ])
        ->assertSuccessful();
});

it('limits to one policy, with or without a leading backslash', function (string $policy): void {
    triageHistory();
    WeighingAnswer::factory()->for(Weighing::factory()->policy(ContactIntent::class))->answering('intent', 'sales', 0.9, ['sales', 'spam'])->create();

    $this->artisan('clorian:stats', ['policy' => $policy])
        ->expectsOutputToContain(ContactIntent::class)
        ->doesntExpectOutputToContain(TicketTriage::class)
        ->assertSuccessful();
})->with([ContactIntent::class, '\\'.ContactIntent::class]);

it('reports each policy in its own section', function (): void {
    triageHistory();
    WeighingAnswer::factory()->for(Weighing::factory()->policy(ContactIntent::class))->answering('intent', 'sales', 0.9, ['sales', 'spam'])->create();

    $this->artisan('clorian:stats')
        ->expectsOutputToContain(ContactIntent::class)
        ->expectsOutputToContain(TicketTriage::class)
        ->assertSuccessful();
});

it('counts only the last --days days, or everything with 0', function (): void {
    WeighingAnswer::factory()->for(Weighing::factory()->policy(TicketTriage::class))->create(['created_at' => now()->subDays(10)]);
    WeighingAnswer::factory()->for(Weighing::factory()->policy(TicketTriage::class))->create(['created_at' => now()->subDays(60)]);

    $this->artisan('clorian:stats', ['--days' => '30'])
        ->expectsOutputToContain('| category | billing | 1  ')
        ->assertSuccessful();

    $this->artisan('clorian:stats', ['--days' => '7'])
        ->expectsOutputToContain('No answers recorded in the last 7 days.')
        ->assertSuccessful();

    $this->artisan('clorian:stats', ['--days' => '0'])
        ->expectsOutputToContain('all time')
        ->expectsOutputToContain('| category | billing | 2  ')
        ->assertSuccessful();
});

it('says when there is nothing to show', function (): void {
    $this->artisan('clorian:stats', ['policy' => TicketTriage::class])
        ->expectsOutputToContain('No answers recorded for '.TicketTriage::class.' in the last 30 days.')
        ->assertSuccessful();
});

it('rejects a --days that is not a whole number', function (string $days): void {
    $this->artisan('clorian:stats', ['--days' => $days])
        ->expectsOutputToContain('--days must be a whole number of days, or 0 for all.')
        ->assertExitCode(2);
})->with(['-1', 'week', '1.5']);

it('uses the configured target and sample', function (): void {
    config()->set('clorian.calibration', ['target' => 0.9, 'min_sample' => 10]);
    triageHistory();

    $this->artisan('clorian:stats')
        ->expectsOutputToContain('upheld at least 90% of the time over 10 or more reviews')
        ->assertSuccessful();
});
