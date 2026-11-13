<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Models\TunedThreshold;
use Phoenix1331\Clorian\Policies\Policy;
use Phoenix1331\Clorian\Policies\PolicyRegistry;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Resolution\ThresholdResolver;
use Phoenix1331\Clorian\Resolution\TunedThresholds;

/**
 * Writes a tuned threshold to clorian_thresholds, which beats the policy and
 * config. Shows the thresholds in force before and after, and asks first.
 */
final class TuneCommand extends Command
{
    public const string NEVER = 'never';

    protected $signature = 'clorian:tune
        {policy : The policy class}
        {question : The question name}
        {--option= : One option; the whole question when left out}
        {--accept= : 0 to 1, or "never" to always review}
        {--review= : 0 to 1}';

    protected $description = 'Set the accept and review thresholds for a question or one of its options';

    public function handle(PolicyRegistry $policies, TunedThresholds $tuned, Auth $auth): int
    {
        $policyName = ltrim((string) $this->argument('policy'), '\\');
        $questionName = (string) $this->argument('question');
        $option = $this->option('option');
        $option = is_string($option) && $option !== '' ? $option : null;

        try {
            $policy = $policies->get($policyName);
        } catch (InvalidThresholds $e) {
            return $this->refuse($e->getMessage());
        }

        $questions = $policies->questions($policy);
        $question = $questions[$questionName] ?? null;

        if (! $question instanceof Question) {
            return $this->refuse(sprintf('%s has no question [%s]. Questions: %s.', $policyName, $questionName, implode(', ', array_keys($questions))));
        }

        if ($option !== null && ! in_array($option, $question->options(), true)) {
            return $this->refuse(sprintf('[%s] is not an option of [%s]. Options: %s.', $option, $questionName, implode(', ', $question->options())));
        }

        $acceptOption = $this->option('accept');
        $never = is_string($acceptOption) && strtolower($acceptOption) === self::NEVER;
        $accept = $never ? null : $this->threshold('accept');
        $review = $this->threshold('review');

        if ($accept === false || $review === false) {
            return self::INVALID;
        }

        if (! $never && $accept === null && $review === null) {
            return $this->refuse('Pass --accept, --review or both.');
        }

        $row = TunedThreshold::query()
            ->where('policy', $policyName)
            ->where('question', $questionName)
            ->where('option', $option ?? TunedThreshold::WHOLE_QUESTION)
            ->first();

        $newAccept = match (true) {
            $never => null,
            $accept !== null => $accept,
            $row !== null => $row->accept,
            default => false,
        };

        if ($newAccept === false) {
            return $this->refuse('A new tuned threshold needs --accept too (a value, or "never" to always review); --review alone would fall back to always review.');
        }

        $values = ['accept' => $newAccept, 'review' => $review ?? $row?->review];

        $before = $tuned->forPolicy($policyName)[$questionName] ?? [];
        $after = $before;
        $after[$option ?? TunedThresholds::WHOLE_QUESTION] = self::overrides($values['accept'], $values['review']);

        try {
            [$rows, $unguarded] = $this->comparison($policyName, $policies, $question, $option, $before, $after);
        } catch (InvalidThresholds $e) {
            return $this->refuse($e->getMessage());
        }

        $this->newLine();
        $this->line(sprintf('  <fg=green;options=bold>%s</>, %s', $policyName, $option === null ? "{$questionName}, every option" : "{$questionName}, {$option}"));
        $this->table(['Option', 'Accept', 'Review'], $rows);

        foreach ($unguarded as $name) {
            $this->components->warn("[{$name}] always went to review; it would now be accepted automatically. Tune it with --option={$name} --accept=never to keep it.");
        }

        if ($row !== null && $row->accept === $values['accept'] && $row->review === $values['review']) {
            $this->components->info('These thresholds are already set. Nothing to change.');

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! $this->confirm('Save these thresholds?', false)) {
            $this->components->warn('Nothing saved.');

            return self::SUCCESS;
        }

        $by = $auth->guard()->user();

        TunedThreshold::query()->updateOrCreate(
            ['policy' => $policyName, 'question' => $questionName, 'option' => $option],
            [
                ...$values,
                'tuned_by_type' => $by instanceof Model ? $by->getMorphClass() : null,
                'tuned_by_id' => $by instanceof Model ? self::key($by) : null,
            ],
        );

        $this->components->info('Saved. New weighings use these thresholds; stored answers keep the ones they were judged by.');

        return self::SUCCESS;
    }

    /**
     * A threshold option: null when not passed, false (after an error) when unusable.
     */
    private function threshold(string $name): float|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0.0 || (float) $value > 1.0) {
            $this->components->error(sprintf('--%s must be between 0 and 1%s, got "%s".', $name, $name === 'accept' ? ', or "never"' : '', is_string($value) ? $value : ''));

            return false;
        }

        return round((float) $value, 4);
    }

    private static function overrides(?float $accept, ?float $review): ThresholdOverrides
    {
        $overrides = $accept === null ? (new ThresholdOverrides)->alwaysReview() : (new ThresholdOverrides)->accept($accept);

        return $review === null ? $overrides : $overrides->review($review);
    }

    /**
     * Option => accept and review, before -> after, for every option the
     * tune reaches, and the options that would stop always going to review.
     *
     * @param  array<string, ThresholdOverrides>  $before
     * @param  array<string, ThresholdOverrides>  $after
     * @return array{0: list<array{0: string, 1: string, 2: string}>, 1: list<string>}
     *
     * @throws InvalidThresholds when the result would be unusable
     */
    private function comparison(string $policy, PolicyRegistry $policies, Question $question, ?string $option, array $before, array $after): array
    {
        $resolver = new ThresholdResolver($policies->defaultThresholds());
        $rows = [];
        $unguarded = [];

        foreach ($option === null ? $question->options() : [$option] as $name) {
            $old = $resolver->thresholdsFor($policy, $question, $name, $before);
            $new = $resolver->thresholdsFor($policy, $question, $name, $after);

            if ($old->alwaysReviews() && ! $new->alwaysReviews()) {
                $unguarded[] = $name;
            }

            $rows[] = [
                $name,
                self::change(StatsCommand::accept($old), StatsCommand::accept($new)),
                self::change(StatsCommand::threshold($old->review), StatsCommand::threshold($new->review)),
            ];
        }

        return [$rows, $unguarded];
    }

    private static function change(string $old, string $new): string
    {
        return $old === $new ? $old : "{$old} -> <fg=yellow>{$new}</>";
    }

    private static function key(Model $model): int|string|null
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
