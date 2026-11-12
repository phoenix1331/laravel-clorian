<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Phoenix1331\Clorian\ClorianServiceProvider;
use Phoenix1331\Clorian\Models\TunedThreshold;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;

/**
 * @return list<Migration>
 */
function clorianMigrations(): array
{
    $files = glob(__DIR__.'/../../../database/migrations/*.php') ?: [];
    sort($files);

    return array_map(static fn (string $file): Migration => require $file, $files);
}

it('creates the three tables with their columns', function (): void {
    expect(Schema::getColumnListing('clorian_weighings'))->toEqualCanonicalizing([
        'id', 'subject_type', 'subject_id', 'policy', 'model_version', 'driver', 'status',
        'content_hash', 'input', 'error', 'meta', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('clorian_answers'))->toEqualCanonicalizing([
        'id', 'weighing_id', 'question', 'value', 'confidence', 'scores', 'accept_threshold',
        'review_threshold', 'status', 'review_action', 'reviewed_value', 'reviewer_type',
        'reviewer_id', 'reviewed_at', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('clorian_thresholds'))->toEqualCanonicalizing([
        'id', 'policy', 'question', 'option', 'accept', 'review', 'tuned_by_type',
        'tuned_by_id', 'created_at', 'updated_at',
    ]);
});

it('indexes the columns stats and review queues filter on', function (): void {
    $indexes = fn (string $table): array => collect(Schema::getIndexes($table))->pluck('columns')->all();

    expect($indexes('clorian_weighings'))->toContain(['policy', 'created_at'], ['subject_type', 'subject_id'])
        ->and($indexes('clorian_answers'))->toContain(['status', 'created_at'], ['question', 'value', 'review_action'], ['weighing_id'])
        ->and($indexes('clorian_thresholds'))->toContain(['policy', 'question', 'option']);
});

it('rolls back and migrates again cleanly', function (): void {
    $migrations = clorianMigrations();

    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }

    expect(Schema::hasTable('clorian_weighings'))->toBeFalse()
        ->and(Schema::hasTable('clorian_answers'))->toBeFalse()
        ->and(Schema::hasTable('clorian_thresholds'))->toBeFalse();

    foreach ($migrations as $migration) {
        $migration->up();
    }

    expect(Schema::hasTable('clorian_weighings'))->toBeTrue()
        ->and(Schema::hasTable('clorian_answers'))->toBeTrue()
        ->and(Schema::hasTable('clorian_thresholds'))->toBeTrue();
});

it('deletes answers with their weighing', function (): void {
    $answer = WeighingAnswer::factory()->create();

    $answer->weighing->delete();

    expect(WeighingAnswer::query()->count())->toBe(0);
});

it('lets a subject be deleted without touching its weighings', function (): void {
    $ticket = Ticket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);
    Weighing::factory()->forSubject($ticket)->create();

    $ticket->delete();

    expect(Weighing::query()->count())->toBe(1);
});

it('allows one tuned threshold per policy, question and option', function (): void {
    TunedThreshold::factory()->create();
    TunedThreshold::factory()->forOption('critical')->create();

    expect(fn () => TunedThreshold::factory()->create())->toThrow(QueryException::class);
});

it('stores the whole question as an empty option so the unique key holds', function (): void {
    TunedThreshold::factory()->create();

    expect(DB::table('clorian_thresholds')->value('option'))->toBe('');
});

it('publishes the migrations under their tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(ClorianServiceProvider::class, 'clorian-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('database/migrations');
});
