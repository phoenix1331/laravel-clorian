<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Questions\BooleanQuestion;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\Thresholds;

/**
 * Scores with TypeSafe's Jev: every question of a policy in one
 * POST /systemone call. Never retries itself; DriverException::$retryable
 * tells the job whether to try again. The key only ever travels in the
 * Authorization header, and nothing here logs.
 */
final readonly class JevDriver implements Driver
{
    public const string NAME = 'jev';

    public const string REQUEST_ID_HEADER = 'x-typesafe-request-id';

    /** Conservative estimate for English; Jev's tokeniser is unpublished. */
    public const int BYTES_PER_TOKEN = 3;

    /** Rate limited or overloaded; 5xx and connection failures are retried too. */
    private const array RETRYABLE = [429, 529];

    public function __construct(
        private ?string $key,
        private string $model = 'jev-latest',
        private string $baseUrl = 'https://api.typesafe.ai/v1',
        private float $timeout = 5.0,
        private int $maxContextTokens = 24000,
    ) {
        if ($timeout <= 0.0) {
            throw new InvalidArgumentException('The Jev timeout must be above 0 seconds.');
        }

        if ($maxContextTokens < 1) {
            throw new InvalidArgumentException('The Jev max_context_tokens must be at least 1.');
        }
    }

    /**
     * From the clorian.drivers.jev config array.
     *
     * @param  array<mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $key = $config['key'] ?? null;
        $model = $config['model'] ?? null;
        $baseUrl = $config['base_url'] ?? null;
        $timeout = $config['timeout'] ?? null;
        $maxContextTokens = $config['max_context_tokens'] ?? null;

        return new self(
            key: is_string($key) && trim($key) !== '' ? trim($key) : null,
            model: is_string($model) && $model !== '' ? $model : 'jev-latest',
            baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'https://api.typesafe.ai/v1',
            timeout: is_numeric($timeout) ? (float) $timeout : 5.0,
            maxContextTokens: is_numeric($maxContextTokens) ? (int) $maxContextTokens : 24000,
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function score(array $questions, string|array $content, array $context, ?float $timeout = null): Scores
    {
        if ($this->key === null) {
            throw DriverException::permanent('No Jev API key is set. Add JEV_API_KEY to .env, or set CLORIAN_DRIVER=fake.');
        }

        $seconds = $timeout ?? $this->timeout;
        $payload = self::questions($questions);
        [$state, $truncated] = $this->state($content, $context, $payload);
        $started = hrtime(true);

        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
                ->withToken($this->key)
                ->acceptJson()
                ->asJson()
                ->timeout($seconds)
                ->connectTimeout($seconds)
                ->post('systemone', [
                    'model' => $this->model,
                    'state' => $state,
                    'questions' => $payload,
                ]);
        } catch (ConnectionException $e) {
            // Not chained: Guzzle's exception underneath holds the request,
            // Authorization header and content included, for any error
            // reporter that walks the chain.
            if (self::isTimeout($e)) {
                throw DriverException::timedOut(self::NAME, $seconds);
            }

            throw DriverException::retryable('Could not reach Jev: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw self::failure($response);
        }

        $model = $response->json('model');
        $inputTokens = $response->json('usage.input_tokens');
        $outputTokens = $response->json('usage.output_tokens');

        $scores = new Scores(
            probabilities: self::probabilities($questions, $response),
            modelVersion: is_string($model) ? $model : null,
            latencyMs: (int) round((hrtime(true) - $started) / 1_000_000),
            inputTokens: is_int($inputTokens) ? $inputTokens : null,
            outputTokens: is_int($outputTokens) ? $outputTokens : null,
            requestId: self::requestId($response),
            truncated: $truncated,
        );

        $scores->assertCovers($questions, self::NAME);

        return $scores;
    }

    /**
     * The questions as Jev reads them: choices with option criteria, booleans
     * as noul with optional true/false criteria.
     *
     * @param  array<string, Question>  $questions
     * @return array<string, array<string, mixed>>
     */
    private static function questions(array $questions): array
    {
        $payload = [];

        foreach ($questions as $name => $question) {
            if ($question instanceof BooleanQuestion) {
                $payload[$name] = ['type' => 'noul', 'instructions' => $question->getInstructions()];
                $criteria = array_filter($question->descriptions(), static fn (?string $description): bool => $description !== null);

                if ($criteria !== []) {
                    $payload[$name]['criteria'] = $criteria;
                }

                continue;
            }

            $payload[$name] = array_filter([
                'type' => 'choice',
                'instructions' => $question->getInstructions(),
            ], static fn (?string $value): bool => $value !== null);

            // An option without a description is read by its name alone.
            $payload[$name]['criteria'] = $question->descriptions();
        }

        return $payload;
    }

    /**
     * The state sent to Jev, trimmed to the context budget: context strings
     * first, then content strings, each by one proportion.
     *
     * @param  string|array<string, mixed>  $content
     * @param  array<string, mixed>  $context
     * @param  array<string, array<string, mixed>>  $questions
     * @return array{0: array{content: string|array<mixed>, context?: array<mixed>}, 1: bool}
     */
    private function state(string|array $content, array $context, array $questions): array
    {
        $budget = max(0, $this->maxContextTokens * self::BYTES_PER_TOKEN - strlen((string) json_encode($questions)));
        $contextBytes = self::bytes($context);
        $contentBytes = self::bytes($content);
        $over = $contextBytes + $contentBytes - $budget;
        $truncated = $over > 0;

        if ($over > 0 && $contextBytes > 0) {
            $context = self::shrink($context, max(0, $contextBytes - $over) / $contextBytes);
            $over -= $contextBytes - self::bytes($context);
        }

        if ($over > 0 && $contentBytes > 0) {
            $ratio = max(0, $contentBytes - $over) / $contentBytes;
            $content = is_string($content) ? self::cut($content, $ratio) : self::shrink($content, $ratio);
        }

        $state = ['content' => $content];

        if ($context !== []) {
            $state['context'] = $context;
        }

        return [$state, $truncated];
    }

    /**
     * Bytes of every string in a value, however deeply nested.
     */
    private static function bytes(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }

        if (! is_array($value)) {
            return 0;
        }

        return array_sum(array_map(self::bytes(...), $value));
    }

    /**
     * Every string in an array cut to a ratio of its length.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function shrink(array $value, float $ratio): array
    {
        return array_map(static fn (mixed $item): mixed => match (true) {
            is_string($item) => self::cut($item, $ratio),
            is_array($item) => self::shrink($item, $ratio),
            default => $item,
        }, $value);
    }

    /**
     * A string cut to a ratio of its length, on a character boundary.
     */
    private static function cut(string $value, float $ratio): string
    {
        return mb_strcut($value, 0, (int) floor(strlen($value) * $ratio));
    }

    /**
     * question => option => probability, booleans mapped to "true"/"false".
     *
     * @param  array<string, Question>  $questions
     * @return array<string, array<string, float>>
     */
    private static function probabilities(array $questions, Response $response): array
    {
        $answers = $response->json('answers');

        if (! is_array($answers)) {
            throw self::unexpected($response, 'no answers in the response');
        }

        $probabilities = [];

        foreach ($questions as $name => $question) {
            $answer = $answers[$name] ?? null;

            if (! is_array($answer)) {
                throw self::unexpected($response, sprintf('no answer for question [%s]', $name));
            }

            $expected = $question instanceof BooleanQuestion ? 'noul' : 'choice';

            if (($answer['type'] ?? null) !== $expected) {
                throw self::unexpected($response, sprintf('question [%s] was answered as %s, expected %s', $name, is_string($answer['type'] ?? null) ? $answer['type'] : 'nothing', $expected));
            }

            $probabilities[$name] = $expected === 'noul'
                ? self::noul($name, $answer['noul'] ?? null, $response)
                : self::choice($name, $answer['probabilities'] ?? null, $response);
        }

        return $probabilities;
    }

    /**
     * @return array<string, float>
     */
    private static function noul(string $name, mixed $probability, Response $response): array
    {
        if (! is_int($probability) && ! is_float($probability)) {
            throw self::unexpected($response, sprintf('question [%s] has no noul probability', $name));
        }

        return [
            BooleanQuestion::TRUE => (float) $probability,
            BooleanQuestion::FALSE => round(1.0 - $probability, Thresholds::PRECISION),
        ];
    }

    /**
     * @return array<string, float>
     */
    private static function choice(string $name, mixed $raw, Response $response): array
    {
        if (! is_array($raw)) {
            throw self::unexpected($response, sprintf('question [%s] has no probabilities', $name));
        }

        $probabilities = [];

        foreach ($raw as $option => $probability) {
            if (! is_int($probability) && ! is_float($probability)) {
                throw self::unexpected($response, sprintf('question [%s] has a probability that is not a number', $name));
            }

            // Jev rounds, so certain answers arrive as integers 0 and 1.
            $probabilities[(string) $option] = (float) $probability;
        }

        return $probabilities;
    }

    private static function failure(Response $response): DriverException
    {
        $status = $response->status();
        $message = sprintf('Jev returned HTTP %d: %s', $status, self::detail($response));

        if ($status === 401 || $status === 403) {
            $message .= ' Check JEV_API_KEY.';
        }

        if (in_array($status, self::RETRYABLE, true) || $status >= 500) {
            $retryAfter = $response->header('retry-after');

            return DriverException::retryable(
                $message,
                $status,
                self::requestId($response),
                retryAfter: ctype_digit($retryAfter) ? (int) $retryAfter : null,
            );
        }

        return DriverException::permanent($message, $status, self::requestId($response));
    }

    /**
     * The reason Jev gave: {detail: {message}}, a string detail, or the
     * first validation error's location and message. Never the 422's
     * "input", which echoes the content sent.
     */
    private static function detail(Response $response): string
    {
        $detail = $response->json('detail');

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        if (is_array($detail) && is_string($detail['message'] ?? null)) {
            return $detail['message'];
        }

        $first = is_array($detail) ? ($detail[0] ?? null) : null;

        if (is_array($first) && is_string($first['msg'] ?? null)) {
            $location = is_array($first['loc'] ?? null) ? implode('.', array_filter($first['loc'], is_scalar(...))) : '';

            return $location === '' ? $first['msg'] : "{$location}: {$first['msg']}";
        }

        return $response->reason() !== '' ? $response->reason() : 'no details';
    }

    private static function unexpected(Response $response, string $reason): DriverException
    {
        return DriverException::unexpected(self::NAME, $reason, $response->status(), self::requestId($response));
    }

    private static function requestId(Response $response): ?string
    {
        $id = $response->header(self::REQUEST_ID_HEADER);

        return $id === '' ? null : $id;
    }

    /**
     * cURL error 28, however Guzzle words it.
     */
    private static function isTimeout(ConnectionException $e): bool
    {
        return str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;
    }
}
