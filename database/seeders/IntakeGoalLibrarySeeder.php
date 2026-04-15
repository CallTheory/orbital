<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IntakeGoal;
use Illuminate\Database\Seeder;

/**
 * Platform-owned library of intake goals. These are the small, structured
 * building blocks that every tenant composes into call flows. The seeder is
 * opinionated — it ships what a generic answering service actually needs on
 * day one. Platform operators can add their own goals via the Filament
 * editor; this seeder just provides a sensible starting set.
 */
class IntakeGoalLibrarySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->goals() as $goal) {
            IntakeGoal::updateOrCreate(
                [
                    'team_id' => null,
                    'template_id' => null,
                    'key' => $goal['key'],
                ],
                $goal + ['is_active' => true],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function goals(): array
    {
        return [
            [
                'key' => 'identify_caller',
                'name' => 'Identify Caller',
                'category' => 'intake',
                'icon' => 'heroicon-o-user',
                'description' => 'Collect the caller\'s name and a reliable callback number.',
                'talking_points' => [
                    'Greet the caller warmly and thank them for calling.',
                    'Ask for the caller\'s full name.',
                    'Confirm the best number to reach them on if the call disconnects.',
                    'If appropriate, ask what company or relationship they\'re calling from.',
                ],
                'data_fields' => [
                    ['key' => 'caller_name', 'label' => 'Caller name', 'type' => 'string', 'required' => true, 'hint' => 'First and last name.'],
                    ['key' => 'callback_number', 'label' => 'Callback number', 'type' => 'phone', 'required' => true, 'hint' => 'Best number if disconnected.'],
                    ['key' => 'caller_company', 'label' => 'Company', 'type' => 'string', 'required' => false],
                    ['key' => 'caller_relationship', 'label' => 'Relationship', 'type' => 'string', 'required' => false, 'hint' => 'Existing customer, vendor, new inquiry, etc.'],
                ],
                'completion' => ['type' => 'all_required'],
            ],

            [
                'key' => 'identify_reason',
                'name' => 'Identify Reason for Call',
                'category' => 'intake',
                'icon' => 'heroicon-o-question-mark-circle',
                'description' => 'Capture the caller\'s reason for calling in their own words, plus a rough urgency signal.',
                'talking_points' => [
                    'Ask what prompted the call today.',
                    'Listen without interrupting; let them finish.',
                    'Gauge urgency — is this a routine question or time-sensitive?',
                ],
                'data_fields' => [
                    ['key' => 'reason', 'label' => 'Reason for call', 'type' => 'textarea', 'required' => true, 'hint' => 'Caller\'s own words.'],
                    ['key' => 'urgency', 'label' => 'Urgency', 'type' => 'select', 'required' => false, 'hint' => 'low / normal / high / emergency.'],
                ],
                'completion' => ['type' => 'all_required'],
            ],

            [
                'key' => 'take_message',
                'name' => 'Take Message',
                'category' => 'intake',
                'icon' => 'heroicon-o-envelope',
                'description' => 'Record a message for a staff member who isn\'t available right now.',
                'talking_points' => [
                    'Let the caller know you\'ll take a message and make sure it gets to the right person.',
                    'Capture the message in the caller\'s own words.',
                    'Confirm whether a callback is expected and when is a good time.',
                    'Repeat the callback number back to verify.',
                ],
                'data_fields' => [
                    ['key' => 'recipient', 'label' => 'Message for', 'type' => 'string', 'required' => false, 'hint' => 'Staff member or department.'],
                    ['key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
                    ['key' => 'callback_expected', 'label' => 'Callback expected?', 'type' => 'boolean', 'required' => true],
                    ['key' => 'callback_window', 'label' => 'Best time to call back', 'type' => 'string', 'required' => false],
                ],
                'completion' => ['type' => 'all_required'],
            ],

            [
                'key' => 'schedule_callback',
                'name' => 'Schedule a Callback',
                'category' => 'scheduling',
                'icon' => 'heroicon-o-calendar-days',
                'description' => 'Propose callback windows and confirm a slot the caller is comfortable with.',
                'talking_points' => [
                    'Offer two or three callback windows to choose from.',
                    'Confirm the selected window back to the caller.',
                    'Note any access restrictions (e.g. "do not call before 10am").',
                ],
                'data_fields' => [
                    ['key' => 'preferred_window', 'label' => 'Preferred callback window', 'type' => 'string', 'required' => true],
                    ['key' => 'alternate_window', 'label' => 'Alternate window', 'type' => 'string', 'required' => false],
                    ['key' => 'access_notes', 'label' => 'Access / timing notes', 'type' => 'textarea', 'required' => false],
                ],
                'completion' => ['type' => 'all_required'],
            ],

            [
                'key' => 'transfer_call',
                'name' => 'Transfer Call',
                'category' => 'routing',
                'icon' => 'heroicon-o-arrow-right-circle',
                'description' => 'Route the caller to a specific extension, number, or department.',
                'talking_points' => [
                    'Confirm the destination with the caller before transferring.',
                    'Let them know they may need to re-introduce themselves on the other end.',
                    'For warm transfers, stay on the line until the destination answers.',
                ],
                'data_fields' => [
                    ['key' => 'destination', 'label' => 'Destination', 'type' => 'string', 'required' => true, 'hint' => 'Extension, number, or department name.'],
                    ['key' => 'transfer_mode', 'label' => 'Transfer mode', 'type' => 'select', 'required' => true, 'hint' => 'cold or warm'],
                    ['key' => 'reason', 'label' => 'Transfer reason', 'type' => 'string', 'required' => false],
                ],
                'completion' => ['type' => 'decision', 'decision_field' => 'transfer_confirmed'],
                'tools' => [
                    ['type' => 'transfer_call', 'config' => []],
                ],
            ],

            [
                'key' => 'answer_from_faq',
                'name' => 'Answer from FAQ',
                'category' => 'knowledge',
                'icon' => 'heroicon-o-book-open',
                'description' => 'Look up information in the tenant\'s knowledge store and answer the caller\'s question from it.',
                'talking_points' => [
                    'Ask the caller to phrase their question clearly.',
                    'Search the knowledge store and answer only from what you find.',
                    'If nothing matches, offer to take a message for a human follow-up.',
                ],
                'data_fields' => [
                    ['key' => 'question', 'label' => 'Question asked', 'type' => 'textarea', 'required' => true],
                    ['key' => 'answered', 'label' => 'Question answered?', 'type' => 'boolean', 'required' => true],
                ],
                'completion' => ['type' => 'manual'],
            ],

            [
                'key' => 'verify_existing_customer',
                'name' => 'Verify Existing Customer',
                'category' => 'intake',
                'icon' => 'heroicon-o-identification',
                'description' => 'Check whether the caller is an existing customer using a lookup key.',
                'talking_points' => [
                    'Ask for the account number or the phone number on file.',
                    'Verify one additional piece of information to confirm identity.',
                    'If verification fails, fall back to taking a message for staff.',
                ],
                'data_fields' => [
                    ['key' => 'lookup_value', 'label' => 'Lookup value', 'type' => 'string', 'required' => true, 'hint' => 'Account #, phone, or email.'],
                    ['key' => 'verified', 'label' => 'Identity verified?', 'type' => 'boolean', 'required' => true],
                ],
                'completion' => ['type' => 'all_required'],
                'tools' => [
                    ['type' => 'lookup_account', 'config' => []],
                ],
            ],

            [
                'key' => 'handle_objection',
                'name' => 'Handle Objection',
                'category' => 'escalation',
                'icon' => 'heroicon-o-shield-exclamation',
                'description' => 'Calmly de-escalate a frustrated caller and offer a path forward.',
                'talking_points' => [
                    'Acknowledge the frustration without agreeing or disagreeing.',
                    'Thank them for telling you.',
                    'Offer to take a message for a supervisor or manager.',
                    'If they become abusive, warn once and then politely end the call.',
                ],
                'data_fields' => [
                    ['key' => 'concern_summary', 'label' => 'Concern summary', 'type' => 'textarea', 'required' => true],
                    ['key' => 'resolution_offered', 'label' => 'Resolution offered', 'type' => 'string', 'required' => false],
                    ['key' => 'escalate_to_supervisor', 'label' => 'Escalate to supervisor?', 'type' => 'boolean', 'required' => false],
                ],
                'completion' => ['type' => 'manual'],
            ],
        ];
    }
}
