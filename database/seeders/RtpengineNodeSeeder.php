<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\RtpengineNode;
use Illuminate\Database\Seeder;

/**
 * Seeds the two rtpengine nodes that every Orbital dev install
 * ships with — co-located on the Kamailio VMs in Phase 1, but
 * still get distinct hostnames so the registry works the same
 * way as the Asterisk backend roster.
 *
 * Platform operators extend or replace these rows through the
 * admin UI; the seeder only runs at fresh install.
 */
class RtpengineNodeSeeder extends Seeder
{
    public function run(): void
    {
        $seed = [
            ['hostname' => 'rtpengine-1', 'display_name' => 'Edge RTP 1', 'sort_order' => 10],
            ['hostname' => 'rtpengine-2', 'display_name' => 'Edge RTP 2', 'sort_order' => 20],
        ];

        foreach ($seed as $row) {
            RtpengineNode::query()->firstOrCreate(
                ['hostname' => $row['hostname']],
                $row + [
                    'ng_port' => 22222,
                    'prom_port' => 9059,
                    'recording_spool_path' => '/var/spool/rtpengine',
                    'is_active' => true,
                ],
            );
        }
    }
}
