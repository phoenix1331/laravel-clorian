<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Attributes\Weigh;
use Phoenix1331\Clorian\Concerns\HasWeighings;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

/**
 * A ticket whose table has the clorian_status column.
 *
 * @property int $id
 * @property string|null $clorian_status
 */
#[Weigh(TicketTriage::class, fields: ['subject', 'body'])]
final class StatusTicket extends Model
{
    use HasWeighings;

    protected $table = 'status_tickets';

    protected $guarded = ['id'];
}
