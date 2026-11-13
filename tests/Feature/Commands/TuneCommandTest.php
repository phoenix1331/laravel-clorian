<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Models\TunedThreshold;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Reviewer;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;
use Phoenix1331\Clorian\Tuning\EloquentTunedThresholds;

/**
 * @param  array<string, string>  $options
 * @return array<string, mixed>
 */
function tune(string $question, array $options = [], string $policy = TicketTriage::class): array
{
    return ['policy' => $policy, 'question' => $question, ...$options];
}

/**
 * @return array{0: float|null, 1: float|null}|null
 */
function tunedRow(string $question, ?string $option = null): ?array
{
    $row = TunedThreshold::query()->where('policy', TicketTriage::class)->where('question', $question)
        ->where('option', $option ?? TunedThreshold::WHOLE_QUESTION)->first();

    return $row === null ? null : [$row->accept, $row->review];
}

it('shows before and after for one option, then saves on confirm', function (): void {
    $this->artisan('clorian:tune', tune('urgency', ['--option' => 'high', '--accept' => '0.8']))
        ->expectsOutputToContain(TicketTriage::class.', urgency, high')
        ->expectsTable(['Option', 'Accept', 'Review'], [['high', '0.90 -> 0.80', '0.60']])
        ->expectsConfirmation('Save these thresholds?', 'yes')
        ->expectsOutputToContain('Saved.')
        ->assertSuccessful();

    expect(tunedRow('urgency', 'high'))->toBe([0.8, null]);
});

it('writes nothing when the confirm is declined', function (): void {
    $this->artisan('clorian:tune', tune('urgency', ['--option' => 'high', '--accept' => '0.8']))
        ->expectsConfirmation('Save these thresholds?', 'no')
        ->expectsOutputToContain('Nothing saved.')
        ->assertSuccessful();

    expect(TunedThreshold::query()->count())->toBe(0);
});

it('saves without asking under --no-interaction', function (): void {
    $this->artisan('clorian:tune', tune('category', ['--accept' => '0.85', '--review' => '0.5', '--no-interaction' => true]))
        ->expectsTable(['Option', 'Accept', 'Review'], [
            ['billing', '0.90 -> 0.85', '0.60 -> 0.50'],
            ['bug', '0.90 -> 0.85', '0.60 -> 0.50'],
            ['account', '0.90 -> 0.85', '0.60 -> 0.50'],
            ['feature', '0.90 -> 0.85', '0.60 -> 0.50'],
        ])
        ->assertSuccessful();

    expect(tunedRow('category'))->toBe([0.85, 0.5]);
});

it('feeds the thresholds Clorian resolves with', function (): void {
    $this->artisan('clorian:tune', tune('urgency', ['--option' => 'low', '--accept' => '0.95', '--no-interaction' => true]))->assertSuccessful();

    expect((new EloquentTunedThresholds)->forPolicy(TicketTriage::class)['urgency']['low']->acceptValue())->toBe(0.95);
});

it('sets always review with --accept=never', function (): void {
    $this->artisan('clorian:tune', tune('category', ['--option' => 'billing', '--accept' => 'NEVER', '--no-interaction' => true]))
        ->expectsTable(['Option', 'Accept', 'Review'], [['billing', '0.90 -> always review', '0.60']])
        ->assertSuccessful();

    expect(tunedRow('category', 'billing'))->toBe([null, null]);
});

it('warns when an option would stop always going to review', function (): void {
    $this->artisan('clorian:tune', tune('urgency', ['--accept' => '0.8', '--no-interaction' => true]))
        ->expectsOutputToContain('| critical | always review -> 0.80 | 0.60   |')
        ->expectsOutputToContain('[critical] always went to review; it would now be accepted automatically. Tune it with --option=critical --accept=never to keep it.')
        ->assertSuccessful();
});

