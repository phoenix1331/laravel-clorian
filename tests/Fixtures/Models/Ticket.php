<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Attributes\Weigh;
use Phoenix1331\Clorian\Concerns\HasWeighings;
use Phoenix1331\Clorian\Tests\Fixtures\Policies\TicketTriage;

/**
 * A support ticket: the subject Clorian weighs in feature tests.
 *
 * @property int $id
 * @property string $subject
 * @property string $body
 * @property string|null $queue
 * @property string|null $priority
 */
#[Weigh(TicketTriage::class, fields: ['subject', 'body'])]
final class Ticket extends Model
{
    use HasWeighings;

    protected $table = 'tickets';

    protected $guarded = ['id'];
}
