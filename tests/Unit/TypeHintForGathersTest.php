<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Flows\AgentFlowCompiler;
use App\Services\Flows\JsonLogicRenderer;
use App\Services\Flows\TemplateEvaluator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Exercises the per-primitive typeHintFor() method on AgentFlowCompiler
 * directly via reflection. The compile() path itself needs the full
 * model stack; this is a cheap unit test that locks down the prompt
 * hint each typed gather emits.
 */
class TypeHintForGathersTest extends TestCase
{
    private function compiler(): AgentFlowCompiler
    {
        return new AgentFlowCompiler(new JsonLogicRenderer, new TemplateEvaluator);
    }

    private function hint(string $key, array $params = []): string
    {
        $m = new ReflectionMethod(AgentFlowCompiler::class, 'typeHintFor');
        $m->setAccessible(true);

        return $m->invoke($this->compiler(), $key, $params);
    }

    public function test_phone_hint(): void
    {
        $this->assertStringContainsString('E.164', $this->hint('gather_phone'));
    }

    public function test_email_hint(): void
    {
        $this->assertStringContainsString('valid email', $this->hint('gather_email'));
    }

    public function test_number_hint_no_bounds(): void
    {
        $this->assertSame('collect a number.', $this->hint('gather_number'));
    }

    public function test_number_hint_with_bounds(): void
    {
        $out = $this->hint('gather_number', ['min' => 1, 'max' => 99, 'decimals' => 2]);
        $this->assertStringContainsString('min 1', $out);
        $this->assertStringContainsString('max 99', $out);
        $this->assertStringContainsString('2 decimal places', $out);
    }

    public function test_date_hint_with_range(): void
    {
        $out = $this->hint('gather_date', ['min_date' => '2026-01-01', 'max_date' => '2026-12-31']);
        $this->assertStringContainsString('on or after 2026-01-01', $out);
        $this->assertStringContainsString('on or before 2026-12-31', $out);
    }

    public function test_duration_uses_configured_unit(): void
    {
        $this->assertStringContainsString('store as minutes', $this->hint('gather_duration', ['unit' => 'minutes']));
        $this->assertStringContainsString('store as seconds', $this->hint('gather_duration'));
    }

    public function test_masked_hint_includes_pattern(): void
    {
        $out = $this->hint('gather_masked', ['pattern' => '999-99-9999']);
        $this->assertStringContainsString('999-99-9999', $out);
    }

    public function test_choice_lists_options(): void
    {
        $out = $this->hint('gather_choice', ['options' => ['yes', 'no', 'maybe']]);
        $this->assertStringContainsString('"yes"', $out);
        $this->assertStringContainsString('"maybe"', $out);
    }

    public function test_boolean_hint(): void
    {
        $this->assertStringContainsString('true or false', $this->hint('gather_boolean'));
    }

    public function test_address_hint_default_country_and_require_postal(): void
    {
        $out = $this->hint('gather_address', ['require_postal' => true, 'default_country' => 'US']);
        $this->assertStringContainsString('postal (required)', $out);
        $this->assertStringContainsString('default US', $out);
    }

    public function test_unknown_key_returns_empty(): void
    {
        $this->assertSame('', $this->hint('do_not_know'));
    }

    public function test_action_group_hint_without_id(): void
    {
        $this->assertStringContainsString(
            'not yet chosen',
            $this->hint('call_action_group', []),
        );
    }

    public function test_send_email_hint_includes_recipient_subject_body(): void
    {
        $out = $this->hint('send_email', [
            'recipient' => 'dispatch@acme.com',
            'subject' => 'Caller message',
            'body' => 'Hi — a caller just reached out.',
        ]);
        $this->assertStringContainsString('send a email to dispatch@acme.com', $out);
        $this->assertStringContainsString('subject: Caller message', $out);
        $this->assertStringContainsString('body: Hi — a caller just reached out.', $out);
    }

    public function test_send_email_wait_for_reply_flag(): void
    {
        $out = $this->hint('send_email', [
            'recipient' => 'x@y.com',
            'subject' => 's',
            'body' => 'b',
            'wait_for_reply' => true,
            'reply_timeout' => 45,
            'reply_action' => 'one_call',
        ]);
        $this->assertStringContainsString('wait for reply (45s)', $out);
        $this->assertStringContainsString('on reply: one_call', $out);
    }

    public function test_send_sms_has_body_no_subject(): void
    {
        $out = $this->hint('send_sms', [
            'recipient' => '+15005551212',
            'body' => 'Test',
        ]);
        $this->assertStringContainsString('send a SMS to +15005551212', $out);
        $this->assertStringNotContainsString('subject', $out);
    }

