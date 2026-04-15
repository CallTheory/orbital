<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LogoutReason;
use Illuminate\Database\Seeder;

/**
 * Starter vocabulary for the operator logout modal. Editable by
 * super-admins under Features → Logout Reasons. These are what
 * populates the "why are you signing out?" dropdown operators
 * must pick from before their session actually ends.
 */
class LogoutReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            [
                'label' => 'End of shift',
                'description' => 'Scheduled shift end.',
                'sort_order' => 10,
            ],
            [
                'label' => 'Going home',
                'description' => 'Heading out early.',
                'sort_order' => 20,
            ],
            [
                'label' => 'Taking a break',
                'description' => 'Stepping away for a while.',
                'sort_order' => 30,
            ],
            [
                'label' => 'System issue',
                'description' => 'Something is broken, logging out to reset.',
                'sort_order' => 40,
            ],
            [
                'label' => 'Other',
                'description' => 'Anything not covered above.',
                'sort_order' => 100,
            ],
        ];

        foreach ($reasons as $data) {
            LogoutReason::updateOrCreate(
                ['label' => $data['label']],
                $data + ['is_active' => true],
            );
        }
    }
}
