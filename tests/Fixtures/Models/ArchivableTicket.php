<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A soft-deleting view of the tickets table, for retention tests.
 *
 * @property int $id
 */
final class ArchivableTicket extends Model
{
    use SoftDeletes;

    protected $table = 'tickets';

    protected $guarded = ['id'];
}
