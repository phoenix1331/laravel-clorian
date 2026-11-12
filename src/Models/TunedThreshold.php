<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Phoenix1331\Clorian\Database\Factories\TunedThresholdFactory;

/**
 * A threshold written by clorian:tune (or the Filament calibration widget).
 * Beats anything set in the policy or config. A null option covers the whole
 * question; a null accept means always review.
 *
 * @property int $id
 * @property string $policy
 * @property string $question
 * @property string|null $option
 * @property float|null $accept
 * @property float|null $review
 * @property string|null $tuned_by_type
 * @property int|string|null $tuned_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TunedThreshold extends Model
{
    /** @use HasFactory<TunedThresholdFactory> */
    use HasFactory;

    /** Stored instead of null so the unique key holds on every database. */
    public const string WHOLE_QUESTION = '';

    protected $table = 'clorian_thresholds';

    protected $guarded = ['id'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function tunedBy(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): TunedThresholdFactory
    {
        return TunedThresholdFactory::new();
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function option(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?string => is_string($value) && $value !== self::WHOLE_QUESTION ? $value : null,
            set: static fn (?string $value): string => $value ?? self::WHOLE_QUESTION,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accept' => 'float',
            'review' => 'float',
        ];
    }
}
