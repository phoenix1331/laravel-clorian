<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Tests\Fixtures\Policies;

use Phoenix1331\Clorian\Policies\Policy;
use Phoenix1331\Clorian\Questions\Question;

/**
 * A subjectless policy for the sync demo, with option descriptions and no
 * defaults or apply().
 */
final class ContactIntent extends Policy
{
    public function questions(): array
    {
        return [
            Question::choice('intent', [
                'billing' => 'Invoices, payments, refunds',
                'account_access' => 'Cannot log in or reset a password',
                'bug_report' => 'Something in the product is broken',
                'sales' => 'Pricing, plans or a demo',
                'spam' => 'Advertising or nonsense',
            ])->instructions('What does the person writing `content` want?'),
        ];
    }
}
