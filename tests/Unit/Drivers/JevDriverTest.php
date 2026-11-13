<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Phoenix1331\Clorian\Drivers\JevDriver;
use Phoenix1331\Clorian\Drivers\Scores;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Questions\Question;

const JEV_TEST_KEY = 'ts-secret-key-1234567890';

const JEV_URL = 'https://api.typesafe.ai/v1/systemone';

/**
 * @return array<string, mixed>
 */
function jevFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__."/../../Fixtures/Responses/jev/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, Question>
 */
function jevQuestions(): array
{
    return [
        'category' => Question::choice('category', ['billing', 'bug', 'account', 'feature'])
            ->instructions('Which team should handle the support ticket in `content`?'),
        'urgency' => Question::choice('urgency', ['low', 'normal', 'high', 'critical']),
        'needs_human' => Question::boolean('needs_human'),
    ];
}

function jevDriver(int $maxContextTokens = 24000): JevDriver
{
    return new JevDriver(JEV_TEST_KEY, 'jev-latest', 'https://api.typesafe.ai/v1', 5.0, $maxContextTokens);
}

function jevScore(?JevDriver $driver = null, ?array $questions = null, ?float $timeout = null): Scores
{
    return ($driver ?? jevDriver())->score($questions ?? jevQuestions(), ['subject' => 'Export not working?', 'body' => 'The CSV export is empty.'], ['plan' => 'team'], $timeout);
}

function jevFailure(Closure $call): DriverException
{
    try {
        $call();
    } catch (DriverException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a DriverException.');
}

it('scores every question in one call', function (): void {
    Http::fake([JEV_URL => Http::response(jevFixture('systemone-borderline'), 200, [JevDriver::REQUEST_ID_HEADER => 'req_abc'])]);

    $scores = jevScore();

    expect($scores->probabilities)->toBe([
        'category' => ['billing' => 0.03, 'bug' => 0.89, 'account' => 0.06, 'feature' => 0.02],
        'urgency' => ['low' => 0.09, 'normal' => 0.6, 'high' => 0.27, 'critical' => 0.04],
        'needs_human' => ['true' => 0.73, 'false' => 0.27],
    ])
        ->and($scores->modelVersion)->toBe('jev-1.14.0')
        ->and($scores->inputTokens)->toBe(452)
        ->and($scores->outputTokens)->toBe(107)
        ->and($scores->requestId)->toBe('req_abc')
        ->and($scores->latencyMs)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($scores->truncated)->toBeFalse()
        ->and($scores->meta())->not->toHaveKey('truncated');

    Http::assertSentCount(1);
});

it('casts certain answers to floats', function (): void {
    Http::fake([JEV_URL => Http::response(jevFixture('systemone-clear'))]);

    $scores = jevScore();

    expect($scores->for('category'))->toBe(['billing' => 1.0, 'bug' => 0.0, 'account' => 0.0, 'feature' => 0.0])
        ->and($scores->for('needs_human'))->toBe(['true' => 0.9, 'false' => 0.1])
        ->and($scores->requestId)->toBeNull();
});

it('maps a noul to true and false', function (): void {
    Http::fake([JEV_URL => Http::response(['model' => 'jev-1.14.0', 'answers' => ['needs_human' => ['type' => 'noul', 'noul' => 0.02]]])]);

    expect(jevScore(questions: ['needs_human' => Question::boolean('needs_human')])->for('needs_human'))
        ->toBe(['true' => 0.02, 'false' => 0.98]);
});

it('sends the model, state and questions in the shape Jev reads', function (): void {
    Http::fake([JEV_URL => Http::response(jevFixture('systemone-borderline'))]);

    $questions = jevQuestions();
    $questions['needs_human'] = Question::boolean('needs_human', 'The ticket needs a human reply.')->when(true: 'A person must answer');
    $questions['category'] = Question::choice('category', ['billing' => 'Payments and invoices', 'bug' => '', 'account' => null, 'feature' => 'Requests']);

    jevScore(questions: $questions);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === JEV_URL
        && $request->hasHeader('Authorization', 'Bearer '.JEV_TEST_KEY)
        && $request->hasHeader('Accept', 'application/json')
        && $request->data() === [
            'model' => 'jev-latest',
            'state' => [
                'content' => ['subject' => 'Export not working?', 'body' => 'The CSV export is empty.'],
                'context' => ['plan' => 'team'],
            ],
            'questions' => [
                'category' => [
                    'type' => 'choice',
                    'criteria' => ['billing' => 'Payments and invoices', 'bug' => null, 'account' => null, 'feature' => 'Requests'],
                ],
                'urgency' => [
                    'type' => 'choice',
                    'criteria' => ['low' => null, 'normal' => null, 'high' => null, 'critical' => null],
                ],
                'needs_human' => [
                    'type' => 'noul',
                    'instructions' => 'The ticket needs a human reply.',
                    'criteria' => ['true' => 'A person must answer'],
                ],
            ],
        ]);
});

