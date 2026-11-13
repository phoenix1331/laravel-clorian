<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Phoenix1331\Clorian\Clorian as Manager;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Facades\Clorian;
use Phoenix1331\Clorian\Jobs\WeighSubject;
use Phoenix1331\Clorian\Policies\PolicyRegistry;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Tests\Fixtures\Models\Ticket;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Http\Message\StreamInterface;

/*
 * Whatever goes wrong, the weighed content and the Jev key stay out of logs,
 * Clorian's events, exceptions (messages, traces with arguments, and every
 * object they reach) and queued job payloads; stored input is ciphertext.
 */

const CONTENT_CANARY = 'Zanzibar';

const EMAIL_CANARY = 'jo.canary@example.com';

const KEY_CANARY = 'ts-canary-key-0000';

/**
 * Every string reachable from a value: array items, and the properties of
 * every object (private ones and an exception's trace arguments included).
 *
 * @return list<string>
 */
function reachableStrings(mixed $value, ?SplObjectStorage $seen = null, int $depth = 0): array
{
    $seen ??= new SplObjectStorage;

    if (is_string($value)) {
        return [$value];
    }

    if ($depth > 12 || $value instanceof Closure) {
        return [];
    }

    if (is_array($value)) {
        $strings = array_map('strval', array_filter(array_keys($value), is_string(...)));

        foreach ($value as $item) {
            array_push($strings, ...reachableStrings($item, $seen, $depth + 1));
        }

        return $strings;
    }

    if (! is_object($value) || $seen->contains($value)) {
        return [];
    }

    $seen->attach($value);
    $strings = [];

    for ($class = new ReflectionClass($value); $class !== false; $class = $class->getParentClass()) {
        foreach ($class->getProperties() as $property) {
            if (! $property->isStatic() && $property->isInitialized($value)) {
                array_push($strings, ...reachableStrings($property->getValue($value), $seen, $depth + 1));
            }
        }
    }

    // Streams (a request body) hide their contents behind a resource.
    if ($value instanceof StreamInterface && $value->isSeekable()) {
        $strings[] = (string) $value;
    }

    return $strings;
}

function assertNoSecrets(mixed $value, string $where): void
{
    foreach (reachableStrings($value) as $string) {
        foreach ([CONTENT_CANARY, EMAIL_CANARY, KEY_CANARY] as $secret) {
            expect(str_contains($string, $secret))->toBeFalse("{$where} contains [{$secret}]: ".mb_substr($string, 0, 200));
        }
    }
}

function secretTicket(): Ticket
{
    return Ticket::query()->create([
        'subject' => CONTENT_CANARY.' parcel',
        'body' => CONTENT_CANARY.' parcel never arrived, write to '.EMAIL_CANARY,
    ]);
}

/**
 * How Jev can answer, from success to every failure the driver handles.
 *
 * @return array<string, Closure(): void>
 */
function jevOutcomes(): array
{
    $fixture = static fn (string $name): array => json_decode((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/Responses/jev/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

    return [
        'success' => static fn () => Http::fake(['*' => Http::response($fixture('systemone-clear'), 200, ['x-typesafe-request-id' => 'req_1'])]),
        'bad key' => static fn () => Http::fake(['*' => Http::response($fixture('error-401-invalid-key'), 401)]),
        'validation error echoing the content' => static fn () => Http::fake(['*' => Http::response(['detail' => [[
            'type' => 'string_too_long',
            'loc' => ['body', 'state', 'content', 'body'],
            'msg' => 'String should have at most 10 characters',
            'input' => CONTENT_CANARY.' parcel never arrived',
        ]]], 422)]),
        'rate limited' => static fn () => Http::fake(['*' => Http::response(['detail' => 'Slow down'], 429, ['retry-after' => '7'])]),
        'server error' => static fn () => Http::fake(['*' => Http::response(['detail' => ['message' => 'Internal error']], 500)]),
        'overloaded' => static fn () => Http::fake(['*' => Http::response('', 529)]),
        'not json' => static fn () => Http::fake(['*' => Http::response('<html>Bad gateway</html>', 200)]),
        'timeout' => static fn () => Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 2001 milliseconds with 0 bytes received')),
        // A real refused connection: Guzzle's exception holds the request, key and body included.
        'connection refused' => static function (): void {
            Http::allowStrayRequests();
            config()->set('clorian.drivers.jev.base_url', 'http://127.0.0.1:1');
        },
    ];
}

beforeEach(function (): void {
    ini_set('zend.exception_ignore_args', '1');
    config()->set('clorian.driver', 'jev');
    config()->set('clorian.drivers.jev.key', KEY_CANARY);
    config()->set('clorian.store_input', 'always');
    config()->set('logging.default', 'null');
});

afterEach(function (): void {
    ini_restore('zend.exception_ignore_args');
});

it('keeps content and the key out of exceptions, logs and events', function (Closure $outcome): void {
    $outcome();
    $logged = [];
    $events = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message;
    });
    Event::listen('Phoenix1331\Clorian\Events\*', function (string $name, array $payload) use (&$events): void {
        $events[] = $payload;
    });

    $ticket = secretTicket();
    $policies = app(PolicyRegistry::class);
    $exceptions = [];

    // Straight from the driver, as an app calling it would see it.
    try {
        app(Manager::class)->driver()->score($policies->questions($policies->get(TicketTriage::class)), $ticket->weighInput(), []);
    } catch (Throwable $e) {
        $exceptions[] = $e;
    }

    // Sync: never throws, stores a failed weighing instead.
    Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);

    // Queued: retried or failed; the exception is what lands in failed_jobs.
    $job = (new WeighSubject($ticket))->withFakeQueueInteractions();
    $job->handle(app(Manager::class));
    $failure = $job->job?->failedWith;

    if ($failure instanceof Throwable) {
        $exceptions[] = $failure;
        $job->failed($failure);
    }

    // An app reporting each exception, as its handler would.
    foreach ($exceptions as $exception) {
        report($exception);
        assertNoSecrets((string) $exception, 'The exception as a string');
    }

    assertNoSecrets($exceptions, 'An exception');
    assertNoSecrets($logged, 'A log entry');
    assertNoSecrets($events, 'A Clorian event');
    expect($events)->not->toBe([]);
})->with(jevOutcomes());

