<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Attributes\Weigh;
use Phoenix1331\Clorian\Concerns\HasWeighings;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

/**
 * The tickets table without timestamps, for rescan's --since check.
 */
#[Weigh(TicketTriage::class, fields: ['subject', 'body'])]
final class UntimedTicket extends Model
{
    use HasWeighings;

    public $timestamps = false;

    protected $table = 'tickets';

    protected $guarded = ['id'];
}
