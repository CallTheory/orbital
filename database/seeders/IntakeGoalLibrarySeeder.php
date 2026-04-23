<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IntakeGoal;
use Illuminate\Database\Seeder;

/**
 * Platform-owned palette of intake primitives.
 *
 * Each row describes ONE node type the flow compiler knows how to
 * render into a prompt/script. A flow is an ordered (eventually DAG)
 * composition of these primitives, and each placement carries its
 * own parameters in `intake_flow_steps.step_params` — so the library
 * row stays generic and every instance in a flow is customizable.
 *
 * The schema for each node's `step_params` is documented in its
 * `data_fields` array here. The Filament flow editor reads that
 * array to know which inputs to render when you drop the node into
 * a flow.
 *
 * Keys are stable — the compiler branches on them. Rename with care.
 */
class IntakeGoalLibrarySeeder extends Seeder
{
    public function run(): void
    {
        // Remove any library rows that are no longer part of the palette
        // so migrating from the old "bundled goal" seed cleans up without
        // a hand-rolled migration. Soft-deleted via the SoftDeletes cast.
        $currentKeys = array_map(fn ($g) => $g['key'], $this->primitives());
        IntakeGoal::whereNotIn('key', $currentKeys)->delete();

        foreach ($this->primitives() as $primitive) {
            IntakeGoal::updateOrCreate(
                ['key' => $primitive['key']],
                $primitive + ['is_active' => true],
            );
        }
    }

