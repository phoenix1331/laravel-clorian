<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Phoenix1331\Clorian\Database\Factories\WeighingAnswerFactory;
use Phoenix1331\Clorian\Resolution\Status;
use Phoenix1331\Clorian\Reviews\ReviewAction;

/**
 * One question's answer within a weighing, with the thresholds that were in
 * force when it resolved and, once a human has looked, their decision.
 *
 * @property int $id
 * @property int $weighing_id
 * @property string $question
 * @property string|null $value
 * @property float $confidence
 * @property array<string, float> $scores option => probability; key order is not kept on MySQL, so order by the question's options
 * @property float|null $accept_threshold
 * @property float|null $review_threshold
 * @property Status $status
 * @property ReviewAction|null $review_action
 * @property string|null $reviewed_value
 * @property string|null $reviewer_type
 * @property int|string|null $reviewer_id
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Weighing $weighing
 */
final class WeighingAnswer extends Model
{
    /** @use HasFactory<WeighingAnswerFactory> */
    use HasFactory;

    protected $table = 'clorian_answers';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Weighing, $this>
     */
    public function weighing(): BelongsTo
    {
        return $this->belongsTo(Weighing::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reviewer(): MorphTo
    {
        return $this->morphTo();
    }

    public function isReviewed(): bool
    {
        return $this->review_action !== null;
    }

    protected static function newFactory(): WeighingAnswerFactory
    {
        return WeighingAnswerFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'scores' => 'array',
            'accept_threshold' => 'float',
            'review_threshold' => 'float',
            'status' => Status::class,
            'review_action' => ReviewAction::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