it('sends choice instructions and a humanised boolean, and leaves out empty context', function (): void {
    Http::fake([JEV_URL => Http::response(jevFixture('systemone-borderline'))]);

    jevDriver()->score(jevQuestions(), 'Charged twice, please refund', []);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['state'] === ['content' => 'Charged twice, please refund']
            && $data['questions']['category']['instructions'] === 'Which team should handle the support ticket in `content`?'
            && $data['questions']['needs_human'] === ['type' => 'noul', 'instructions' => 'Needs human'];
    });
});

it('trims a trailing slash from the base url', function (): void {
    Http::fake(['https://jev.test/v1/systemone' => Http::response(jevFixture('systemone-borderline'))]);

    jevScore(new JevDriver(JEV_TEST_KEY, 'jev-1.14.0', 'https://jev.test/v1/'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://jev.test/v1/systemone' && $request['model'] === 'jev-1.14.0');
});

it('uses the configured timeout, or the one given for a sync call', function (?float $given, float $expected): void {
    $options = [];

    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(jevFixture('systemone-borderline'));
    });

    jevScore(timeout: $given);

    expect($options['timeout'])->toBe($expected)
        ->and($options['connect_timeout'])->toBe($expected);
})->with([
    'configured' => [null, 5.0],
    'sync' => [2.0, 2.0],
]);

it('reads its settings from config', function (): void {
    Http::fake(['https://jev.test/systemone' => Http::response(jevFixture('systemone-borderline'))]);

    $driver = JevDriver::fromConfig(['key' => '  '.JEV_TEST_KEY.' ', 'model' => 'jev-1.14.0', 'base_url' => 'https://jev.test', 'timeout' => '3', 'max_context_tokens' => 1000]);

    jevScore($driver);

    expect($driver->name())->toBe('jev')
        ->and($driver->model())->toBe('jev-1.14.0');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.JEV_TEST_KEY));
});

it('falls back to defaults for missing config', function (): void {
    $driver = JevDriver::fromConfig(['key' => JEV_TEST_KEY, 'model' => '', 'timeout' => 'soon']);

    expect($driver->model())->toBe('jev-latest');
});

it('builds from the package config', function (): void {
    config()->set('clorian.drivers.jev.key', JEV_TEST_KEY);

    $config = config('clorian.drivers.jev');

    expect($config)->toBeArray()->toHaveKey('max_context_tokens', 24000)
        ->and(JevDriver::fromConfig(is_array($config) ? $config : [])->model())->toBe('jev-latest');
});

it('rejects a timeout or context budget that can never work', function (float $timeout, int $tokens, string $message): void {
    expect(fn () => new JevDriver(JEV_TEST_KEY, timeout: $timeout, maxContextTokens: $tokens))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero timeout' => [0.0, 24000, 'timeout must be above 0'],
    'no tokens' => [5.0, 0, 'max_context_tokens must be at least 1'],
]);

it('refuses to call without a key', function (?string $key): void {
    Http::fake();

    $e = jevFailure(fn () => jevScore(JevDriver::fromConfig(['key' => $key])));

    expect($e->retryable)->toBeFalse()
        ->and($e->getMessage())->toContain('JEV_API_KEY');

    Http::assertNothingSent();
})->with([
    'missing' => [null],
    'blank' => ['   '],
]);

