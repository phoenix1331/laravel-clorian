<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Phoenix1331\Clorian\Clorian as Manager;
use Phoenix1331\Clorian\Drivers\Driver;
use Phoenix1331\Clorian\Drivers\FakeDriver;
use Phoenix1331\Clorian\Drivers\JevDriver;
use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Models\TunedThreshold;
use Phoenix1331\Clorian\Models\Weighing;
use Phoenix1331\Clorian\Models\WeighingAnswer;
use Phoenix1331\Clorian\Models\WeighingStatus;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\ContactIntent;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

beforeEach(function (): void {
    config()->set('clorian.driver', 'fake');
});

function fakeDriver(): FakeDriver
{
    return app(FakeDriver::class);
}

function triageTicket(): Ticket
{
    return Ticket::query()->create(['subject' => 'Charged twice', 'body' => 'Please refund the second payment.']);
}

/**
 * @return array<string, array{0: string|null, 1: Status}>
 */
function answerSummary(Weighing $weighing): array
{
    return $weighing->answers->mapWithKeys(static fn (WeighingAnswer $a): array => [$a->question => [$a->value, $a->status]])->all();
}

it('weighs a subjectless input and stores the answers', function (): void {
    fakeDriver()->answer('intent', 'account_access', 0.95);

    $weighing = Clorian::now(ContactIntent::class, 'I cannot log in');

    expect($weighing->exists)->toBeTrue()
        ->and($weighing->subject_type)->toBeNull()
        ->and($weighing->policy)->toBe(ContactIntent::class)
        ->and($weighing->driver)->toBe('fake')
        ->and($weighing->model_version)->toBe('fake')
        ->and($weighing->status)->toBe(WeighingStatus::Complete)
        ->and($weighing->error)->toBeNull()
        ->and($weighing->meta)->toBe(['latency_ms' => 0])
        ->and($weighing->content_hash)->toBe(hash('sha256', '"I cannot log in"'))
        ->and($weighing->accepted('intent', 'account_access'))->toBeTrue()
        ->and($weighing->accepted('intent', 'billing'))->toBeFalse();

    $answer = WeighingAnswer::query()->sole();

    expect($answer->weighing_id)->toBe($weighing->id)
        ->and($answer->question)->toBe('intent')
        ->and($answer->value)->toBe('account_access')
        ->and($answer->confidence)->toBe(0.95)
        ->and($answer->scores)->toHaveKey('account_access', 0.95)
        ->and($answer->accept_threshold)->toBe(0.9)
        ->and($answer->review_threshold)->toBe(0.6)
        ->and($answer->status)->toBe(Status::Accepted);

    expect(fakeDriver()->calls())->toHaveCount(1)
        ->and(fakeDriver()->calls()[0]['content'])->toBe('I cannot log in')
        ->and(fakeDriver()->calls()[0]['context'])->toBe([])
        ->and(fakeDriver()->calls()[0]['timeout'])->toBe(2.0);
});

it('weighs a model, sends the policy context and applies only accepted answers', function (): void {
    $ticket = triageTicket();
    fakeDriver()->answer('category', 'billing', 0.95)->answer('urgency', 'critical', 0.99)->answer('needs_human', true, 0.9);

    $weighing = Clorian::now(TicketTriage::class, ['subject' => $ticket->subject, 'body' => $ticket->body], $ticket);

    expect($weighing->subject_type)->toBe($ticket->getMorphClass())
        ->and($weighing->subject_id)->toBe($ticket->id)
        ->and($weighing->subject?->is($ticket))->toBeTrue()
        ->and(answerSummary($weighing))->toBe([
            'category' => ['billing', Status::Accepted],
            'urgency' => ['critical', Status::Review],
            'needs_human' => ['true', Status::Accepted],
        ])
        ->and(fakeDriver()->calls()[0]['context'])->toBe(['plan' => 'team']);

    // critical is always review: the default urgency applies until a human decides.
    expect($ticket->refresh()->queue)->toBe('billing')
        ->and($ticket->priority)->toBe('normal');
});

