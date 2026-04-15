<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AvailabilityReason;
use Illuminate\Database\Seeder;

/**
 * Starter vocabulary for operator availability reasons. The
 * platform operator can edit, disable, or extend this list
 * from Features → Availability Reasons at any time — this
 * just gets new installs to a reasonable default without
 * requiring admin setup before the first operator can say
 * they're on break.
 *
 * Every starter reason has `blocks_new_work = true` since
 * they're all "I'm not taking work right now" states. Admins
 * can add softer reasons (e.g. "Back in 5 — still send me
 * urgent stuff") and set blocks_new_work to false if they
 * want routing to keep firing.
 */
class AvailabilityReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            [
                'slug' => 'on_break',
                'label' => 'On break',
                'description' => 'Short break, back shortly.',
                'dot_color' => '#f59e0b',
                'blocks_new_work' => true,
                'sort_order' => 10,
            ],
            [
                'slug' => 'lunch',
                'label' => 'Lunch',
                'description' => 'At lunch.',
                'dot_color' => '#f59e0b',
                'blocks_new_work' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'in_meeting',
                'label' => 'In meeting',
                'description' => 'With a supervisor or teammate.',
                'dot_color' => '#f59e0b',
                'blocks_new_work' => true,
                'sort_order' => 30,
            ],
            [
                'slug' => 'training',
                'label' => 'Training',
                'description' => 'In training or learning the ropes.',
                'dot_color' => '#3b82f6',
                'blocks_new_work' => true,
                'sort_order' => 40,
            ],
            [
                'slug' => 'offline_manual',
                'label' => 'Offline',
                'description' => 'Fully clocked out.',
                'dot_color' => '#6b7280',
                'blocks_new_work' => true,
                'sort_order' => 100,
            ],
        ];

        foreach ($reasons as $data) {
            AvailabilityReason::updateOrCreate(
                ['slug' => $data['slug']],
                $data + ['is_active' => true],
            );
        }
    }
}
