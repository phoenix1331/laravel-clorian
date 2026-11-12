<?php

declare(strict_types=1);

use Phoenix1331\Clorian\Exceptions\InvalidThresholds;
use Phoenix1331\Clorian\Questions\BooleanQuestion;
use Phoenix1331\Clorian\Questions\ChoiceQuestion;
use Phoenix1331\Clorian\Questions\Question;
use Phoenix1331\Clorian\Questions\ThresholdOverrides;
use Phoenix1331\Clorian\Questions\Thresholds;

const POLICY = 'App\\Clorian\\TicketTriage';

function defaults(): Thresholds
{
    return new Thresholds(0.9, 0.6);
}

function urgency(): ChoiceQuestion
{
    return Question::choice('urgency', ['low', 'normal', 'high', 'critical'])
        ->accept(0.90)->review(0.60)
        ->option('critical', fn (ThresholdOverrides $o) => $o->alwaysReview())
        ->option('low', fn (ThresholdOverrides $o) => $o->accept(0.75));
}

describe('choice', function (): void {
    it('takes a list of option names', function (): void {
        $question = Question::choice('category', ['billing', 'bug']);

        expect($question)->toBeInstanceOf(ChoiceQuestion::class)
            ->and($question->name())->toBe('category')
            ->and($question->options())->toBe(['billing', 'bug'])
            ->and($question->descriptions())->toBe(['billing' => null, 'bug' => null])
            ->and($question->getInstructions())->toBeNull();
    });

    it('takes a map of option name to description', function (): void {
        $question = Question::choice('urgency', ['low' => 'Can wait a week', 'critical' => 'Service down', 'normal' => '']);

        expect($question->options())->toBe(['low', 'critical', 'normal'])
            ->and($question->descriptions())->toBe(['low' => 'Can wait a week', 'critical' => 'Service down', 'normal' => null]);
    });

    it('keeps numeric option names as strings', function (): void {
        expect(Question::choice('stars', [1 => 'Poor', 5 => 'Great'])->options())->toBe(['1', '5']);
    });

    it('carries instructions for the model', function (): void {
        expect(Question::choice('category', ['billing', 'bug'])->instructions('Which team handles `content`?')->getInstructions())
            ->toBe('Which team handles `content`?');
    });

    it('validates the ticket triage questions', function (): void {
        urgency()->validate(POLICY, defaults());
        Question::choice('category', ['billing', 'bug', 'account', 'feature'])->validate(POLICY, defaults());
    })->throwsNoExceptions();

    it('accepts 2 and 255 options', function (int $count): void {
        Question::choice('q', array_map(fn (int $i): string => "o{$i}", range(1, $count)))->validate(POLICY, defaults());
    })->with([2, 255])->throwsNoExceptions();

    it('rejects fewer than 2 or more than 255 options', function (int $count): void {
        expect(fn () => Question::choice('q', array_map(fn (int $i): string => "o{$i}", range(1, $count)))->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, "Invalid policy [App\\Clorian\\TicketTriage], question [q]: a choice needs 2 to 255 options, got {$count}.");
    })->with([1, 256]);

    it('rejects an empty option list', function (): void {
        expect(fn () => Question::choice('q', [])->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'got 0');
    });

    it('rejects duplicate options', function (): void {
        expect(fn () => Question::choice('q', ['a', 'b', 'a'])->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'options must be unique');
    });

    it('rejects an empty option name', function (?string $option): void {
        expect(fn () => Question::choice('q', ['a', $option])->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'an option name is empty');
    })->with(['empty' => '', 'blank' => '  ', 'null' => null]);

    it('rejects an option name the answers table cannot hold', function (): void {
        $long = str_repeat('x', 192);

        expect(fn () => Question::choice('q', ['a', $long])->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'the option name is longer than 191 characters');
    });
});

describe('boolean', function (): void {
    it('answers true or false', function (): void {
        $question = Question::boolean('needs_human');

        expect($question)->toBeInstanceOf(BooleanQuestion::class)
            ->and($question->options())->toBe(['true', 'false'])
            ->and($question->descriptions())->toBe(['true' => null, 'false' => null]);
    });

    it('turns the name into instructions when none are given', function (string $name, string $instructions): void {
        expect(Question::boolean($name)->getInstructions())->toBe($instructions);
    })->with([
        ['needs_human', 'Needs human'],
        ['is-spam', 'Is spam'],
        ['refund__requested', 'Refund requested'],
    ]);

    it('keeps instructions it is given', function (): void {
        expect(Question::boolean('needs_human', 'The ticket needs a human reply')->getInstructions())->toBe('The ticket needs a human reply');
    });

    it('describes what counts as yes and no', function (): void {
        expect(Question::boolean('needs_human')->when(true: 'A person must reply', false: 'A help article answers it')->descriptions())
            ->toBe(['true' => 'A person must reply', 'false' => 'A help article answers it']);
    });

    it('judges the answer on the probability of its own value', function (float $probability, string $value, float $confidence): void {
        expect(BooleanQuestion::answerFor($probability))->toBe([$value, $confidence]);
    })->with([
        'confident yes' => [0.95, 'true', 0.95],
        'coin toss counts as yes' => [0.5, 'true', 0.5],
        'unsure no' => [0.27, 'false', 0.73],
        'confident no' => [0.02, 'false', 0.98],
        'certain no' => [0.0, 'false', 1.0],
    ]);

    it('takes thresholds per answer', function (): void {
        $question = Question::boolean('needs_human')->accept(0.7)
            ->option('true', fn (ThresholdOverrides $o) => $o->alwaysReview());

        expect($question->thresholdsFor('true', defaults()))->toEqual(Thresholds::alwaysReview(0.6))
            ->and($question->thresholdsFor('false', defaults()))->toEqual(new Thresholds(0.7, 0.6));

        $question->validate(POLICY, defaults());
    });
});

