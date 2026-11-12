<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A support ticket: the subject Clorian weighs in feature tests.
 *
 * @property int $id
 * @property string $subject
 * @property string $body
 * @property string|null $queue
 * @property string|null $priority
 */
final class Ticket extends Model
{
    protected $table = 'tickets';

    protected $guarded = ['id'];
}