    /**
     * The primitive palette.
     *
     * Categories:
     *   intake       — collect info from the caller
     *   action       — do something on behalf of the caller
     *   control      — branching / conditional flow (no user-visible side effect)
     *
     * @return array<int, array<string, mixed>>
     */
    private function primitives(): array
    {
        return [
            // ─── Intake primitives ───────────────────────────────────
            [
                'key' => 'gather_detail',
                'name' => 'Gather Detail',
                'category' => 'intake',
                'icon' => 'heroicon-o-clipboard-document',
                'description' => 'Collect one specific piece of information from the caller.',
                'talking_points' => [
                    'Ask the caller for the configured piece of information.',
                    'If they hesitate, offer the hint verbatim.',
                    'Read the value back to confirm before moving on.',
                ],
                // Parameters the flow author sets when placing this node.
                // The Filament editor renders one control per entry.
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (variable name)', 'type' => 'string', 'required' => true, 'hint' => 'e.g. caller_name, callback_phone, account_number'],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true, 'hint' => 'What the operator/CRM sees. e.g. "Caller Name"'],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'textarea', 'required' => false, 'hint' => 'Optional override. Leave blank for the AI to phrase it.'],
                    ['key' => 'type', 'label' => 'Value type', 'type' => 'select', 'required' => true, 'options' => ['string', 'phone', 'email', 'date', 'number', 'choice'], 'hint' => 'Controls validation and how the AI confirms.'],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                    ['key' => 'hint', 'label' => 'Fallback hint', 'type' => 'string', 'required' => false, 'hint' => 'Spoken if the caller hesitates.'],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'identify_reason',
                'name' => 'Identify Reason for Call',
                'category' => 'intake',
                'icon' => 'heroicon-o-question-mark-circle',
                'description' => 'Capture the caller\'s reason in their own words (open-ended).',
                'talking_points' => [
                    'Ask why the caller is reaching out today.',
                    'Let them describe the situation in their own words.',
                    'Do not interrupt or paraphrase until they finish.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (variable name)', 'type' => 'string', 'required' => true, 'hint' => 'Default: reason'],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'textarea', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'verify_caller',
                'name' => 'Verify Caller Identity',
                'category' => 'intake',
                'icon' => 'heroicon-o-identification',
                'description' => 'Confirm the caller against a directory or known customer list.',
                'talking_points' => [
                    'Ask for the configured identifier (phone, account number, email).',
                    'Look it up against the directory.',
                    'If a match is found, carry the identity forward for later nodes.',
                    'If no match, continue — downstream branching decides what to do.',
                ],
                'data_fields' => [
                    ['key' => 'lookup_field', 'label' => 'Lookup by', 'type' => 'select', 'required' => true, 'options' => ['phone', 'account_number', 'email'], 'hint' => 'Which field identifies the caller.'],
                    ['key' => 'match_slot', 'label' => 'Store match result as', 'type' => 'string', 'required' => true, 'hint' => 'e.g. matched_customer_id'],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{match_slot}'],
            ],

            // ─── Action primitives ───────────────────────────────────
            [
                'key' => 'save_message',
                'name' => 'Save Message',
                'category' => 'action',
                'icon' => 'heroicon-o-envelope',
                'description' => 'Persist the collected fields as a message to the client\'s portal inbox.',
                'talking_points' => [
                    'Confirm to the caller that a message will be passed along.',
                    'Read back the included fields so they can correct mistakes.',
                ],
                'data_fields' => [
                    ['key' => 'include_slots', 'label' => 'Fields to include', 'type' => 'slot_list', 'required' => true, 'hint' => 'Names of earlier gather_detail slots to persist.'],
                    ['key' => 'destination', 'label' => 'Destination', 'type' => 'select', 'required' => false, 'options' => ['inbox', 'email_queue'], 'hint' => 'Default: inbox.'],
                ],
                'completion' => ['type' => 'node_completed'],
            ],

            [
                'key' => 'answer_question',
                'name' => 'Answer Question',
                'category' => 'action',
                'icon' => 'heroicon-o-chat-bubble-bottom-center-text',
                'description' => 'Answer the caller\'s question using the attached knowledge stores. Falls back cleanly when nothing matches.',
                'talking_points' => [
                    'Search the attached knowledge stores before replying.',
                    'If an answer is found, summarize it in two sentences.',
                    'If not, say you don\'t have that information and offer the configured fallback action.',
                ],
                'data_fields' => [
                    ['key' => 'knowledge_store_ids', 'label' => 'Knowledge stores', 'type' => 'knowledge_store_list', 'required' => false, 'hint' => 'Which stores to search. None = search all stores attached to this client.'],
                    ['key' => 'on_no_match', 'label' => 'When no answer is found', 'type' => 'select', 'required' => false, 'options' => ['offer_message', 'transfer', 'end_politely'], 'hint' => 'Default: offer_message.'],
                ],
                'completion' => ['type' => 'node_completed'],
            ],

            [
                'key' => 'transfer_call',
                'name' => 'Transfer Call',
                'category' => 'action',
                'icon' => 'heroicon-o-arrow-right-circle',
                'description' => 'Transfer the live caller to an extension, a DID, or a queue.',
                'talking_points' => [
                    'Tell the caller who you\'re transferring them to before bridging.',
                    'Perform the configured transfer.',
                ],
                'data_fields' => [
                    ['key' => 'destination_type', 'label' => 'Destination type', 'type' => 'select', 'required' => true, 'options' => ['extension', 'did', 'queue']],
                    ['key' => 'destination', 'label' => 'Destination', 'type' => 'string', 'required' => true, 'hint' => 'Extension number, DID, or queue name.'],
                    ['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'required' => false, 'options' => ['cold', 'warm'], 'hint' => 'Default: cold.'],
                ],
                'completion' => ['type' => 'node_completed'],
            ],

            [
                'key' => 'schedule_callback',
                'name' => 'Schedule a Callback',
                'category' => 'action',
                'icon' => 'heroicon-o-calendar',
                'description' => 'Capture a preferred callback window and persist it on the message.',
                'talking_points' => [
                    'Ask the caller when the best time to reach them is.',
                    'Record the window and confirm.',
                ],
                'data_fields' => [
                    ['key' => 'window_slot', 'label' => 'Store window as', 'type' => 'string', 'required' => true, 'hint' => 'Default: callback_window'],
                    ['key' => 'allow_specific_time', 'label' => 'Allow specific date/time?', 'type' => 'boolean', 'required' => false, 'hint' => 'Otherwise accept fuzzy windows ("tomorrow afternoon").'],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{window_slot}'],
            ],

            // ─── Control primitives (visual-editor primitives; compiler
            // treats them as no-ops today, but they render as real nodes
            // so authors can lay out the intended graph before the
            // runtime catches up). ─────────────────────────────────────
            [
                'key' => 'branch_if',
                'name' => 'Branch (If / Else)',
                'category' => 'control',
                'icon' => 'heroicon-o-arrows-right-left',
                'description' => 'Evaluate a condition against earlier collected data and pick a path.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'condition', 'label' => 'Condition', 'type' => 'expression', 'required' => true, 'hint' => 'e.g. matched_customer_id != null   ·   callback_phone starts_with "+1"'],
                    ['key' => 'on_true_goto', 'label' => 'If true, go to step', 'type' => 'step_ref', 'required' => true],
                    ['key' => 'on_false_goto', 'label' => 'If false, go to step', 'type' => 'step_ref', 'required' => false, 'hint' => 'Default: next step.'],
                ],
                'completion' => ['type' => 'branch'],
            ],

            [
                'key' => 'branch_on_time_of_day',
                'name' => 'Branch on Time of Day',
                'category' => 'control',
                'icon' => 'heroicon-o-clock',
                'description' => 'Pick a path based on the current time, weekday, or holiday calendar.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'windows', 'label' => 'Business-hour windows', 'type' => 'time_window_list', 'required' => true, 'hint' => 'Day + start + end + where to go when matched.'],
                    ['key' => 'on_outside_goto', 'label' => 'Outside any window, go to', 'type' => 'step_ref', 'required' => false],
                ],
                'completion' => ['type' => 'branch'],
            ],
        ];
    }
}