it('writes no content or key into the trace text of a failed queued weighing, even with arguments on', function (Closure $outcome): void {
    // What failed_jobs.exception and a log's stack trace hold: PHP prints
    // arrays as "Array" and objects by class, so the job's array input stays out.
    ini_set('zend.exception_ignore_args', '0');
    $outcome();

    $job = (new WeighSubject(secretTicket()))->withFakeQueueInteractions();
    $job->handle(app(Manager::class));
    $failure = $job->job?->failedWith;

    expect($failure)->toBeInstanceOf(Throwable::class);
    assertNoSecrets((string) $failure, 'The failed job exception text');
})->with(fn (): array => array_diff_key(jevOutcomes(), array_flip(['success', 'rate limited', 'server error', 'overloaded', 'timeout', 'connection refused'])));

it('reports a bad key without the key', function (): void {
    jevOutcomes()['bad key']();

    try {
        app(Manager::class)->driver()->score(['q' => Question::choice('q', ['a', 'b'])], 'Hello', []);
    } catch (DriverException $e) {
        expect($e->getMessage())->toContain('Check JEV_API_KEY.')
            ->not->toContain(KEY_CANARY);
    }

    expect($e ?? null)->not->toBeNull();
});

it('sends the key in the authorization header only', function (): void {
    jevOutcomes()['success']();

    Clorian::now(TicketTriage::class, secretTicket()->weighInput());

    Http::assertSent(function (Request $request): bool {
        $headers = $request->headers();
        unset($headers['Authorization']);

        return $request->header('Authorization') === ['Bearer '.KEY_CANARY]
            && ! str_contains($request->url(), KEY_CANARY)
            && ! str_contains($request->body(), KEY_CANARY)
            && ! str_contains((string) json_encode($headers), KEY_CANARY);
    });
});

it('queues the subject by id, never its content', function (): void {
    config()->set('clorian.enabled', true);
    Queue::fake();

    secretTicket();

    Queue::assertPushed(WeighSubject::class, function (WeighSubject $job): bool {
        $queue = new SyncQueue;
        $queue->setContainer(app());
        $payload = (fn (): string => $this->createPayload($job, 'default'))->call($queue);

        assertNoSecrets($payload, 'The queued payload');
        assertNoSecrets(serialize($job), 'The serialised job');

        return true;
    });
});

it('stores no plaintext content in any Clorian table', function (Closure $outcome): void {
    $outcome();
    $ticket = secretTicket();

    Clorian::now(TicketTriage::class, $ticket->weighInput(), $ticket);
    Clorian::now(TicketTriage::class, $ticket->weighInput());

    $rows = [
        ...DB::table('clorian_weighings')->get()->all(),
        ...DB::table('clorian_answers')->get()->all(),
        ...DB::table('clorian_thresholds')->get()->all(),
    ];

    expect(DB::table('clorian_weighings')->whereNotNull('input')->count())->toBe(2);
    assertNoSecrets(array_map(static fn (object $row): array => (array) $row, $rows), 'A Clorian table');
})->with(fn (): array => array_intersect_key(jevOutcomes(), array_flip(['success', 'server error'])));

it('finds secrets it is meant to find', function (): void {
    $exception = new RuntimeException('wrapped', previous: new LogicException('Contains '.KEY_CANARY));

    expect(fn () => assertNoSecrets([$exception], 'probe'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => assertNoSecrets(['nested' => ['x' => EMAIL_CANARY]], 'probe'))->toThrow(ExpectationFailedException::class);
});