describe('thresholds', function (): void {
    it('falls back to the defaults when a question sets nothing', function (): void {
        expect(Question::choice('category', ['billing', 'bug'])->thresholdsFor('bug', defaults()))->toEqual(defaults());
    });

    it('layers config defaults, then the question, then the option', function (): void {
        $question = Question::choice('urgency', ['low', 'normal', 'high', 'critical'])
            ->review(0.5)
            ->option('low', fn (ThresholdOverrides $o) => $o->accept(0.75));

        expect($question->thresholdsFor('normal', defaults()))->toEqual(new Thresholds(0.9, 0.5))
            ->and($question->thresholdsFor('low', defaults()))->toEqual(new Thresholds(0.75, 0.5));
    });

    it('lets an option opt back into acceptance under an always-review question', function (): void {
        $question = Question::choice('refund', ['yes', 'no'])
            ->alwaysReview()
            ->option('no', fn (ThresholdOverrides $o) => $o->accept(0.95));

        expect($question->thresholdsFor('yes', defaults())->alwaysReviews())->toBeTrue()
            ->and($question->thresholdsFor('no', defaults()))->toEqual(new Thresholds(0.95, 0.6));
    });

    it('applies the urgency overrides', function (): void {
        expect(urgency()->thresholdsFor('critical', defaults()))->toEqual(Thresholds::alwaysReview(0.6))
            ->and(urgency()->thresholdsFor('low', defaults()))->toEqual(new Thresholds(0.75, 0.6))
            ->and(urgency()->thresholdsFor('high', defaults()))->toEqual(new Thresholds(0.9, 0.6));
    });

    it('merges repeated option calls', function (): void {
        $question = Question::choice('q', ['a', 'b'])
            ->option('a', fn (ThresholdOverrides $o) => $o->accept(0.8))
            ->option('a', fn (ThresholdOverrides $o) => $o->review(0.4));

        expect($question->thresholdsFor('a', defaults()))->toEqual(new Thresholds(0.8, 0.4));
    });

    it('names the question when its own thresholds are invalid', function (): void {
        expect(fn () => Question::choice('urgency', ['low', 'high'])->review(0.95)->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'Invalid policy [App\\Clorian\\TicketTriage], question [urgency]: review (0.95) must be lower than accept (0.9).');
    });

    it('names the option when its thresholds are invalid', function (): void {
        $question = Question::choice('urgency', ['low', 'high'])->option('low', fn (ThresholdOverrides $o) => $o->accept(1.5));

        try {
            $question->validate(POLICY, defaults());
            $this->fail('No exception thrown.');
        } catch (InvalidThresholds $e) {
            expect($e->getMessage())->toBe('Invalid policy [App\\Clorian\\TicketTriage], question [urgency], option [low]: accept must be between 0 and 1, got 1.5.')
                ->and($e->policy)->toBe(POLICY)
                ->and($e->question)->toBe('urgency')
                ->and($e->option)->toBe('low');
        }
    });

    it('catches an option whose review crosses the question\'s accept', function (): void {
        $question = Question::choice('urgency', ['low', 'high'])->accept(0.7)->option('high', fn (ThresholdOverrides $o) => $o->review(0.7));

        expect(fn () => $question->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'option [high]: review (0.7) must be lower than accept (0.7)');
    });

    it('rejects thresholds for an option the question does not have', function (): void {
        $question = Question::choice('urgency', ['low', 'high'])->option('urgent', fn (ThresholdOverrides $o) => $o->alwaysReview());

        expect(fn () => $question->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'option [urgent]: not one of the options (low, high)');
    });

    it('rejects boolean option overrides other than true and false', function (): void {
        $question = Question::boolean('needs_human')->option('yes', fn (ThresholdOverrides $o) => $o->alwaysReview());

        expect(fn () => $question->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'not one of the options (true, false)');
    });
});

describe('names', function (): void {
    it('rejects an empty question name', function (): void {
        expect(fn () => Question::boolean(' ')->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'the question name is empty');
    });

    it('rejects a name the answers table cannot hold', function (): void {
        expect(fn () => Question::boolean(str_repeat('q', 101))->validate(POLICY, defaults()))
            ->toThrow(InvalidThresholds::class, 'the question name is longer than 100 characters');
    });

    it('names only the policy when no question is involved', function (): void {
        expect(InvalidThresholds::forPolicy(POLICY, 'no questions')->getMessage())
            ->toBe('Invalid policy [App\\Clorian\\TicketTriage]: no questions.');
    });
});
