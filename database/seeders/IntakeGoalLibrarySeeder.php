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
            // Default named exits per primitive. If the row already
            // declares `exits`, use what's there; otherwise pick a
            // sensible default shape based on category + key.
            $primitive['exits'] = array_key_exists('exits', $primitive)
                ? $primitive['exits']
                : $this->defaultExits($primitive['key'] ?? '', $primitive['category'] ?? null);

            // max_transitions is derived from exits when exits is
            // defined — count of named exits = hard cap on
            // transitions. Unbounded (null) stays unbounded.
            if (array_key_exists('max_transitions', $primitive)) {
                // Explicit override wins.
            } elseif (is_array($primitive['exits'])) {
                $primitive['max_transitions'] = count($primitive['exits']);
            } else {
                $primitive['max_transitions'] = null;
            }

            IntakeGoal::updateOrCreate(
                ['key' => $primitive['key']],
                $primitive + ['is_active' => true],
            );
        }
    }

    /**
     * Default named exits for a primitive. Overridden per-key where
     * the category's default doesn't capture the right semantics.
     *
     * Returns:
     *   array<string>   fixed exit set (order matters — matches the
     *                   on-canvas port order)
     *   null            unbounded (author supplies transitions
     *                   dynamically; e.g. match_did, gather_choice)
     *
     * @return array<int, string>|null
     */
    private function defaultExits(string $key, ?string $category): ?array
    {
        // Per-key overrides first.
        $byKey = [
            'verify_caller' => ['matched', 'not_matched'],
            'gather_boolean' => ['yes', 'no'],
            'gather_choice' => null,   // one transition per configured option
            'branch_if' => ['true', 'false'],
            'look_up_contact' => ['found', 'not_found'],
            // Channel triggers: matched routing rule vs unmatched fallback.
            'trigger_inbound_phone' => ['matched', 'fallback'],
            'trigger_inbound_email' => ['matched', 'fallback'],
            'trigger_inbound_message' => ['matched', 'fallback'],
            'trigger_inbound_chat' => ['matched', 'fallback'],
            'trigger_outbound_phone' => ['matched', 'fallback'],
            // Matches without a fixed outcome set stay unbounded —
            // each DID / local-part rule is its own transition.
            'match_did' => null,
            'match_email_local' => null,
            'match_inbox_of' => null,
        ];
        if (array_key_exists($key, $byKey)) {
            return $byKey[$key];
        }

        // Category defaults.
        return match ($category) {
            'queue', 'assign' => [],                                       // terminal
            'action' => ['continue'],                             // single forward
            'intake' => ['continue', 'rejected', 'failure'],      // gather primitives
            'match', 'trigger', 'control' => null,                            // branchy / unbounded
            default => ['continue'],
        };
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
                'key' => 'gather_text',
                'name' => 'Ask: Text',
                'category' => 'intake',
                'icon' => 'heroicon-o-clipboard-document',
                'description' => 'Collect a free-text answer from the caller. For typed values (phone, email, number, date, choice, etc.) use the purpose-built Ask: X primitive instead — it gives the LLM a tighter hint about the expected shape.',
                'talking_points' => [
                    'Ask the caller the configured question.',
                    'If they hesitate, offer the fallback hint verbatim.',
                    'Read the answer back to confirm before moving on.',
                ],
                'data_fields' => [
                    ['key' => 'slot',     'label' => 'Store as (slot name)', 'type' => 'string',   'required' => true, 'hint' => 'e.g. caller_name'],
                    ['key' => 'label',    'label' => 'Display label',        'type' => 'string',   'required' => true],
                    ['key' => 'prompt',   'label' => 'How the AI asks',      'type' => 'template', 'required' => false],
                    ['key' => 'required', 'label' => 'Required?',            'type' => 'boolean',  'required' => false],
                    ['key' => 'hint',     'label' => 'Fallback hint',        'type' => 'string',   'required' => false, 'hint' => 'Spoken if the caller hesitates.'],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            // ─── Phase 3 typed gather primitives ─────────────────────
            // Each primitive locks the slot's type so the LLM prompt
            // advertises the expected shape and the runtime can later
            // validate the value before persisting. The common data
            // fields are slot / label / prompt / required; the rest
            // are per-type validators.

            [
                'key' => 'gather_phone',
                'name' => 'Ask: Phone',
                'category' => 'intake',
                'icon' => 'heroicon-o-phone',
                'description' => 'Collect a phone number from the caller. Stored in E.164 format.',
                'talking_points' => [
                    'Ask for a phone number — callback, alternate contact, etc.',
                    'Read each digit group back to confirm before moving on.',
                    'If only a 10-digit number is given, assume the caller\'s country code.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true, 'hint' => 'e.g. callback_phone'],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'gather_email',
                'name' => 'Ask: Email',
                'category' => 'intake',
                'icon' => 'heroicon-o-envelope',
                'description' => 'Collect an email address from the caller.',
                'talking_points' => [
                    'Ask for an email address.',
                    'Spell back any uncommon parts (e.g. "jsmith at gmail dot com").',
                    'Reject addresses without `@` and a TLD.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true, 'hint' => 'e.g. contact_email'],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'gather_number',
                'name' => 'Ask: Number',
                'category' => 'intake',
                'icon' => 'heroicon-o-hashtag',
                'description' => 'Collect a numeric value (integer or decimal).',
                'talking_points' => [
                    'Ask for a number only — reject words, letters, or mixed text.',
                    'Apply min / max limits if configured; re-ask on out-of-range input.',
                    'Round to the configured precision before storing.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'min', 'label' => 'Minimum', 'type' => 'number', 'required' => false],
                    ['key' => 'max', 'label' => 'Maximum', 'type' => 'number', 'required' => false],
                    ['key' => 'decimals', 'label' => 'Decimal places', 'type' => 'number', 'required' => false, 'hint' => 'Leave blank for integers.'],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'gather_date',
                'name' => 'Ask: Date',
                'category' => 'intake',
                'icon' => 'heroicon-o-calendar',
                'description' => 'Collect a calendar date (no time component).',
                'talking_points' => [
                    'Ask for a date in natural language ("next Tuesday", "March 3rd").',
                    'Confirm by restating in month-day-year form.',
                    'Reject dates outside the configured range.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'min_date', 'label' => 'Earliest allowed', 'type' => 'string', 'required' => false, 'hint' => 'ISO date (YYYY-MM-DD) or relative ("today", "tomorrow").'],
                    ['key' => 'max_date', 'label' => 'Latest allowed', 'type' => 'string', 'required' => false],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'gather_datetime',
                'name' => 'Ask: Date & Time',
                'category' => 'intake',
                'icon' => 'heroicon-o-clock',
                'description' => 'Collect a calendar date plus a time-of-day.',
                'talking_points' => [
                    'Ask for both the date and the time in one question or two, whichever flows naturally.',
                    'Confirm by restating the full date-time, including AM/PM.',
                    'If no timezone is implied, use the caller\'s local timezone.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'timezone', 'label' => 'Timezone', 'type' => 'string', 'required' => false, 'hint' => 'e.g. America/New_York — defaults to caller\'s timezone.'],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'gather_duration',
                'name' => 'Ask: Duration',
                'category' => 'intake',
                'icon' => 'heroicon-o-bolt',
                'description' => 'Collect a length of time (hours, minutes, seconds).',
                'talking_points' => [
                    'Ask for a length of time.',
                    'Accept natural phrasing ("about twenty minutes", "three and a half hours").',
                    'Normalise to the configured unit before storing.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'unit', 'label' => 'Store as', 'type' => 'select', 'required' => false, 'options' => ['seconds', 'minutes', 'hours'], 'hint' => 'Defaults to seconds.'],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'gather_masked',
                'name' => 'Ask: Masked',
                'category' => 'intake',
                'icon' => 'heroicon-o-key',
                'description' => 'Collect a value matching a fixed digit / letter mask (SSN, account number, etc.).',
                'talking_points' => [
                    'Ask for the value described by the mask pattern.',
                    'Reject input that doesn\'t match the mask; re-ask once, then escalate.',
                    'Never speak the full value back — confirm with the last N digits only.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'pattern', 'label' => 'Mask pattern', 'type' => 'string', 'required' => true, 'hint' => 'Use 9 for digit, A for letter, * for either. e.g. 999-99-9999'],
                    ['key' => 'confirm_last', 'label' => 'Confirm last N chars only', 'type' => 'number', 'required' => false, 'hint' => 'Privacy: read only the last N back (leave blank to read none).'],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'gather_choice',
                'name' => 'Ask: Choice',
                'category' => 'intake',
                'max_transitions' => null, // one transition per option + fallback
                'icon' => 'heroicon-o-list-bullet',
                'description' => 'Ask the caller to pick from a fixed list of options.',
                'talking_points' => [
                    'List the options aloud.',
                    'Accept any paraphrase that matches an option (case-insensitive).',
                    'Re-ask if the caller picks something outside the list.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'options', 'label' => 'Allowed choices', 'type' => 'string_list', 'required' => true, 'hint' => 'One choice per line.'],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'gather_boolean',
                'name' => 'Ask: Yes / No',
                'category' => 'intake',
                'max_transitions' => 2, // yes / no branches
                'icon' => 'heroicon-o-check-circle',
                'description' => 'Collect a yes/no answer from the caller.',
                'talking_points' => [
                    'Ask a yes/no question.',
                    'Accept paraphrases ("yeah", "nope", "correct", "that\'s right").',
                    'Re-ask once if the answer is ambiguous.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'gather_address',
                'name' => 'Ask: Address',
                'category' => 'intake',
                'icon' => 'heroicon-o-map-pin',
                'description' => 'Collect a structured mailing address (street, city, state, postal code, country).',
                'talking_points' => [
                    'Ask for the address one line at a time if the caller prefers.',
                    'Confirm by restating in a natural delivery format.',
                    'Default country to the client\'s country if not stated.',
                ],
                'data_fields' => [
                    ['key' => 'slot', 'label' => 'Store as (slot name)', 'type' => 'string', 'required' => true],
                    ['key' => 'label', 'label' => 'Display label', 'type' => 'string', 'required' => true],
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false],
                    ['key' => 'require_postal', 'label' => 'Require postal code?', 'type' => 'boolean', 'required' => false],
                    ['key' => 'default_country', 'label' => 'Default country', 'type' => 'string', 'required' => false, 'hint' => 'e.g. US — used if the caller doesn\'t specify one.'],
                    ['key' => 'required', 'label' => 'Required?', 'type' => 'boolean', 'required' => false],
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
                    ['key' => 'prompt', 'label' => 'How the AI asks', 'type' => 'template', 'required' => false, 'hint' => 'Plain text with optional {{ slot_name }} placeholders.'],
                ],
                'completion' => ['type' => 'slot_filled', 'slot' => '{slot}'],
            ],

            [
                'key' => 'verify_caller',
                'name' => 'Verify Caller Identity',
                'category' => 'intake',
                'max_transitions' => 2, // matched / not matched
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
                    ['key' => 'include_slots', 'label' => 'Fields to include', 'type' => 'slot_list', 'required' => true, 'hint' => 'Names of earlier gather_* slots to persist.'],
                    ['key' => 'destination', 'label' => 'Destination', 'type' => 'select', 'required' => false, 'options' => ['inbox', 'email_queue'], 'hint' => 'Default: inbox.'],
                ],
                'completion' => ['type' => 'node_completed'],
            ],

            // ─── Phase 6 messaging primitives ────────────────────────
            // Each send_* uses expression/template fields so authors
            // can embed slot values ({{ caller_name }}, etc.) into
            // recipient / subject / body. Delivery and reply-handling
            // knobs are uniform across the family — the runtime will
            // pick the appropriate transport based on the primitive
            // key.

            [
                'key' => 'send_email',
                'name' => 'Send Email',
                'category' => 'action',
                'icon' => 'heroicon-o-envelope-open',
                'description' => 'Compose and send an email. Supports templated recipient, subject, and body.',
                'talking_points' => [
                    'Resolve the recipient / subject / body templates against the current slot values.',
                    'Dispatch through the configured from-account (or the client default).',
                    'If wait_for_reply is on, pause and watch the inbox for a matching response until reply_timeout seconds elapse.',
                ],
                'data_fields' => [
                    ['key' => 'recipient',        'label' => 'Recipient',      'type' => 'template', 'required' => true,  'hint' => 'e.g. dispatch@acme.com or {{ contact_email }}'],
                    ['key' => 'subject',          'label' => 'Subject',        'type' => 'template', 'required' => true],
                    ['key' => 'body',             'label' => 'Body',           'type' => 'template', 'required' => true],
                    ['key' => 'from_account',     'label' => 'From account',   'type' => 'string',   'required' => false, 'hint' => 'Name of a configured email account. Leave blank for the client default.'],
                    ['key' => 'require_ack',      'label' => 'Require ACK?',   'type' => 'boolean',  'required' => false],
                    ['key' => 'wait_for_reply',   'label' => 'Wait for reply?', 'type' => 'boolean', 'required' => false],
                    ['key' => 'reply_timeout',    'label' => 'Reply timeout (seconds)', 'type' => 'number', 'required' => false],
                    ['key' => 'reply_text_match', 'label' => 'Reply text match', 'type' => 'template', 'required' => false, 'hint' => 'Text or pattern in the reply body that triggers reply_action.'],
                    ['key' => 'reply_action',     'label' => 'Reply action',   'type' => 'select',   'required' => false, 'options' => ['one_call', 'resend', 'forward', 'voicemail', 'log_only']],
                    ['key' => 'send_in_test_drive', 'label' => 'Fire in test mode?', 'type' => 'boolean', 'required' => false, 'hint' => 'Off by default — test calls skip this action.'],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'send_sms',
                'name' => 'Send SMS',
                'category' => 'action',
                'icon' => 'heroicon-o-device-phone-mobile',
                'description' => 'Send a text message. Supports templated recipient and body.',
                'talking_points' => [
                    'Normalise the recipient to E.164 before dispatching.',
                    'Keep the body under the carrier\'s single-segment limit when possible.',
                ],
                'data_fields' => [
                    ['key' => 'recipient',        'label' => 'Recipient',      'type' => 'template', 'required' => true, 'hint' => 'Phone number (E.164) or {{ callback_phone }}'],
                    ['key' => 'body',             'label' => 'Body',           'type' => 'template', 'required' => true],
                    ['key' => 'from_endpoint',    'label' => 'SMS gateway',    'type' => 'string',   'required' => false, 'hint' => 'Name of a configured messaging endpoint. Leave blank for the client default.'],
                    ['key' => 'wait_for_reply',   'label' => 'Wait for reply?', 'type' => 'boolean', 'required' => false],
                    ['key' => 'reply_timeout',    'label' => 'Reply timeout (seconds)', 'type' => 'number', 'required' => false],
                    ['key' => 'reply_text_match', 'label' => 'Reply text match', 'type' => 'template', 'required' => false],
                    ['key' => 'reply_action',     'label' => 'Reply action',   'type' => 'select',   'required' => false, 'options' => ['one_call', 'resend', 'forward', 'voicemail', 'log_only']],
                    ['key' => 'send_in_test_drive', 'label' => 'Fire in test mode?', 'type' => 'boolean', 'required' => false],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'send_page',
                'name' => 'Send Page',
                'category' => 'action',
                'icon' => 'heroicon-o-bell-alert',
                'description' => 'Send a page to a pager service (TAP / SNPP / vendor API).',
                'talking_points' => [
                    'Include the urgency prefix if priority > normal.',
                    'Keep message under the pager\'s character cap.',
                ],
                'data_fields' => [
                    ['key' => 'recipient',     'label' => 'Pager number',   'type' => 'template', 'required' => true],
                    ['key' => 'body',          'label' => 'Body',           'type' => 'template', 'required' => true],
                    ['key' => 'priority',      'label' => 'Priority',       'type' => 'select',   'required' => false, 'options' => ['low', 'normal', 'high', 'urgent']],
                    ['key' => 'from_endpoint', 'label' => 'Pager endpoint', 'type' => 'string',   'required' => false],
                    ['key' => 'wait_for_reply', 'label' => 'Wait for reply?', 'type' => 'boolean', 'required' => false],
                    ['key' => 'reply_timeout', 'label' => 'Reply timeout (seconds)', 'type' => 'number', 'required' => false],
                    ['key' => 'send_in_test_drive', 'label' => 'Fire in test mode?', 'type' => 'boolean', 'required' => false],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'send_fax',
                'name' => 'Send Fax',
                'category' => 'action',
                'icon' => 'heroicon-o-printer',
                'description' => 'Send a fax through a configured fax endpoint.',
                'talking_points' => [
                    'Use the client\'s default cover page unless the step params override it.',
                ],
                'data_fields' => [
                    ['key' => 'recipient',     'label' => 'Fax number',    'type' => 'template', 'required' => true],
                    ['key' => 'subject',       'label' => 'Subject',       'type' => 'template', 'required' => true],
                    ['key' => 'body',          'label' => 'Body',          'type' => 'template', 'required' => true],
                    ['key' => 'cover_page',    'label' => 'Cover page',    'type' => 'select',   'required' => false, 'options' => ['default', 'none', 'custom']],
                    ['key' => 'from_endpoint', 'label' => 'Fax endpoint',  'type' => 'string',   'required' => false],
                    ['key' => 'require_ack',   'label' => 'Require ACK?',  'type' => 'boolean',  'required' => false],
                    ['key' => 'send_in_test_drive', 'label' => 'Fire in test mode?', 'type' => 'boolean', 'required' => false],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'send_wctp',
                'name' => 'Send WCTP',
                'category' => 'action',
                'icon' => 'heroicon-o-signal',
                'description' => 'Dispatch a WCTP (Wireless Communications Transfer Protocol) message — typically to a two-way pager.',
                'talking_points' => [
                    'Submit to the configured WCTP server over HTTPS.',
                    'Watch for the signed acknowledgement if require_ack is set.',
                ],
                'data_fields' => [
                    ['key' => 'recipient',     'label' => 'WCTP address',  'type' => 'template', 'required' => true, 'hint' => 'Device address (usually a phone-number-like token).'],
                    ['key' => 'body',          'label' => 'Body',          'type' => 'template', 'required' => true],
                    ['key' => 'priority',      'label' => 'Priority',      'type' => 'select',   'required' => false, 'options' => ['low', 'normal', 'high']],
                    ['key' => 'from_endpoint', 'label' => 'WCTP endpoint', 'type' => 'string',   'required' => false],
                    ['key' => 'require_ack',   'label' => 'Require ACK?',  'type' => 'boolean',  'required' => false],
                    ['key' => 'wait_for_reply', 'label' => 'Wait for reply?', 'type' => 'boolean', 'required' => false],
                    ['key' => 'reply_timeout', 'label' => 'Reply timeout (seconds)', 'type' => 'number', 'required' => false],
                    ['key' => 'send_in_test_drive', 'label' => 'Fire in test mode?', 'type' => 'boolean', 'required' => false],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'mark_message_sent',
                'name' => 'Mark Message Sent',
                'category' => 'action',
                'icon' => 'heroicon-o-paper-airplane',
                'description' => 'Record that an outbound message was successfully sent — updates the message state for reporting and for client-portal visibility.',
                'talking_points' => [
                    'Match the message by its slot / reference; skip silently if already marked.',
                ],
                'data_fields' => [
                    ['key' => 'message_ref', 'label' => 'Message reference', 'type' => 'template', 'required' => true, 'hint' => 'Slot name or template that resolves to the message id.'],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'mark_message_delivered',
                'name' => 'Mark Message Delivered',
                'category' => 'action',
                'icon' => 'heroicon-o-check-badge',
                'description' => 'Record that an outbound message was confirmed delivered (read-receipt / delivery-report style).',
                'talking_points' => [
                    'Requires a prior send_* step to have stored a reference to match on.',
                ],
                'data_fields' => [
                    ['key' => 'message_ref', 'label' => 'Message reference', 'type' => 'template', 'required' => true],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            // ─── Phase 7 database + web integration ──────────────────
            // Authors reference a named DB connection (configured in
            // the client's Data Dictionary → DB Connections page) and
            // supply either a table + filter or a raw query. Results
            // land in slots per the `output_mapping` JSON.

            [
                'key' => 'db_lookup_single',
                'name' => 'DB: Lookup (Single Row)',
                'category' => 'action',
                'icon' => 'heroicon-o-circle-stack',
                'description' => 'Query a configured database for a single matching row and write its columns to slots.',
                'talking_points' => [
                    'Resolve the lookup params against the current slot values.',
                    'If zero rows match, take the not_found exit; if multiple, use the first per the ORDER BY.',
                ],
                'data_fields' => [
                    ['key' => 'connection_name', 'label' => 'Connection name', 'type' => 'string', 'required' => true, 'hint' => 'Name of a configured DB connection.'],
                    ['key' => 'command_type',    'label' => 'Command type',    'type' => 'select', 'required' => true, 'options' => ['table', 'stored_proc', 'raw_sql']],
                    ['key' => 'table_or_query',  'label' => 'Table / proc / SQL', 'type' => 'template', 'required' => true, 'hint' => 'Table name, procedure name, or full SQL (templates allowed).'],
                    ['key' => 'lookup_params',   'label' => 'Lookup params (JSON: slot → column)', 'type' => 'textarea', 'required' => false, 'hint' => 'e.g. {"caller_phone": "phone"}'],
                    ['key' => 'output_mapping',  'label' => 'Output mapping (JSON: column → slot)', 'type' => 'textarea', 'required' => true, 'hint' => 'e.g. {"customer_id": "matched_customer_id", "name": "customer_name"}'],
                ],
                'exits' => ['found', 'not_found', 'error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'db_picklist',
                'name' => 'DB: Picklist',
                'category' => 'action',
                'icon' => 'heroicon-o-rectangle-stack',
                'description' => 'Query the DB for multiple rows and let the caller (via the AI) pick one. Writes the chosen row\'s columns to slots.',
                'talking_points' => [
                    'Offer the rows verbally (or present them in the operator UI).',
                    'Accept a paraphrase that uniquely identifies one row; reject ambiguous answers.',
                ],
                'data_fields' => [
                    ['key' => 'connection_name', 'label' => 'Connection name', 'type' => 'string', 'required' => true],
                    ['key' => 'command_type',    'label' => 'Command type',    'type' => 'select', 'required' => true, 'options' => ['table', 'stored_proc', 'raw_sql']],
                    ['key' => 'table_or_query',  'label' => 'Table / proc / SQL', 'type' => 'template', 'required' => true],
                    ['key' => 'lookup_params',   'label' => 'Lookup params (JSON)', 'type' => 'textarea', 'required' => false],
                    ['key' => 'display_columns', 'label' => 'Display columns (comma-separated)', 'type' => 'string', 'required' => true, 'hint' => 'Which columns the caller sees / hears when choosing.'],
                    ['key' => 'output_mapping',  'label' => 'Output mapping (JSON)', 'type' => 'textarea', 'required' => true],
                    ['key' => 'max_rows',        'label' => 'Maximum rows to offer', 'type' => 'number', 'required' => false, 'hint' => 'Default 10.'],
                ],
                'exits' => ['picked', 'none_chosen', 'error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'db_iterate',
                'name' => 'DB: Iterate Rows',
                'category' => 'action',
                'icon' => 'heroicon-o-arrow-path-rounded-square',
                'description' => 'Run an action group once per result row. Caps at max_iterations to prevent runaways.',
                'talking_points' => [
                    'For each row, bind row columns to slots per the mapping, then invoke the body action group.',
                ],
                'data_fields' => [
                    ['key' => 'connection_name', 'label' => 'Connection name', 'type' => 'string', 'required' => true],
                    ['key' => 'command_type',    'label' => 'Command type',    'type' => 'select', 'required' => true, 'options' => ['table', 'stored_proc', 'raw_sql']],
                    ['key' => 'table_or_query',  'label' => 'Table / proc / SQL', 'type' => 'template', 'required' => true],
                    ['key' => 'lookup_params',   'label' => 'Lookup params (JSON)', 'type' => 'textarea', 'required' => false],
                    ['key' => 'row_slot_mapping', 'label' => 'Row → slot mapping (JSON)', 'type' => 'textarea', 'required' => true],
                    ['key' => 'body_action_group_id', 'label' => 'Body action group', 'type' => 'action_group_picker', 'required' => true],
                    ['key' => 'max_iterations',  'label' => 'Max iterations',  'type' => 'number', 'required' => false, 'hint' => 'Default 50.'],
                ],
                'exits' => ['complete', 'error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'db_save',
                'name' => 'DB: Save (Insert/Update)',
                'category' => 'action',
                'icon' => 'heroicon-o-archive-box-arrow-down',
                'description' => 'Insert or update a row in a configured database using slot values for column data.',
                'talking_points' => [
                    'When a matching row exists per the key columns, update; else insert.',
                    'Return the saved row\'s primary key to the configured slot.',
                ],
                'data_fields' => [
                    ['key' => 'connection_name', 'label' => 'Connection name', 'type' => 'string', 'required' => true],
                    ['key' => 'table',           'label' => 'Table',           'type' => 'string', 'required' => true],
                    ['key' => 'key_columns',     'label' => 'Key columns (comma-separated)', 'type' => 'string', 'required' => false, 'hint' => 'Leave blank for insert-only.'],
                    ['key' => 'column_mapping',  'label' => 'Column mapping (JSON: column → slot)', 'type' => 'textarea', 'required' => true],
                    ['key' => 'pk_slot',         'label' => 'Slot for saved row\'s PK', 'type' => 'slot_ref', 'required' => false],
                ],
                'exits' => ['saved', 'error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'web_call',
                'name' => 'Web: REST / HTTP Call',
                'category' => 'action',
                'icon' => 'heroicon-o-globe-alt',
                'description' => 'Make an HTTP call to a configured web endpoint. Supports templated URL, headers, and body; maps response fields to slots.',
                'talking_points' => [
                    'Resolve the URL + headers + body templates against the current slots.',
                    'Merge default headers from the endpoint with any step-level headers (step-level wins).',
                ],
                'data_fields' => [
                    ['key' => 'endpoint_name', 'label' => 'Endpoint name', 'type' => 'string',   'required' => true],
                    ['key' => 'method',        'label' => 'Method',        'type' => 'select',   'required' => true, 'options' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                    ['key' => 'path',          'label' => 'Path / URL',    'type' => 'template', 'required' => true, 'hint' => 'Appended to the endpoint\'s base_url, or a full URL. Supports {{ slot }} templates.'],
                    ['key' => 'headers',       'label' => 'Extra headers (JSON)', 'type' => 'textarea', 'required' => false],
                    ['key' => 'query_params',  'label' => 'Query params (JSON)',  'type' => 'textarea', 'required' => false],
                    ['key' => 'body_template', 'label' => 'Request body',  'type' => 'template', 'required' => false],
                    ['key' => 'response_mapping', 'label' => 'Response mapping (JSON: JSONPath → slot)', 'type' => 'textarea', 'required' => false, 'hint' => 'e.g. {"$.customer.id": "matched_customer_id"}'],
                    ['key' => 'expected_status_codes', 'label' => 'Expected status codes (comma)', 'type' => 'string', 'required' => false, 'hint' => 'Default 2xx — e.g. "200,201,204".'],
                ],
                'exits' => ['ok', 'http_error', 'network_error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'parse_json',
                'name' => 'Parse JSON',
                'category' => 'action',
                'icon' => 'heroicon-o-code-bracket',
                'description' => 'Parse a slot\'s JSON string and extract fields via JSONPath into other slots.',
                'talking_points' => [
                    'Each JSONPath expression resolves against the parsed object; missing paths leave the target slot null.',
                ],
                'data_fields' => [
                    ['key' => 'source_slot', 'label' => 'Source slot', 'type' => 'slot_ref', 'required' => true],
                    ['key' => 'mapping',     'label' => 'JSONPath → slot (JSON)', 'type' => 'textarea', 'required' => true, 'hint' => 'e.g. {"$.user.id": "user_id", "$.items[0].name": "first_item_name"}'],
                ],
                'exits' => ['ok', 'error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'parse_xml',
                'name' => 'Parse XML',
                'category' => 'action',
                'icon' => 'heroicon-o-document-text',
                'description' => 'Parse a slot\'s XML string and extract fields via XPath into other slots.',
                'talking_points' => [
                    'XPath expressions resolve against the root element. Namespaces come from options.',
                ],
                'data_fields' => [
                    ['key' => 'source_slot', 'label' => 'Source slot', 'type' => 'slot_ref', 'required' => true],
                    ['key' => 'mapping',     'label' => 'XPath → slot (JSON)', 'type' => 'textarea', 'required' => true, 'hint' => 'e.g. {"/Envelope/Body/Result/@id": "result_id"}'],
                    ['key' => 'namespaces',  'label' => 'Namespaces (JSON: prefix → uri)', 'type' => 'textarea', 'required' => false],
                ],
                'exits' => ['ok', 'error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'answer_question',
                'name' => 'Answer Question',
                'category' => 'action',
                'icon' => 'heroicon-o-chat-bubble-bottom-center-text',
                'description' => 'Answer the caller\'s question using the attached knowledge stores. Falls back cleanly when nothing matches.',
                'talking_points' => [
                    'Search the attached knowledge stores before replying.',
                    'If an answer is found, summarize it in two sentences and take the `answered` exit.',
                    'If nothing matches, take the `no_answer` exit — the downstream flow handles what to do next (offer to take a message, transfer, etc.).',
                ],
                'data_fields' => [
                    ['key' => 'knowledge_store_ids', 'label' => 'Knowledge stores', 'type' => 'knowledge_store_list', 'required' => false, 'hint' => 'Which stores to search. None = search all stores attached to this client.'],
                    ['key' => 'no_answer_message', 'label' => 'What the AI says when no answer is found', 'type' => 'template', 'required' => false, 'hint' => 'Default: "I don\'t have that information handy." Runs just before the no_answer exit.'],
                ],
                'exits' => ['answered', 'no_answer'],
                'completion' => ['type' => 'node_completed'],
            ],

            [
                'key' => 'transfer_call',
                'name' => 'Transfer Call',
                'category' => 'action',
                'exits' => [], // terminal — call leaves this flow on transfer
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

            // ─── Phase 8 dispatch + telephony control ────────────────

            [
                'key' => 'enter_dispatcher_queue',
                'name' => 'Enter Dispatcher Queue',
                'category' => 'queue',
                'icon' => 'heroicon-o-queue-list',
                'description' => 'Post this call to a human-operator dispatcher queue — different from a call queue (ring-group hunt). An operator picks the call up and resumes handling.',
                'talking_points' => [
                    'Tell the caller a real person will be right with them.',
                    'Hand the call off to the dispatcher queue.',
                ],
                'data_fields' => [
                    ['key' => 'queue_name',    'label' => 'Dispatcher queue', 'type' => 'string',   'required' => true, 'hint' => 'Name of a configured dispatcher queue.'],
                    ['key' => 'priority',      'label' => 'Priority',         'type' => 'select',   'required' => false, 'options' => ['low', 'normal', 'high', 'urgent']],
                    ['key' => 'wait_music',    'label' => 'Hold music',       'type' => 'string',   'required' => false],
                    ['key' => 'max_wait_seconds', 'label' => 'Max wait (seconds)', 'type' => 'number', 'required' => false],
                    ['key' => 'timeout_goto',  'label' => 'On timeout, go to', 'type' => 'step_ref', 'required' => false],
                ],
                'exits' => [], // terminal from the flow's perspective — operator takes over
                'completion' => ['type' => 'node_completed'],
            ],

            [
                'key' => 'auto_dispatch',
                'name' => 'Auto-Dispatch',
                'category' => 'action',
                'icon' => 'heroicon-o-arrows-right-left',
                'description' => 'Rule-based dispatch without operator intervention — fires a message to the configured on-call roster / pager / email list.',
                'talking_points' => [
                    'Resolve the dispatch rule against current slots.',
                    'Fire the configured notification action(s) and log the dispatch event.',
                ],
                'data_fields' => [
                    ['key' => 'rule_name',       'label' => 'Dispatch rule',   'type' => 'string', 'required' => true, 'hint' => 'Name of a configured auto-dispatch rule.'],
                    ['key' => 'include_slots',   'label' => 'Fields to include', 'type' => 'slot_list', 'required' => false],
                    ['key' => 'notify_action_group_id', 'label' => 'Notify action group', 'type' => 'action_group_picker', 'required' => false, 'hint' => 'Optional — run a shared chain (e.g. "On-Call Notification") instead of the rule\'s default.'],
                ],
                'exits' => ['dispatched', 'no_match', 'error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'handle_dispatch_event',
                'name' => 'Handle Dispatch Event',
                'category' => 'trigger',
                'icon' => 'heroicon-o-inbox-arrow-down',
                'description' => 'Entry point for operator-side flows invoked when a dispatcher picks up a queued dispatch. Surfaces the dispatch context (caller info, rule, original message) as slots.',
                'talking_points' => [
                    'Greet the operator by name and summarise the dispatch.',
                    'Bind the original message slots so the operator sees the same context the caller provided.',
                ],
                'data_fields' => [
                    ['key' => 'bind_slot_prefix', 'label' => 'Slot prefix for dispatch context', 'type' => 'string', 'required' => false, 'hint' => 'e.g. "dispatch_" — dispatch fields become dispatch_caller_name, dispatch_reason, etc.'],
                ],
                'exits' => ['accepted', 'rejected'],
                'completion' => ['type' => 'node_completed'],
            ],

            [
                'key' => 'park_call',
                'name' => 'Park Call',
                'category' => 'action',
                'icon' => 'heroicon-o-pause-circle',
                'description' => 'Park the live call on an orbit / hold / conference bridge / agent extension. The call can be retrieved later by the configured mechanism; on retrieve the resume_flow takes over.',
                'talking_points' => [
                    'Tell the caller they\'re being placed on hold briefly.',
                    'Transfer to the parking destination and note the orbit / slot identifier.',
                ],
                'data_fields' => [
                    ['key' => 'park_type',   'label' => 'Park type',    'type' => 'select',   'required' => true, 'options' => ['orbit', 'on_hold', 'conference_bridge', 'agent_extension']],
                    ['key' => 'target',      'label' => 'Target',       'type' => 'string',   'required' => false, 'hint' => 'Orbit number, bridge name, or agent extension (depending on park_type).'],
                    ['key' => 'retrieve_code', 'label' => 'Retrieve code', 'type' => 'string', 'required' => false],
                    ['key' => 'resume_flow_id', 'label' => 'Resume flow', 'type' => 'action_group_picker', 'required' => false, 'hint' => 'Flow that runs when the call is retrieved from park.'],
                    ['key' => 'max_park_seconds', 'label' => 'Max park (seconds)', 'type' => 'number', 'required' => false],
                ],
                'exits' => ['parked', 'timeout', 'error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'pause',
                'name' => 'Pause',
                'category' => 'control',
                'icon' => 'heroicon-o-clock',
                'description' => 'Pause flow execution for a fixed number of seconds. Useful for staged auto-reads and for giving the caller a beat to respond.',
                'talking_points' => [
                    'Hold for the configured duration without speaking.',
                ],
                'data_fields' => [
                    ['key' => 'seconds', 'label' => 'Seconds', 'type' => 'number', 'required' => true, 'hint' => 'e.g. 2'],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'toggle_hold',
                'name' => 'Toggle Hold',
                'category' => 'action',
                'icon' => 'heroicon-o-hand-raised',
                'description' => 'Place the caller on hold, take them off, or toggle.',
                'talking_points' => [
                    'Brief the caller before switching states.',
                ],
                'data_fields' => [
                    ['key' => 'state', 'label' => 'Hold state', 'type' => 'select', 'required' => true, 'options' => ['hold', 'resume', 'toggle']],
                    ['key' => 'music', 'label' => 'Hold music', 'type' => 'string', 'required' => false],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'toggle_recording',
                'name' => 'Toggle Recording',
                'category' => 'action',
                'icon' => 'heroicon-o-microphone',
                'description' => 'Start, stop, pause, or resume the call recording. Honours the client\'s consent rules.',
                'talking_points' => [
                    'If starting, confirm the caller has been told the call is recorded.',
                    'If stopping, write the final recording to the message.',
                ],
                'data_fields' => [
                    ['key' => 'state',  'label' => 'Recording state', 'type' => 'select', 'required' => true, 'options' => ['start', 'stop', 'pause', 'resume', 'toggle']],
                    ['key' => 'reason', 'label' => 'Reason',          'type' => 'template', 'required' => false, 'hint' => 'Why the state changed — logged for audit.'],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'play_hold_music',
                'name' => 'Play Hold Music',
                'category' => 'action',
                'icon' => 'heroicon-o-musical-note',
                'description' => 'Play a hold-music / pacifier track for a fixed duration (or indefinitely until the next step). Equivalent to NxtScript\'s elPacify.',
                'talking_points' => [
                    'Pick the configured track; fall back to the client default.',
                ],
                'data_fields' => [
                    ['key' => 'track',   'label' => 'Track',          'type' => 'string', 'required' => false, 'hint' => 'Leave blank for the client default.'],
                    ['key' => 'seconds', 'label' => 'Duration (seconds)', 'type' => 'number', 'required' => false, 'hint' => 'Blank = play until next step.'],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'change_account',
                'name' => 'Change Account',
                'category' => 'action',
                'icon' => 'heroicon-o-arrows-pointing-out',
                'description' => 'Switch the active account context mid-call — useful for multi-account clients where the caller\'s identification resolves to a different account than the one they originally dialed.',
                'talking_points' => [
                    'Confirm with the caller before switching accounts (most of the time).',
                    'Rebind account-scoped slots to the new account.',
                ],
                'data_fields' => [
                    ['key' => 'account_ref', 'label' => 'Target account', 'type' => 'template', 'required' => true, 'hint' => 'Account id / code (template allowed).'],
                    ['key' => 'confirm_with_caller', 'label' => 'Confirm with caller?', 'type' => 'boolean', 'required' => false],
                ],
                'exits' => ['switched', 'declined', 'error'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'transfer_to_voicemail',
                'name' => 'Transfer to Voicemail',
                'category' => 'action',
                'icon' => 'heroicon-o-mailbox',
                'description' => 'Send the caller to a voicemail inbox. Terminal — the call leaves this flow.',
                'talking_points' => [
                    'Tell the caller they\'ll be sent to voicemail.',
                    'Bridge to the configured voicemail destination.',
                ],
                'data_fields' => [
                    ['key' => 'mailbox',  'label' => 'Mailbox',      'type' => 'string',   'required' => true, 'hint' => 'Voicemail box identifier.'],
                    ['key' => 'greeting', 'label' => 'Greeting override', 'type' => 'template', 'required' => false],
                ],
                'exits' => [], // terminal
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

            // ─── Phase 9 summary / tagging / analytics ───────────────

            [
                'key' => 'save_summary',
                'name' => 'Save Summary',
                'category' => 'action',
                'icon' => 'heroicon-o-document-text',
                'description' => 'Compose an explicit call summary from included slots and persist it. Separate from save_message when you want summary text without inbox delivery.',
                'talking_points' => [
                    'Render the summary template against the collected slots.',
                    'Store the resolved text on the message record for reporting.',
                ],
                'data_fields' => [
                    ['key' => 'summary_template', 'label' => 'Summary template', 'type' => 'template', 'required' => true, 'hint' => 'Plain text with {{ slot_name }} placeholders.'],
                    ['key' => 'target_slot',      'label' => 'Also store in slot', 'type' => 'slot_ref', 'required' => false, 'hint' => 'Optional — keep the resolved summary available for later steps.'],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'save_keyword',
                'name' => 'Save Keyword',
                'category' => 'action',
                'icon' => 'heroicon-o-tag',
                'description' => 'Tag the current call with a keyword for later search and reporting.',
                'talking_points' => [
                    'Pick the keyword from the client\'s keyword dictionary.',
                    'If the keyword is missing, skip silently (don\'t hallucinate new keywords).',
                ],
                'data_fields' => [
                    ['key' => 'keyword_slug', 'label' => 'Keyword', 'type' => 'string', 'required' => true, 'hint' => 'Slug of a configured client keyword (after_hours, vip, emergency, …).'],
                    ['key' => 'condition',    'label' => 'Only tag if (optional)', 'type' => 'expression', 'required' => false, 'hint' => 'Apply the tag only when this expression is truthy.'],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'save_call_tracker_event',
                'name' => 'Save Call Tracker Event',
                'category' => 'action',
                'icon' => 'heroicon-o-chart-bar-square',
                'description' => 'Emit an analytics event with a named type and a templated description. Appears in the reporting surface for slicing by event class.',
                'talking_points' => [
                    'Events are append-only — write them on real milestones, not every step.',
                ],
                'data_fields' => [
                    ['key' => 'event_type',  'label' => 'Event type', 'type' => 'string',   'required' => true, 'hint' => 'Short snake_case event name (e.g. customer_identified, escalated_to_operator).'],
                    ['key' => 'description', 'label' => 'Description (template)', 'type' => 'template', 'required' => false],
                    ['key' => 'include_slots', 'label' => 'Include slots in context snapshot', 'type' => 'slot_list', 'required' => false, 'hint' => 'Defaults to all current slots.'],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'save_history',
                'name' => 'Save History Entry',
                'category' => 'action',
                'icon' => 'heroicon-o-book-open',
                'description' => 'Append an entry to the caller\'s / account\'s history. Useful for cross-call context — the next time the caller calls back, get_history pulls the recent entries.',
                'talking_points' => [
                    'Set the subject line so the next flow can recognise the entry at a glance.',
                    'Use body + metadata for details the reporting surface or the next LLM prompt can read back.',
                ],
                'data_fields' => [
                    ['key' => 'scope',    'label' => 'Scope',    'type' => 'select',   'required' => true, 'options' => ['caller', 'account', 'both']],
                    ['key' => 'subject',  'label' => 'Subject (template)', 'type' => 'template', 'required' => true],
                    ['key' => 'body',     'label' => 'Body (template)',    'type' => 'template', 'required' => false],
                    ['key' => 'metadata_slots', 'label' => 'Slots to snapshot into metadata', 'type' => 'slot_list', 'required' => false],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_completed'],
            ],

            [
                'key' => 'get_history',
                'name' => 'Get History',
                'category' => 'intake',
                'icon' => 'heroicon-o-clock',
                'description' => 'Retrieve recent history entries for the caller / account and bind them to slots for downstream prompts.',
                'talking_points' => [
                    'Pull up to max_entries most-recent entries for the configured scope.',
                    'Make the list available to later steps via the target slot.',
                ],
                'data_fields' => [
                    ['key' => 'scope',       'label' => 'Scope',       'type' => 'select',  'required' => true, 'options' => ['caller', 'account', 'both']],
                    ['key' => 'max_entries', 'label' => 'Max entries', 'type' => 'number',  'required' => false, 'hint' => 'Default 5.'],
                    ['key' => 'since_days',  'label' => 'Since (days)', 'type' => 'number', 'required' => false, 'hint' => 'Only pull entries from the last N days.'],
                    ['key' => 'target_slot', 'label' => 'Store as',    'type' => 'slot_ref', 'required' => true, 'hint' => 'Slot that holds the resulting list.'],
                ],
                'exits' => ['found', 'empty', 'error'],
                'completion' => ['type' => 'slot_filled', 'slot' => '{target_slot}'],
            ],

            // ─── Control primitives (visual-editor primitives; compiler
            // treats them as no-ops today, but they render as real nodes
            // so authors can lay out the intended graph before the
            // runtime catches up). ─────────────────────────────────────
            [
                'key' => 'call_action_group',
                'name' => 'Invoke Action Group',
                'category' => 'action',
                'icon' => 'heroicon-o-puzzle-piece',
                'description' => 'Invoke a reusable action group defined elsewhere in this client\'s graph. Useful for shared chains like "Standard Goodbye" or "Verify Identity".',
                'talking_points' => [
                    'Follow the steps inside the referenced action group, then continue.',
                    'When the group has no more steps, resume the caller flow that invoked it.',
                ],
                'data_fields' => [
                    ['key' => 'action_group_flow_id', 'label' => 'Action group', 'type' => 'action_group_picker', 'required' => true, 'hint' => 'Pick a flow marked as an action group.'],
                ],
                'exits' => ['continue'],
                'completion' => ['type' => 'action_group_completed'],
            ],

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

            // ─── Match primitives (decision nodes that route based on
            // DID / caller-identity / channel-specific data) ─────────
            [
                'key' => 'match_did',
                'name' => 'Match DID',
                'category' => 'match',
                'icon' => 'heroicon-o-phone',
                'description' => 'Branch based on which DID the caller dialed.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'did_ids', 'label' => 'DIDs to match', 'type' => 'did_picker', 'required' => false, 'hint' => 'Leave empty to match any of this client\'s DIDs.'],
                    ['key' => 'pattern', 'label' => 'DID pattern', 'type' => 'string', 'required' => false, 'hint' => 'Optional glob/regex when a specific DID isn\'t listed (e.g. "15551234*").'],
                ],
                'completion' => ['type' => 'branch'],
            ],

            [
                'key' => 'match_email_address',
                'name' => 'Match Email Address',
                'category' => 'match',
                'icon' => 'heroicon-o-at-symbol',
                'description' => 'Branch based on the inbound email\'s to-address local-part.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'local_part_pattern', 'label' => 'Local-part pattern', 'type' => 'string', 'required' => true, 'hint' => 'e.g. "support*", "billing@", or a regex.'],
                ],
                'completion' => ['type' => 'branch'],
            ],

            [
                'key' => 'match_extension',
                'name' => 'Match Extension',
                'category' => 'match',
                'icon' => 'heroicon-o-device-phone-mobile',
                'description' => 'Branch based on the internal extension the caller dialed.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'extension_ids', 'label' => 'Extensions to match', 'type' => 'extension_picker', 'required' => true],
                ],
                'completion' => ['type' => 'branch'],
            ],

            // ─── Queue primitives — park the caller/thread in a wait
            // queue that's configured separately (Call Queues admin). ─
            [
                'key' => 'enter_call_queue',
                'name' => 'Enter Call Queue',
                'category' => 'queue',
                'icon' => 'heroicon-o-queue-list',
                'description' => 'Route the live caller into a call queue to wait for an operator.',
                'talking_points' => [
                    'Tell the caller they\'re being put on hold for the next available agent.',
                    'Transfer them into the configured queue.',
                ],
                'data_fields' => [
                    ['key' => 'call_queue_id', 'label' => 'Call queue', 'type' => 'call_queue_picker', 'required' => true],
                    ['key' => 'overflow_after_seconds', 'label' => 'Overflow after (seconds)', 'type' => 'number', 'required' => false, 'hint' => 'If no agent picks up within this time, fall through to the next transition.'],
                ],
                'completion' => ['type' => 'node_completed'],
            ],

            [
                'key' => 'enter_email_queue',
                'name' => 'Enter Email Queue',
                'category' => 'queue',
                'icon' => 'heroicon-o-inbox-stack',
                'description' => 'Route the inbound email into an email queue for operator review.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'email_queue_id', 'label' => 'Email queue', 'type' => 'email_queue_picker', 'required' => true],
                ],
                'completion' => ['type' => 'node_completed'],
            ],

            // ─── Assignment primitives — hand the caller/thread over
            // to a specific endpoint (AI persona / extension). ───────
            [
                'key' => 'assign_to_persona',
                'name' => 'Assign to AI Persona',
                'category' => 'assign',
                'icon' => 'heroicon-o-user-circle',
                'description' => 'Hand the call off to a configured AI persona. The persona\'s default flow picks up from here.',
                'talking_points' => [
                    'Bridge the call to the persona\'s AI agent extension.',
                    'The persona takes over the conversation from this point.',
                ],
                'data_fields' => [
                    ['key' => 'agent_persona_id', 'label' => 'Persona', 'type' => 'agent_persona_picker', 'required' => true],
                ],
                'completion' => ['type' => 'node_completed'],
            ],

            [
                'key' => 'transfer_to_extension',
                'name' => 'Transfer to Extension',
                'category' => 'assign',
                'icon' => 'heroicon-o-arrow-right-on-rectangle',
                'description' => 'Transfer the call to a configured extension (hardware phone, WebRTC seat, or AI agent extension).',
                'talking_points' => [
                    'Tell the caller who you\'re transferring them to.',
                    'Perform the transfer.',
                ],
                'data_fields' => [
                    ['key' => 'extension_id', 'label' => 'Extension', 'type' => 'extension_picker', 'required' => true],
                    ['key' => 'mode', 'label' => 'Transfer mode', 'type' => 'select', 'required' => false, 'options' => ['cold', 'warm'], 'hint' => 'Default: cold.'],
                ],
                'completion' => ['type' => 'node_completed'],
            ],

            // ─── Trigger primitives — one per inbound/outbound channel.
            // These don't get placed as steps inside a flow; they
            // exist as library rows so the auto-seeder can create a
            // flow with this primitive as its "entry" representation.
            // `trigger_type` on the flow is what actually gates runtime
            // dispatch — the primitive here is mostly for rendering
            // and for giving the author a way to inspect/edit match
            // params. ─────────────────────────────────────────────────
            // Trigger primitives are pure visual entry anchors. The
            // actual channel matching (DIDs for phone, addresses for
            // email, etc.) lives on the queue row now, not on the
            // trigger step. Author-supplied notes survive as a
            // freeform `notes` field.
            [
                'key' => 'trigger_inbound_phone',
                'name' => 'Inbound Phone',
                'category' => 'trigger',
                'icon' => 'heroicon-o-phone-arrow-down-left',
                'description' => 'Entry point for inbound calls to this client. DID matching is configured on the Call Queue that owns those DIDs.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'template', 'required' => false],
                ],
                'completion' => ['type' => 'trigger'],
            ],

            [
                'key' => 'trigger_inbound_email',
                'name' => 'Inbound Email',
                'category' => 'trigger',
                'icon' => 'heroicon-o-envelope-open',
                'description' => 'Entry point for inbound email threads. Local-part / address matching is configured on the Email Queue that owns the relevant addresses.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'template', 'required' => false],
                ],
                'completion' => ['type' => 'trigger'],
            ],

            [
                'key' => 'trigger_inbound_message',
                'name' => 'Inbound Message',
                'category' => 'trigger',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'description' => 'Entry point for inbound text-shaped messages — SMS, MMS, RCS, SMPP, WCTP, paging. Matching (addresses + protocols) is configured on the Message Queue.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'template', 'required' => false],
                ],
                'completion' => ['type' => 'trigger'],
            ],

            [
                'key' => 'trigger_inbound_chat',
                'name' => 'Inbound Chat',
                'category' => 'trigger',
                'icon' => 'heroicon-o-chat-bubble-bottom-center-text',
                'description' => 'Entry point for interactive chat sessions — embeddable web widget, Slack, Microsoft Teams. Integration config is held on the Chat Queue.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'template', 'required' => false],
                ],
                'completion' => ['type' => 'trigger'],
            ],

            [
                'key' => 'trigger_outbound_phone',
                'name' => 'Outbound Phone',
                'category' => 'trigger',
                'icon' => 'heroicon-o-phone-arrow-up-right',
                'description' => 'Entry point for outbound call automation. Runtime wiring lands in a later phase.',
                'talking_points' => [],
                'data_fields' => [
                    ['key' => 'purpose', 'label' => 'Purpose', 'type' => 'select', 'required' => false, 'options' => ['followup', 'reminder', 'survey', 'custom']],
                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'template', 'required' => false],
                ],
                'completion' => ['type' => 'trigger'],
            ],
        ];
    }
}