it('maps error responses to driver exceptions', function (int $status, array|string $body, array $headers, bool $retryable, string $message, ?int $retryAfter): void {
    Http::fake([JEV_URL => Http::response($body, $status, [JevDriver::REQUEST_ID_HEADER => 'req_err', ...$headers])]);

    $e = jevFailure(fn () => jevScore());

    expect($e->status)->toBe($status)
        ->and($e->retryable)->toBe($retryable)
        ->and($e->requestId)->toBe('req_err')
        ->and($e->retryAfter)->toBe($retryAfter)
        ->and($e->getMessage())->toBe($message);
})->with([
    'string detail' => [400, jevFixture('error-400-noul-without-instructions'), [], false, 'Jev returned HTTP 400: Noul question must have criteria or instructions: q', null],
    'unknown model' => [400, jevFixture('error-400-unknown-model'), [], false, 'Jev returned HTTP 400: Unknown model: jev-0.0.0-nope', null],
    'invalid key' => [401, jevFixture('error-401-invalid-key'), [], false, 'Jev returned HTTP 401: Cannot authenticate with the server. Please check your API key and try again. Check JEV_API_KEY.', null],
    'forbidden' => [403, '', [], false, 'Jev returned HTTP 403: Forbidden Check JEV_API_KEY.', null],
    'validation' => [422, jevFixture('error-422-too-many-options'), [], false, 'Jev returned HTTP 422: body.questions.q.choice.criteria: Dictionary should have at most 255 items after validation, not 256', null],
    'rate limited' => [429, ['detail' => ['message' => 'Slow down']], ['retry-after' => '3'], true, 'Jev returned HTTP 429: Slow down', 3],
    'overloaded' => [529, ['detail' => ['message' => 'Overloaded']], [], true, 'Jev returned HTTP 529: Overloaded', null],
    'server error' => [500, '', ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'], true, 'Jev returned HTTP 500: Internal Server Error', null],
    'no reason' => [599, '', [], true, 'Jev returned HTTP 599: no details', null],
    'validation without a location' => [422, ['detail' => [['msg' => 'Bad request']]], [], false, 'Jev returned HTTP 422: Bad request', null],
]);

it('never puts the echoed 422 input in the exception', function (): void {
    Http::fake([JEV_URL => Http::response(jevFixture('error-422-too-many-options'), 422)]);

    expect(jevFailure(fn () => jevScore())->getMessage())->not->toContain('o0')->not->toContain('null');
});

it('rejects answers it does not understand', function (array|string $body, ?array $questions, string $reason, ?int $status = 200): void {
    Http::fake([JEV_URL => Http::response($body, 200, [JevDriver::REQUEST_ID_HEADER => 'req_bad'])]);

    $e = jevFailure(fn () => jevScore(questions: $questions));

    expect($e->retryable)->toBeFalse()
        ->and($e->status)->toBe($status)
        ->and($e->requestId)->toBe('req_bad')
        ->and($e->getMessage())->toBe("The jev driver sent scores this package does not understand: {$reason}.");
})->with([
    'not json' => ['<html>gateway</html>', null, 'no answers in the response'],
    'missing answer' => [jevFixture('systemone-missing-answer'), ['category' => jevQuestions()['category'], 'needs_human' => jevQuestions()['needs_human']], 'no answer for question [needs_human]'],
    'wrong type' => [jevFixture('systemone-missing-answer'), null, 'question [urgency] was answered as noul, expected choice'],
    'no type' => [['answers' => ['category' => ['choice' => 'bug']]], ['category' => jevQuestions()['category']], 'question [category] was answered as nothing, expected choice'],
    'no probabilities' => [['answers' => ['category' => ['type' => 'choice']]], ['category' => jevQuestions()['category']], 'question [category] has no probabilities'],
    'probability not a number' => [['answers' => ['category' => ['type' => 'choice', 'probabilities' => ['billing' => '0.9']]]], ['category' => jevQuestions()['category']], 'question [category] has a probability that is not a number'],
    'noul not a number' => [['answers' => ['needs_human' => ['type' => 'noul', 'noul' => null]]], ['needs_human' => jevQuestions()['needs_human']], 'question [needs_human] has no noul probability'],
    'options differ' => [jevFixture('systemone-one-option'), ['q' => Question::choice('q', ['billing', 'bug'])], 'question [q] scored options (billing), expected (billing, bug)', null],
]);

it('reports a timeout as retryable', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 2001 milliseconds with 0 bytes received'));

    $e = jevFailure(fn () => jevScore(timeout: 2.0));

    expect($e->retryable)->toBeTrue()
        ->and($e->getMessage())->toBe('The jev driver did not answer within 2s.')
        ->and($e->getPrevious())->toBeNull();
});

