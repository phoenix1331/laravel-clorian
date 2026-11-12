<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Phoenix1331\Clorian\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