it('stores the thresholds in force on each answer', function (): void {
    $ticket = triageTicket();
    fakeDriver()->answer('urgency', 'critical', 0.99)->answer('needs_human', false, 0.8);

    $answers = Clorian::now(TicketTriage::class, ['body' => $ticket->body], $ticket)->answers->keyBy('question');

    expect([$answers['urgency']->accept_threshold, $answers['urgency']->review_threshold])->toBe([null, 0.6])
        ->and([$answers['needs_human']->accept_threshold, $answers['needs_human']->review_threshold])->toBe([0.7, 0.6])
        ->and($answers['needs_human']->value)->toBe('false')
        ->and($answers['category']->status)->toBe(Status::Unresolved);
});

it('applies the defaults when nothing is sure', function (): void {
    $ticket = triageTicket();

    Clorian::now(TicketTriage::class, ['body' => $ticket->body], $ticket);

    expect($ticket->refresh()->queue)->toBe('triage')
        ->and($ticket->priority)->toBe('normal');
});

it('uses tuned thresholds from the database', function (): void {
    TunedThreshold::factory()->create(['policy' => ContactIntent::class, 'question' => 'intent', 'accept' => 0.5, 'review' => 0.3]);
    fakeDriver()->answer('intent', 'sales', 0.55);

    $answer = Clorian::now(ContactIntent::class, 'How much is the team plan?')->answers->sole();

    expect($answer->status)->toBe(Status::Accepted)
        ->and([$answer->accept_threshold, $answer->review_threshold])->toBe([0.5, 0.3]);
});

it('falls back to the defaults and marks the weighing failed when the driver times out', function (): void {
    config()->set('clorian.driver', 'jev');
    config()->set('clorian.drivers.jev.key', 'ts-test-key');
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 2000 milliseconds'));
    $ticket = triageTicket()->forceFill(['queue' => 'old', 'priority' => 'low']);
    $ticket->save();

    $weighing = Clorian::now(TicketTriage::class, ['body' => $ticket->body], $ticket);

    expect($weighing->status)->toBe(WeighingStatus::Failed)
        ->and($weighing->error)->toBe('The jev driver did not answer within 2s.')
        ->and($weighing->driver)->toBe('jev')
        ->and($weighing->model_version)->toBeNull()
        ->and($weighing->answers)->toBeEmpty()
        ->and($weighing->accepted('category', 'billing'))->toBeFalse()
        ->and(WeighingAnswer::query()->count())->toBe(0)
        ->and($ticket->refresh()->queue)->toBe('triage')
        ->and($ticket->priority)->toBe('normal');
});

it('never blocks the caller on any driver failure', function (): void {
    fakeDriver()->fail('Jev returned HTTP 401: bad key', retryable: false);

    $weighing = Clorian::now(ContactIntent::class, 'Hello');

    expect($weighing->status)->toBe(WeighingStatus::Failed)
        ->and($weighing->error)->toBe('Jev returned HTTP 401: bad key');
});

it('calls Jev when the config selects it', function (): void {
    config()->set('clorian.driver', 'jev');
    config()->set('clorian.drivers.jev.key', 'ts-test-key');
    config()->set('clorian.sync_timeout', 1.5);
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/Responses/jev/systemone-borderline.json'), true);
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response($fixture, 200, [JevDriver::REQUEST_ID_HEADER => 'req_1'])]);
    $ticket = triageTicket();

    $weighing = Clorian::now(TicketTriage::class, ['subject' => 'Export not working?', 'body' => 'The CSV export is empty.'], $ticket);

    expect($weighing->driver)->toBe('jev')
        ->and($weighing->model_version)->toBe('jev-1.14.0')
        ->and($weighing->meta)->toMatchArray(['input_tokens' => 452, 'output_tokens' => 107, 'request_id' => 'req_1'])
        ->and(answerSummary($weighing))->toBe([
            'category' => ['bug', Status::Review],
            'urgency' => ['normal', Status::Review],
            'needs_human' => ['true', Status::Accepted],
        ]);

    Http::assertSent(fn (Request $request): bool => $request['state']['context'] === ['plan' => 'team']);
});

