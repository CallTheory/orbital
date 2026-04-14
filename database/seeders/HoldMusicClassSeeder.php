<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\HoldMusicClass;
use Illuminate\Database\Seeder;

/**
 * Always seeds the built-in `default` hold music class. This row is the
 * platform's safety net — the dial plan generator falls back to it when
 * no other class is configured.
 *
 * The HoldMusicResource locks this row from rename/delete.
 */
class HoldMusicClassSeeder extends Seeder
{
    public function run(): void
    {
        HoldMusicClass::firstOrCreate(
            ['name' => HoldMusicClass::DEFAULT_CLASS_NAME],
            [
                'label' => 'Default (Asterisk built-in)',
                'description' => 'Asterisk\'s bundled music-on-hold sounds. Always available; cannot be deleted.',
                'type' => HoldMusicClass::TYPE_BUILTIN,
                'is_active' => true,
                'is_default' => true,
            ],
        );
    }
}
