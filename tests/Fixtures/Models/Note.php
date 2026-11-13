<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Concerns\HasWeighings;

/**
 * Uses HasWeighings but forgot #[Weigh].
 */
final class Note extends Model
{
    use HasWeighings;

    protected $table = 'tickets';

    protected $guarded = ['id'];
}