it('resolves drivers by name', function (): void {
    expect(Clorian::driver())->toBe(app(FakeDriver::class))
        ->and(Clorian::driver('jev'))->toBeInstanceOf(JevDriver::class)
        ->and(fn () => Clorian::driver('openai'))->toThrow(InvalidArgumentException::class, 'No Clorian driver [openai].');

    config()->set('clorian.driver', null);

    expect(fn () => Clorian::driver())->toThrow(InvalidArgumentException::class, 'Set clorian.driver');
});

it('weighs with a driver added through extend()', function (): void {
    config()->set('clorian.driver', 'custom');
    config()->set('clorian.drivers.custom', ['answer' => 'billing']);

    Clorian::extend('custom', fn (Container $app, array $config): Driver => new class($config) implements Driver
    {
        /** @param array<mixed> $config */
        public function __construct(private readonly array $config) {}

        public function name(): string
        {
            return 'custom';
        }

        public function score(array $questions, string|array $content, array $context, ?float $timeout = null): Scores
        {
            return new Scores(['intent' => ['billing' => $this->config['answer'] === 'billing' ? 0.92 : 0.0, 'account_access' => 0.02, 'bug_report' => 0.02, 'sales' => 0.02, 'spam' => 0.02]]);
        }
    });

    $weighing = Clorian::now(ContactIntent::class, 'Where is my invoice?');

    expect($weighing->driver)->toBe('custom')
        ->and($weighing->accepted('intent', 'billing'))->toBeTrue();
});

it('lets the driver use its own timeout when sync_timeout is not usable', function (mixed $timeout): void {
    config()->set('clorian.sync_timeout', $timeout);

    Clorian::now(ContactIntent::class, 'Hello');

    expect(fakeDriver()->calls()[0]['timeout'])->toBeNull();
})->with([0, 'soon', null]);

it('stores nothing and calls no driver for an invalid policy', function (): void {
    expect(fn () => Clorian::now(Ticket::class, 'Hello'))->toThrow(InvalidThresholds::class)
        ->and(Weighing::query()->count())->toBe(0)
        ->and(fakeDriver()->calls())->toBe([]);
});

it('resolves the facade to the manager singleton', function (): void {
    expect(Clorian::getFacadeRoot())->toBeInstanceOf(Manager::class)
        ->and(app(Manager::class))->toBe(app(Manager::class));
});

it('reads accepted and human-reviewed answers from a weighing', function (): void {
    $weighing = Weighing::factory()->create();
    $answer = static fn (string $question, string $value, Status $status, array $extra = []): WeighingAnswer => WeighingAnswer::factory()->for($weighing)
        ->create(['question' => $question, 'value' => $value, 'status' => $status, ...$extra]);

    $answer('category', 'billing', Status::Accepted);
    $answer('urgency', 'high', Status::Review);
    $answer('needs_human', 'true', Status::Accepted);
    $answer('language', 'en', Status::Accepted, ['review_action' => ReviewAction::Overturned, 'reviewed_value' => 'cy', 'reviewed_at' => now()]);

    $weighing->refresh();

    expect($weighing->accepted('category', 'billing'))->toBeTrue()
        ->and($weighing->accepted('urgency', 'high'))->toBeFalse()
        ->and($weighing->accepted('needs_human', true))->toBeTrue()
        ->and($weighing->accepted('needs_human', false))->toBeFalse()
        ->and($weighing->accepted('language', 'en'))->toBeFalse()
        ->and($weighing->accepted('language', 'cy'))->toBeTrue()
        ->and($weighing->accepted('missing', 'x'))->toBeFalse();
});
