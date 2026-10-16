<?php

declare(strict_types=1);

use App\Models\IntakeGoal;
use Illuminate\Database\Migrations\Migration;

/**
 * Gather Intake Fields: one step that collects every field the client
 * defines, so a shared flow (the intake → handoff template) works for
 * clients with different fields. The goal library is seeded once, so
 * existing installs get it here; fresh ones from IntakeGoalLibrarySeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (IntakeGoal::query()->where('key', 'gather_intake_fields')->exists()) {
            return;
        }

        IntakeGoal::query()->create([
            'key' => 'gather_intake_fields',
            'name' => 'Gather Intake Fields',
            'category' => 'intake',
            'icon' => 'heroicon-o-clipboard-document-list',
            'description' => 'Collect every intake field this client defines (Clients → Intake Fields), one at a time. One step serves any client, so a shared flow works for all of them.',
            'talking_points' => [
                'Ask for each field in "Variables available during this call", one at a time.',
                'Confirm the spelling of names and read numbers back.',
                'Skip any field the caller has already given.',
            ],
            'data_fields' => [
                ['key' => 'prompt', 'label' => 'How the AI opens', 'type' => 'template', 'required' => false, 'hint' => 'Optional, e.g. "I just need a few details so the operator can help you."'],
            ],
            'completion' => ['type' => 'node_completed'],
            'exits' => ['continue', 'rejected', 'failure'],
            'max_transitions' => 3,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        IntakeGoal::query()->where('key', 'gather_intake_fields')->delete();
    }
};
