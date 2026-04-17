<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AvailabilityReason;
use Illuminate\Database\Seeder;

/**
 * Starter vocabulary for operator availability reasons.
 *
 * `blocks_voice` and `blocks_non_voice` control which channels
 * stop routing when an operator selects this status:
 *   - Voice = phone calls (Asterisk queue membership paused)
 *   - Non-voice = email, SMS, chat (inbox hides unclaimed threads)
 *
 * The `available` row is protected from deletion and must have
 * both channels unblocked.
 */
class AvailabilityReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            [
                'slug' => AvailabilityReason::AVAILABLE,
                'label' => 'Available',
                'description' => 'On the floor and accepting all work.',
                'dot_color' => '#22c55e',
                'blocks_voice' => false,
                'blocks_non_voice' => false,
                'sort_order' => 0,
            ],
            [
                'slug' => 'voice_only',
                'label' => 'Voice Only',
                'description' => 'Taking phone calls but not email, SMS, or chat.',
                'dot_color' => '#8b5cf6',
                'blocks_voice' => false,
                'blocks_non_voice' => true,
                'sort_order' => 5,
            ],
            [
                'slug' => 'non_voice_only',
                'label' => 'Non-Voice Only',
                'description' => 'Working email, SMS, and chat but not taking phone calls.',
                'dot_color' => '#06b6d4',
                'blocks_voice' => true,
                'blocks_non_voice' => false,
                'sort_order' => 6,
            ],
            [
                'slug' => 'in_meeting',
                'label' => 'In meeting',
                'description' => 'With a supervisor or teammate.',
                'dot_color' => '#f59e0b',
                'blocks_voice' => true,
                'blocks_non_voice' => true,
                'sort_order' => 10,
            ],
            [
                'slug' => 'training',
                'label' => 'Training',
                'description' => 'In training or learning the ropes.',
                'dot_color' => '#3b82f6',
                'blocks_voice' => true,
                'blocks_non_voice' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'unavailable',
                'label' => 'Unavailable',
                'description' => 'Not taking new work right now.',
                'dot_color' => '#6b7280',
                'blocks_voice' => true,
                'blocks_non_voice' => true,
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
