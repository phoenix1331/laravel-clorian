<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Policies;

use Phoenix1331\Clorian\Policies\Policy;
use Phoenix1331\Clorian\Questions\Question;

/**
 * A redact() that drops the content key, which string input cannot survive.
 */
final class LossyRedaction extends Policy
{
    public function questions(): array
    {
        return [
            Question::choice('tone', ['calm', 'angry']),
        ];
    }

    public function redact(array $input): array
    {
        return ['body' => 'gone'];
    }
}
