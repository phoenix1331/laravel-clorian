<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Phoenix1331\Clorian\Commands\PingCommand;
use Phoenix1331\Clorian\Models\Weighing;

const PING_KEY = 'ts-ping-secret-0000';

/**
 * @return array<string, mixed>
 */
function pingAnswer(string $model = 'jev-1.14.0'): array
{
    return [
        'model' => $model,
        'answers' => [
            'topic' => ['type' => 'choice', 'probabilities' => ['billing' => 0.93, 'technical' => 0.04, 'other' => 0.03]],
            'wants_refund' => ['type' => 'noul', 'noul' => 0.97],
        ],
        'usage' => ['input_tokens' => 80, 'output_tokens' => 4],
    ];
}

beforeEach(function (): void {
    config()->set('clorian.driver', 'jev');
    config()->set('clorian.drivers.jev.key', PING_KEY);
});

it('runs one sample weighing and reports the driver, model and answers', function (): void {
    config()->set('clorian.drivers.jev.model', 'jev-1.14.0');
    Http::fake(['*' => Http::response(pingAnswer(), 200, ['x-typesafe-request-id' => 'req_ping'])]);

    $this->artisan('clorian:ping')
        // One expectation per line: each written line satisfies only one.
        ->expectsOutputToContain('Driver')
        ->expectsOutputToContain(' set')
        ->expectsOutputToContain('Answered by')
        ->expectsOutputToContain('req_ping')
        ->expectsOutputToContain('billing (0.93)')
        ->expectsOutputToContain('true (0.97)')
        ->expectsOutputToContain('The jev driver answered. Clorian is ready.')
        ->doesntExpectOutputToContain(PING_KEY)
        ->doesntExpectOutputToContain('Pin it')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['state'] === ['content' => PingCommand::SAMPLE]
        && $request['model'] === 'jev-1.14.0');

    expect(Weighing::query()->count())->toBe(0);
});

it('warns when the model is a moving alias, naming the version to pin', function (string $model): void {
    config()->set('clorian.drivers.jev.model', $model);
    Http::fake(['*' => Http::response(pingAnswer())]);

    $this->artisan('clorian:ping')
        ->expectsOutputToContain("{$model} moves to new versions without notice, which shifts confidences and invalidates calibration. Pin it: JEV_MODEL=jev-1.14.0")
        ->assertSuccessful();
})->with(['jev-latest', 'jev-preview']);

it('falls back to the docs when the response names no version', function (): void {
    $answer = pingAnswer();
    unset($answer['model']);
    Http::fake(['*' => Http::response($answer)]);

    $this->artisan('clorian:ping')
        ->expectsOutputToContain("Pin it: JEV_MODEL=a version from TypeSafe's docs")
        ->assertSuccessful();
});

it('fails clearly without a key, and calls nothing', function (): void {
    config()->set('clorian.drivers.jev.key', null);
    Http::fake();

    $this->artisan('clorian:ping')
        ->expectsOutputToContain('missing')
        ->expectsOutputToContain('No Jev API key is set. Add JEV_API_KEY to .env, or set CLORIAN_DRIVER=fake.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('reports a rejected key with the request id, never the key', function (): void {
    Http::fake(['*' => Http::response(['detail' => 'Invalid API key'], 401, ['x-typesafe-request-id' => 'req_bad'])]);

    $this->artisan('clorian:ping')
        ->expectsOutputToContain('Jev returned HTTP 401: Invalid API key Check JEV_API_KEY. (request req_bad)')
        ->doesntExpectOutputToContain(PING_KEY)
        ->assertFailed();
});

it('reports a failure without a request id', function (): void {
    Http::fake(['*' => Http::response('', 529)]);

    $this->artisan('clorian:ping')
        ->expectsOutputToContain('Jev returned HTTP 529')
        ->doesntExpectOutputToContain('(request')
        ->assertFailed();
});

it('pings the fake driver without sending anything', function (): void {
    config()->set('clorian.driver', 'fake');
    Http::fake();

    $this->artisan('clorian:ping')
        ->doesntExpectOutputToContain('Key')
        ->doesntExpectOutputToContain('Pin it')
        ->expectsOutputToContain('The fake driver answered. Clorian is ready.')
        ->assertSuccessful();

    Http::assertNothingSent();
});