it('keeps the stored accept when only --review changes', function (): void {
    TunedThreshold::factory()->create(['policy' => TicketTriage::class, 'question' => 'category', 'option' => 'bug', 'accept' => 0.8, 'review' => null]);

    $this->artisan('clorian:tune', tune('category', ['--option' => 'bug', '--review' => '0.5', '--no-interaction' => true]))
        ->expectsTable(['Option', 'Accept', 'Review'], [['bug', '0.80', '0.60 -> 0.50']])
        ->assertSuccessful();

    expect(tunedRow('category', 'bug'))->toBe([0.8, 0.5])
        ->and(TunedThreshold::query()->count())->toBe(1);
});

it('replaces an earlier tune', function (): void {
    TunedThreshold::factory()->create(['policy' => TicketTriage::class, 'question' => 'category', 'option' => 'bug', 'accept' => 0.8, 'review' => 0.5]);

    $this->artisan('clorian:tune', tune('category', ['--option' => 'bug', '--accept' => '0.7', '--no-interaction' => true]))
        ->expectsTable(['Option', 'Accept', 'Review'], [['bug', '0.80 -> 0.70', '0.50']])
        ->assertSuccessful();

    expect(tunedRow('category', 'bug'))->toBe([0.7, 0.5]);
});

it('changes nothing when the values are already set', function (): void {
    TunedThreshold::factory()->create(['policy' => TicketTriage::class, 'question' => 'category', 'option' => 'bug', 'accept' => 0.8, 'review' => null]);

    $this->artisan('clorian:tune', tune('category', ['--option' => 'bug', '--accept' => '0.8']))
        ->expectsOutputToContain('These thresholds are already set. Nothing to change.')
        ->assertSuccessful();
});

it('records who tuned it when someone is signed in', function (): void {
    $reviewer = Reviewer::query()->create(['name' => 'Sam']);
    $this->actingAs($reviewer);

    $this->artisan('clorian:tune', tune('category', ['--accept' => '0.85', '--no-interaction' => true]))->assertSuccessful();

    $row = TunedThreshold::query()->sole();

    expect($row->tuned_by_type)->toBe($reviewer->getMorphClass())
        ->and($row->tuned_by_id)->toBe($reviewer->id)
        ->and($row->tunedBy?->is($reviewer))->toBeTrue();
});

it('leaves tuned_by empty from the console', function (): void {
    $this->artisan('clorian:tune', tune('category', ['--accept' => '0.85', '--no-interaction' => true]))->assertSuccessful();

    expect(TunedThreshold::query()->sole()->tuned_by_type)->toBeNull();
});

it('rejects input it cannot use and writes nothing', function (array $arguments, string $message, int $code): void {
    $this->artisan('clorian:tune', [...$arguments, '--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertExitCode($code);

    expect(TunedThreshold::query()->count())->toBe(0);
})->with([
    'unknown policy' => [tune('category', ['--accept' => '0.8'], 'App\Policies\Missing'), 'App\Policies\Missing', 1],
    'unknown question' => [tune('mood', ['--accept' => '0.8']), TicketTriage::class.' has no question [mood]. Questions: category, urgency, needs_human.', 1],
    'unknown option' => [tune('urgency', ['--option' => 'urgent', '--accept' => '0.8']), '[urgent] is not an option of [urgency]. Options: low, normal, high, critical.', 1],
    'nothing to set' => [tune('category'), 'Pass --accept, --review or both.', 1],
    'accept not a number' => [tune('category', ['--accept' => 'high']), '--accept must be between 0 and 1, or "never", got "high".', 2],
    'accept above 1' => [tune('category', ['--accept' => '1.5']), '--accept must be between 0 and 1', 2],
    'review below 0' => [tune('category', ['--accept' => '0.9', '--review' => '-0.1']), '--review must be between 0 and 1, got "-0.1".', 2],
    'review alone on a new tune' => [tune('category', ['--review' => '0.5']), 'A new tuned threshold needs --accept too', 1],
    'review above accept' => [tune('category', ['--option' => 'bug', '--accept' => '0.5']), 'review (0.6) must be lower than accept (0.5)', 1],
    'boolean option' => [tune('needs_human', ['--option' => 'yes', '--accept' => '0.8']), 'Options: true, false.', 1],
]);
