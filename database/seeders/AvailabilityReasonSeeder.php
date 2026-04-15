<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AvailabilityReason;
use Illuminate\Database\Seeder;

/**
 * Starter vocabulary for operator availability reasons. The
 * platform operator can edit or extend this list from
 * Features → Availability Reasons at any time — this just
 * gets new installs to a reasonable default without requiring
 * admin setup before the first operator can say they're on break.
 *
 * The `available` row is the one special case: admins can rename
 * it ("On the floor", "Taking calls", etc.), re-color it, edit
 * the description, but they cannot delete it or flip its
 * `blocks_new_work` / `is_active` flags from the admin UI.
 * Without it, the system has no "accepting work" state at all
 * and every operator is permanently off the floor. The model
 * rejects deletion on the backend as a safety net for any path
 * (tinker, bulk action, etc.) that might try.
 *
 * Every OTHER starter reason has `blocks_new_work = true` since
 * they're all "I'm not taking work right now" states. Admins can
 * add softer reasons (e.g. "Back in 5 — still send me urgent
 * stuff") and set blocks_new_work to false if they want routing
 * to keep firing.
 */
class AvailabilityReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            [
                // The built-in "I'm taking work" row. Slug is the
                // AvailabilityReason::AVAILABLE constant; protected
                // from deletion by the model's deleting hook.
                'slug' => AvailabilityReason::AVAILABLE,
                'label' => 'Available',
                'description' => 'On the floor and accepting new calls and email.',
                'dot_color' => '#22c55e',
                'blocks_new_work' => false,
                'sort_order' => 0,
            ],
            [
                'slug' => 'in_meeting',
                'label' => 'In meeting',
                'description' => 'With a supervisor or teammate.',
                'dot_color' => '#f59e0b',
                'blocks_new_work' => true,
                'sort_order' => 10,
            ],
            [
                'slug' => 'training',
                'label' => 'Training',
                'description' => 'In training or learning the ropes.',
                'dot_color' => '#3b82f6',
                'blocks_new_work' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'unavailable',
                'label' => 'Unavailable',
                'description' => 'Not taking new work right now.',
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
