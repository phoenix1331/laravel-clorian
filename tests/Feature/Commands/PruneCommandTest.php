<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Phoenix1331\Clorian\Calibration\Calibrator;
use Phoenix1331\Clorian\Calibration\Observations;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Tests\Fixtures\Models\ArchivableTicket;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

function pruneTicket(): Ticket
{
    return Ticket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);
}

/**
 * A weighing with one answer and stored input, made $days ago.
 */
function agedWeighing(int $days, ?Ticket $subject = null): Weighing
{
    $factory = Weighing::factory()->policy(TicketTriage::class)->withInput(['content' => 'Refund please']);
    $weighing = ($subject === null ? $factory : $factory->forSubject($subject))->create(['created_at' => now()->subDays($days)]);
    WeighingAnswer::factory()->for($weighing)->reviewed()->create(['created_at' => now()->subDays($days)]);

    return $weighing;
}

it('deletes weighings and their answers past weighings_after_days', function (): void {
    agedWeighing(91);
    $kept = agedWeighing(89);

    $this->artisan('clorian:prune')
        ->expectsTable(['Rule', 'Changed'], [
            ['Weighings of deleted subjects', '0'],
            ['Weighings older than 90 days', '1'],
            ['Stored input older than 30 days cleared', '1'],
        ])
        ->expectsOutputToContain('Pruned.')
        ->assertSuccessful();

    expect(Weighing::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(WeighingAnswer::query()->count())->toBe(1);
});

it('clears stored input past inputs_after_days and keeps the stats', function (): void {
    $old = agedWeighing(31);
    $fresh = agedWeighing(29);
    $before = app(Calibrator::class)->calibrate(app(Observations::class)->read());

    $this->artisan('clorian:prune')->assertSuccessful();

    expect($old->refresh()->input)->toBeNull()
        ->and($fresh->refresh()->input)->toBe(['content' => 'Refund please'])
        ->and(WeighingAnswer::query()->count())->toBe(2)
        ->and(app(Calibrator::class)->calibrate(app(Observations::class)->read()))->toEqual($before);
});

it('deletes weighings whose subject was deleted', function (): void {
    $gone = pruneTicket();
    $kept = pruneTicket();
    $orphan = agedWeighing(1, $gone);
    agedWeighing(1, $kept);
    agedWeighing(1);
    $gone->delete();

    $this->artisan('clorian:prune')
        ->expectsOutputToContain('Weighings of deleted subjects')
        ->assertSuccessful();

    expect(Weighing::query()->count())->toBe(2)
        ->and(Weighing::query()->find($orphan->id))->toBeNull()
        ->and(WeighingAnswer::query()->where('weighing_id', $orphan->id)->count())->toBe(0);
});

it('keeps weighings of soft-deleted subjects', function (): void {
    $ticket = ArchivableTicket::query()->create(['subject' => 'Old', 'body' => 'Archived']);
    agedWeighing(1)->subject()->associate($ticket)->save();
    $ticket->delete();

    expect($ticket->trashed())->toBeTrue();

    $this->artisan('clorian:prune')->assertSuccessful();

    expect(Weighing::query()->count())->toBe(1);
});

it('resolves subjects through the morph map', function (): void {
    Relation::morphMap(['ticket' => Ticket::class]);

    try {
        $gone = pruneTicket();
        $weighing = agedWeighing(1, $gone);

        expect($weighing->subject_type)->toBe('ticket');

        $gone->delete();
        $this->artisan('clorian:prune')->assertSuccessful();

        expect(Weighing::query()->count())->toBe(0);
    } finally {
        Relation::morphMap([], merge: false);
    }
});

it('leaves weighings alone when their subject type no longer resolves', function (): void {
    agedWeighing(1)->forceFill(['subject_type' => 'App\Models\Retired', 'subject_id' => 5])->save();

    $this->artisan('clorian:prune')
        ->expectsOutputToContain('Skipped weighings for [App\Models\Retired]: not a model class or morph map alias.')
        ->assertSuccessful();

    expect(Weighing::query()->count())->toBe(1);
});

it('keeps every weighing of a subject that still exists', function (): void {
    $kept = pruneTicket();

    foreach (range(1, 3) as $i) {
        agedWeighing(1, $kept);
    }

    $gone = pruneTicket();
    agedWeighing(1, $gone);
    $gone->delete();

    $this->artisan('clorian:prune')->assertSuccessful();

    expect(Weighing::query()->count())->toBe(3);
});

it('counts without changing anything under --pretend', function (): void {
    agedWeighing(100);
    $gone = pruneTicket();
    agedWeighing(1, $gone);
    $gone->delete();

    $this->artisan('clorian:prune', ['--pretend' => true])
        ->expectsTable(['Rule', 'Would change'], [
            ['Weighings of deleted subjects', '1'],
            ['Weighings older than 90 days', '1'],
            ['Stored input older than 30 days cleared', '1'],
        ])
        ->expectsOutputToContain('Nothing changed (--pretend).')
        ->assertSuccessful();

    expect(Weighing::query()->count())->toBe(2)
        ->and(Weighing::query()->whereNotNull('input')->count())->toBe(2);
});

it('keeps forever when a rule is null', function (): void {
    config()->set('clorian.prune', ['weighings_after_days' => null, 'inputs_after_days' => null]);
    agedWeighing(400);

    $this->artisan('clorian:prune')
        ->expectsTable(['Rule', 'Changed'], [
            ['Weighings of deleted subjects', '0'],
            ['Old weighings (kept: weighings_after_days is null)', '0'],
            ['Old stored input (kept: inputs_after_days is null)', '0'],
        ])
        ->assertSuccessful();

    expect(Weighing::query()->whereNotNull('input')->count())->toBe(1);
});

it('reads day counts given as strings', function (): void {
    config()->set('clorian.prune', ['weighings_after_days' => '10', 'inputs_after_days' => '5']);
    agedWeighing(11);

    $this->artisan('clorian:prune')->expectsOutputToContain('Weighings older than 10 days')->assertSuccessful();

    expect(Weighing::query()->count())->toBe(0);
});

it('rejects day counts it cannot use', function (mixed $days): void {
    config()->set('clorian.prune.inputs_after_days', $days);

    $this->artisan('clorian:prune')
        ->expectsOutputToContain('must be whole numbers of days above 0, or null to keep forever.')
        ->assertExitCode(2);
})->with([0, -5, 'month', 1.5]);
