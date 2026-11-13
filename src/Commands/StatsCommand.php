<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Phoenix1331\Clorian\Calibration\Band;
use Phoenix1331\Clorian\Calibration\Calibrator;
use Phoenix1331\Clorian\Calibration\Observations;
use Phoenix1331\Clorian\Calibration\OptionStats;
use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Policies\PolicyRegistry;
use Phoenix1331\Clorian\Questions\Thresholds;
use Phoenix1331\Clorian\Resolution\ThresholdResolver;
use Phoenix1331\Clorian\Resolution\TunedThresholds;

/**
 * Calibration as tables: how answers resolved, how reviewers judged them,
 * by confidence band, and the accept threshold the reviews support.
 */
final class StatsCommand extends Command
{
    protected $signature = 'clorian:stats
        {policy? : A policy class; every policy when left out}
        {--days=30 : Days of answers to include; 0 for all}';

    protected $description = 'Show how answers resolved, how reviewers judged them and the accept thresholds the reviews support';

    public function handle(Calibrator $calibrator, Observations $observations, PolicyRegistry $policies, TunedThresholds $tuned): int
    {
        $days = $this->option('days');

        if (! is_string($days) || ! ctype_digit($days)) {
            $this->components->error('--days must be a whole number of days, or 0 for all.');

            return self::INVALID;
        }

        $policy = $this->argument('policy');
        $policy = is_string($policy) && $policy !== '' ? ltrim($policy, '\\') : null;
        $since = (int) $days === 0 ? null : Carbon::now()->subDays((int) $days);
        $period = $since === null ? 'all time' : "the last {$days} days";

        $stats = $calibrator->calibrate($observations->read($policy, $since));

        if ($stats === []) {
            $this->components->info(sprintf('No answers recorded%s in %s.', $policy === null ? '' : " for {$policy}", $period));

            return self::SUCCESS;
        }

        $byPolicy = [];

        foreach ($stats as $option) {
            $byPolicy[$option->policy][] = $option;
        }

        foreach ($byPolicy as $name => $options) {
            $this->report($name, $options, $period, $policies, $tuned);
        }

        $this->line(sprintf(
            '  Suggested accept: the lowest band edge whose reviews, and every reviewed band above it, were upheld at least %s of the time over %d or more reviews.',
            self::percent($calibrator->target),
            $calibrator->minSample,
        ));
        $this->line('  Apply one with: php artisan clorian:tune {policy} {question} --option={option} --accept={value}');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  list<OptionStats>  $options
     */
    private function report(string $policy, array $options, string $period, PolicyRegistry $policies, TunedThresholds $tuned): void
    {
        $current = $this->currentAccept($policy, $policies, $tuned);

        $this->newLine();
        $this->line("  <fg=green;options=bold>{$policy}</>, {$period}");

        $this->table(
            ['Question', 'Option', 'Accepted', 'Review', 'Unresolved', 'Reviewed', 'Upheld', 'Overturned', 'Missed', 'Accept now', 'Suggested'],
            array_map(static fn (OptionStats $s): array => [
                $s->question,
                $s->option,
                $s->accepted,
                $s->review,
                $s->unresolved,
                $s->reviews->reviewed(),
                self::percent($s->reviews->upheldRate()),
                self::percent($s->reviews->overturnedRate()),
                self::percent($s->reviews->missedRate()),
                $current($s->question, $s->option),
                $s->suggestedAccept === null ? '-' : self::threshold($s->suggestedAccept),
            ], $options),
        );

        $this->table(
            ['Question', 'Option', ...array_map(static fn (Band $band): string => $band->label(), $options[0]->bands)],
            array_map(static fn (OptionStats $s): array => [
                $s->question,
                $s->option,
                ...array_map(self::band(...), $s->bands),
            ], $options),
        );

        $this->newLine();
    }

    /**
     * The accept threshold in force now, or "-" for a policy or question
     * that no longer exists.
     *
     * @return callable(string, string): string
     */
    private function currentAccept(string $policy, PolicyRegistry $policies, TunedThresholds $tuned): callable
    {
        try {
            $instance = $policies->get($policy);
        } catch (InvalidThresholds) {
            return static fn (): string => '-';
        }

        $questions = $policies->questions($instance);
        $overrides = $tuned->forPolicy($policy);
        $resolver = new ThresholdResolver($policies->defaultThresholds());

        return static function (string $question, string $option) use ($policy, $questions, $overrides, $resolver): string {
            if (! isset($questions[$question]) || ! in_array($option, $questions[$question]->options(), true)) {
                return '-';
            }

            $thresholds = $resolver->thresholdsFor($policy, $questions[$question], $option, $overrides[$question] ?? []);

            return self::accept($thresholds);
        };
    }

    private static function band(Band $band): string
    {
        $reviewed = $band->reviews->reviewed();

        if ($reviewed > 0) {
            return sprintf('%s of %d', self::percent($band->reviews->upheldRate()), $reviewed);
        }

        return $band->answers > 0 ? "{$band->answers} unreviewed" : '-';
    }

    public static function accept(Thresholds $thresholds): string
    {
        return $thresholds->accept === null ? 'always review' : self::threshold($thresholds->accept);
    }

    public static function threshold(float $value): string
    {
        return number_format($value, 2);
    }

    private static function percent(?float $rate): string
    {
        return $rate === null ? '-' : round($rate * 100).'%';
    }
}
