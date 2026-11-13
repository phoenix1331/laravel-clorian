<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User;

/**
 * The person reviewing answers in feature tests.
 *
 * @property int $id
 * @property string $name
 */
final class Reviewer extends User
{
    protected $table = 'reviewers';

    protected $guarded = ['id'];
}