it('reports a connection failure as retryable', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to api.typesafe.ai'));

    $e = jevFailure(fn () => jevScore());

    expect($e->retryable)->toBeTrue()
        ->and($e->getMessage())->toBe('Could not reach Jev: cURL error 7: Failed to connect to api.typesafe.ai');
});

it('keeps the key in the authorization header only', function (): void {
    Http::fake([JEV_URL => Http::response(jevFixture('error-401-invalid-key'), 401)]);

    $e = jevFailure(fn () => jevScore());

    expect($e->getMessage())->not->toContain(JEV_TEST_KEY);

    Http::assertSent(function (Request $request): bool {
        $headers = $request->headers();
        unset($headers['Authorization']);

        return ! str_contains($request->url(), JEV_TEST_KEY)
            && ! str_contains($request->body(), JEV_TEST_KEY)
            && ! str_contains((string) json_encode($headers), JEV_TEST_KEY);
    });
});

it('trims context before content to fit the budget', function (): void {
    Http::fake([JEV_URL => Http::response(['answers' => ['needs_human' => ['type' => 'noul', 'noul' => 0.9]]])]);

    $questions = ['needs_human' => Question::boolean('needs_human')];
    $overhead = strlen((string) json_encode(['needs_human' => ['type' => 'noul', 'instructions' => 'Needs human']]));
    // A budget of 100 bytes for the state.
    $tokens = (int) ceil(($overhead + 100) / JevDriver::BYTES_PER_TOKEN);
    $budget = $tokens * JevDriver::BYTES_PER_TOKEN - $overhead;

    $scores = jevDriver($tokens)->score($questions, ['body' => str_repeat('a', $budget - 10)], ['history' => str_repeat('b', 40), 'plan' => 'team', 'seats' => 5]);

    expect($scores->truncated)->toBeTrue()
        ->and($scores->meta())->toHaveKey('truncated', true);

    Http::assertSent(function (Request $request) use ($budget): bool {
        $state = $request['state'];

        return $state['content'] === ['body' => str_repeat('a', $budget - 10)]
            && strlen($state['context']['history']) + strlen($state['context']['plan']) <= 10
            && $state['context']['seats'] === 5;
    });
});

it('trims nested content by one proportion once the context is gone', function (): void {
    Http::fake([JEV_URL => Http::response(['answers' => ['needs_human' => ['type' => 'noul', 'noul' => 0.9]]])]);

    $questions = ['needs_human' => Question::boolean('needs_human')];

    $scores = jevDriver(10)->score($questions, ['body' => str_repeat('é', 1000), 'meta' => ['tags' => [str_repeat('x', 1000)], 'count' => 3]], ['plan' => 'team']);

    expect($scores->truncated)->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        $state = $request['state'];
        $body = $state['content']['body'];

        return $state['context'] === ['plan' => '']
            && mb_check_encoding($body, 'UTF-8')
            && strlen($body) + strlen($state['content']['meta']['tags'][0]) <= 30
            && $state['content']['meta']['count'] === 3;
    });
});

it('trims a string content when it alone is over', function (): void {
    Http::fake([JEV_URL => Http::response(['answers' => ['needs_human' => ['type' => 'noul', 'noul' => 0.9]]])]);

    jevDriver(1)->score(['needs_human' => Question::boolean('needs_human')], str_repeat('a', 500), []);

    Http::assertSent(fn (Request $request): bool => $request['state'] === ['content' => '']);
});
