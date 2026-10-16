<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Transfer Call gains an `external` destination: hand the caller to an
 * outside operator's phone number (HandoffDestinations). The goal
 * library is seeded once, so existing installs get it here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $goal = DB::table('intake_goals')->where('key', 'transfer_call')->first();
        if (! $goal) {
            return;
        }

        $fields = json_decode((string) $goal->data_fields, true) ?: [];
        foreach ($fields as &$field) {
            if (($field['key'] ?? null) === 'destination_type' && ! in_array('external', $field['options'] ?? [], true)) {
                $field['options'][] = 'external';
            }
            if (($field['key'] ?? null) === 'destination') {
                $field['hint'] = 'Extension number, DID, or queue name; for external, the operator\'s phone number in E.164 (+15551234567).';
            }
        }
        unset($field);

        DB::table('intake_goals')->where('id', $goal->id)->update([
            'data_fields' => json_encode($fields),
            'description' => 'Transfer the live caller to an extension, a DID, a queue, or an outside live operator (external phone number). An external handoff records the call so the operator\'s system can fetch what was collected through the handoff API.',
        ]);
    }

    public function down(): void
    {
        // Leaving `external` in place is harmless; steps may use it.
    }
};
