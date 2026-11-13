<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Phoenix1331\Clorian\Clorian as Manager;
use Phoenix1331\Clorian\Drivers\Driver;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Jobs\WeighSubject;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;

function jobTicket(): Ticket
{
    $ticket = Ticket::query()->create(['subject' => 'Charged twice', 'body' => 'Refund please']);
    $ticket->forceFill(['queue' => 'old', 'priority' => 'low'])->save();

    return $ticket;
}

function throwingDriver(DriverException $e): void
{
    config()->set('clorian.driver', 'throwing');

    app(Manager::class)->extend('throwing', fn (Container $app, array $config): Driver => new class($e) implements Driver
    {
        public function __construct(private readonly DriverException $e) {}

        public function name(): string
        {
            return 'throwing';
        }

        public function score(array $questions, string|array $content, array $context, ?float $timeout = null): Scores
        {
            throw $this->e;
        }
    });
}

function runJob(Ticket $ticket, int $attempt = 1): WeighSubject
{
    $job = (new WeighSubject($ticket))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    $job->handle(app(Manager::class));

    return $job;
}

it('weighs the subject with the driver\'s own timeout', function (): void {
    Clorian::fake()->answer('category', 'billing', 0.95);
    $ticket = jobTicket();

    $job = runJob($ticket);

    $job->assertNotFailed();
    $job->assertNotReleased();

    expect(Weighing::query()->sole()->status)->toBe(WeighingStatus::Complete)
        ->and(app(FakeDriver::class)->calls()[0]['timeout'])->toBeNull()
        ->and(app(FakeDriver::class)->calls()[0]['content'])->toBe(['subject' => 'Charged twice', 'body' => 'Refund please'])
        ->and($ticket->refresh()->queue)->toBe('billing');
});

it('releases retryable failures with backoff and stores nothing yet', function (int $attempt, int $delay): void {
    throwingDriver(DriverException::retryable('Jev returned HTTP 529: Overloaded', 529));

    runJob(jobTicket(), $attempt)->assertReleased($delay);

    expect(Weighing::query()->count())->toBe(0);
})->with([
    'first attempt' => [1, 10],
    'second attempt' => [2, 60],
]);

it('honours retry-after', function (): void {
    throwingDriver(DriverException::retryable('Jev returned HTTP 429: Slow down', 429, retryAfter: 7));

    runJob(jobTicket())->assertReleased(7);
});

it('fails on the last attempt', function (): void {
    $e = DriverException::retryable('Jev returned HTTP 529: Overloaded', 529);
    throwingDriver($e);

    $job = runJob(jobTicket(), 3);

    $job->assertFailedWith($e);
    $job->assertNotReleased();
});

it('fails at once on a permanent failure', function (): void {
    $e = DriverException::permanent('Jev returned HTTP 401: bad key. Check JEV_API_KEY.', 401);
    throwingDriver($e);

    $job = runJob(jobTicket());

    $job->assertFailedWith($e);
    $job->assertNotReleased();
});

it('stores a failed weighing and applies the defaults after the last attempt', function (): void {
    Clorian::fake();
    $ticket = jobTicket();

    (new WeighSubject($ticket))->failed(DriverException::retryable('Jev returned HTTP 529: Overloaded', 529));

    $weighing = Weighing::query()->sole();

    expect($weighing->status)->toBe(WeighingStatus::Failed)
        ->and($weighing->error)->toBe('Jev returned HTTP 529: Overloaded')
        ->and($weighing->subject?->is($ticket))->toBeTrue()
        ->and($weighing->answers()->count())->toBe(0)
        ->and($ticket->refresh()->queue)->toBe('triage')
        ->and($ticket->priority)->toBe('normal');
});

it('keeps only the class of other exceptions, which may quote content', function (?Throwable $e, string $error): void {
    Clorian::fake();

    (new WeighSubject(jobTicket()))->failed($e);

    expect(Weighing::query()->sole()->error)->toBe($error);
})->with([
    'other exception' => [new RuntimeException('Refund please'), 'The weighing job failed with RuntimeException.'],
    'no exception' => [null, 'The weighing job failed.'],
]);

it('retries three times and drops jobs for deleted models', function (): void {
    $job = new WeighSubject(jobTicket());

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60])
        ->and($job->deleteWhenMissingModels)->toBeTrue();
});
