<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Policies;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Policies\Policy;
use Phoenix1331\Clorian\Questions\Question;

/**
 * Adds its own redaction on top of the default, and sends personal data in
 * context so its redaction can be checked.
 */
final class SupportReply extends Policy
{
    public function questions(): array
    {
        return [
            Question::choice('tone', ['calm', 'angry']),
        ];
    }

    public function context(?Model $subject): array
    {
        return ['customer' => 'Jo Bloggs', 'email' => 'jo@example.com', 'plan' => 'pro'];
    }

    public function redact(array $input): array
    {
        return array_map(
            static fn (mixed $value): mixed => is_string($value) ? str_replace('Jo Bloggs', '[name]', $value) : $value,
            parent::redact($input),
        );
    }
}
