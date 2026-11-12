<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Policies;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Resolution\Answers;

/**
 * Declares the questions Clorian asks about a model or an input, what to use
 * when an answer is not trusted, and what to do with the result. Resolved
 * from the container, so constructor injection works.
 */
abstract class Policy
{
    /**
     * @return list<Question>
     */
    abstract public function questions(): array;

    /**
     * Values used for any question whose answer is not accepted or reviewed.
     * Questions left out default to null. Booleans take true or false.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [];
    }

    /**
     * Extra facts sent alongside the content as `context`, e.g. the customer's
     * plan. Keep it short: unrelated material costs accuracy.
     *
     * @return array<string, mixed>
     */
    public function context(?Model $subject): array
    {
        return [];
    }

    /**
     * Strip personal data from the input before anything leaves the app.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function redact(array $input): array
    {
        return $input;
    }

    /**
     * Act on the answers. Only ever receives accepted or human-reviewed
     * values; everything else is the default. Runs again after a review.
     */
    public function apply(?Model $subject, Answers $answers): void {}
}
