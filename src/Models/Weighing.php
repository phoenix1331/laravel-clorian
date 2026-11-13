<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Phoenix1331\Clorian\Database\Factories\WeighingFactory;
use Phoenix1331\Clorian\Questions\BooleanQuestion;
use Phoenix1331\Clorian\Resolution\Status;

/**
 * One run of a policy against a subject or a subjectless input: the driver's
 * resolved model version, the outcome, and one answer per question.
 *
 * @property int $id
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string $policy
 * @property string|null $model_version
 * @property string $driver
 * @property WeighingStatus $status
 * @property string|null $content_hash
 * @property array<string, mixed>|null $input
 * @property string|null $error
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, WeighingAnswer> $answers
 */
final class Weighing extends Model
{
    /** @use HasFactory<WeighingFactory> */
    use HasFactory;

    protected $table = 'clorian_weighings';

    protected $guarded = ['id'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<WeighingAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(WeighingAnswer::class);
    }

    /**
     * Whether a question was answered with a value that can be acted on:
     * accepted, or confirmed or corrected by a human. Booleans take true or false.
     */
    public function accepted(string $question, string|bool $value): bool
    {
        $option = is_bool($value) ? ($value ? BooleanQuestion::TRUE : BooleanQuestion::FALSE) : $value;
        $answer = $this->answers->firstWhere('question', $question);

        if (! $answer instanceof WeighingAnswer) {
            return false;
        }

        if ($answer->isReviewed()) {
            return $answer->reviewed_value === $option;
        }

        return $answer->status === Status::Accepted && $answer->value === $option;
    }

    protected static function newFactory(): WeighingFactory
    {
        return WeighingFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WeighingStatus::class,
            'input' => 'encrypted:array',
            'meta' => 'array',
        ];
    }
}