    public function test_mark_message_sent_and_delivered(): void
    {
        $this->assertStringContainsString('was sent', $this->hint('mark_message_sent'));
        $this->assertStringContainsString('was delivered', $this->hint('mark_message_delivered'));
    }

    public function test_db_lookup_single_hint(): void
    {
        $out = $this->hint('db_lookup_single', [
            'connection_name' => 'billing_pg',
            'table_or_query' => 'customers',
        ]);
        $this->assertStringContainsString('look up a single row', $out);
        $this->assertStringContainsString('connection "billing_pg"', $out);
        $this->assertStringContainsString('`customers`', $out);
    }

    public function test_db_save_hint_with_table(): void
    {
        $out = $this->hint('db_save', [
            'connection_name' => 'billing_pg',
            'table' => 'call_logs',
        ]);
        $this->assertStringContainsString('upsert into table `call_logs`', $out);
        $this->assertStringContainsString('billing_pg', $out);
    }

    public function test_web_call_hint(): void
    {
        $out = $this->hint('web_call', [
            'endpoint_name' => 'crm_api',
            'method' => 'post',
            'path' => '/customers/{{ matched_customer_id }}/notes',
        ]);
        $this->assertStringContainsString('make POST', $out);
        $this->assertStringContainsString('endpoint "crm_api"', $out);
    }

    public function test_parse_json_and_xml_hints(): void
    {
        $this->assertStringContainsString('JSONPath', $this->hint('parse_json'));
        $this->assertStringContainsString('XPath', $this->hint('parse_xml'));
    }

    public function test_enter_dispatcher_queue_hint(): void
    {
        $this->assertStringContainsString(
            'dispatcher queue "emergency"',
            $this->hint('enter_dispatcher_queue', ['queue_name' => 'emergency']),
        );
        $this->assertStringContainsString(
            'not yet configured',
            $this->hint('enter_dispatcher_queue'),
        );
    }

    public function test_park_call_hint(): void
    {
        $out = $this->hint('park_call', ['park_type' => 'orbit', 'target' => '901']);
        $this->assertStringContainsString('park the call (orbit: 901)', $out);
        $this->assertStringContainsString('resume flow when retrieved', $out);
    }

    public function test_pause_hint_grammar(): void
    {
        $this->assertStringContainsString(
            'pause for 1 second.',
            $this->hint('pause', ['seconds' => 1]),
        );
        $this->assertStringContainsString(
            'pause for 5 seconds.',
            $this->hint('pause', ['seconds' => 5]),
        );
    }

    public function test_toggle_hold_and_recording(): void
    {
        $this->assertStringContainsString(
            'toggle call hold (resume)',
            $this->hint('toggle_hold', ['state' => 'resume']),
        );
        $this->assertStringContainsString(
            'toggle recording (start)',
            $this->hint('toggle_recording', ['state' => 'start']),
        );
    }

    public function test_transfer_to_voicemail_hint(): void
    {
        $this->assertStringContainsString(
            'voicemail box "sales"',
            $this->hint('transfer_to_voicemail', ['mailbox' => 'sales']),
        );
    }

    public function test_save_summary_hint(): void
    {
        $out = $this->hint('save_summary', [
            'summary_template' => 'Caller {{ caller_name }} at {{ caller_phone }}',
            'target_slot' => 'final_summary',
        ]);
        $this->assertStringContainsString('save summary:', $out);
        $this->assertStringContainsString('stash in `final_summary`', $out);
    }

    public function test_save_keyword_hint(): void
    {
        $this->assertStringContainsString(
            'keyword "vip"',
            $this->hint('save_keyword', ['keyword_slug' => 'vip']),
        );
        $this->assertStringContainsString(
            'Only tag if',
            $this->hint('save_keyword', [
                'keyword_slug' => 'vip',
                'condition' => 'is_member && amount_due > 1000',
            ]),
        );
    }

    public function test_save_call_tracker_event_hint(): void
    {
        $out = $this->hint('save_call_tracker_event', [
            'event_type' => 'customer_identified',
            'description' => 'Matched {{ caller_name }} to account {{ matched_customer_id }}',
        ]);
        $this->assertStringContainsString('`customer_identified`', $out);
        $this->assertStringContainsString('Matched', $out);
    }

    public function test_save_and_get_history_hints(): void
    {
        $save = $this->hint('save_history', [
            'scope' => 'account',
            'subject' => 'Transferred to billing',
        ]);
        $this->assertStringContainsString('account scope', $save);
        $this->assertStringContainsString('Transferred to billing', $save);

        $get = $this->hint('get_history', ['scope' => 'both', 'max_entries' => 10]);
        $this->assertStringContainsString('both scope', $get);
        $this->assertStringContainsString('up to 10', $get);
    }
}
