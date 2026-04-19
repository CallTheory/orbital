<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AsteriskBackend;
use Illuminate\Database\Seeder;

/**
 * Seeds the two Asterisk nodes that every Orbital dev install
 * ships with. Platform operators extend or replace these rows
 * through the admin UI; the seeder only runs at fresh install.
 */
class AsteriskBackendSeeder extends Seeder
{
    public function run(): void
    {
        $seed = [
            ['hostname' => 'asterisk-1', 'display_name' => 'Asterisk 1', 'sort_order' => 10],
            ['hostname' => 'asterisk-2', 'display_name' => 'Asterisk 2', 'sort_order' => 20],
        ];

        foreach ($seed as $row) {
            AsteriskBackend::query()->firstOrCreate(
                ['hostname' => $row['hostname']],
                $row + [
                    'sip_port' => 5060,
                    'ami_port' => 5038,
                    'is_active' => true,
                ],
            );
        }
    }
}
