<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asterisk Realtime Architecture (ARA) tables.
 *
 * These tables are read directly by Asterisk via the ODBC realtime
 * backend (extconfig.conf) and the pjsip sorcery wireup
 * (sorcery.conf). On every call setup, Asterisk queries:
 *   - ps_endpoints / ps_auths / ps_aors → pjsip endpoint config
 *   - ps_endpoint_id_ips → identify trunks by source IP
 *   - ps_contacts → registered SIP contact URIs (Asterisk writes these itself)
 *   - queues → queue config (strategy, timeouts, etc)
 *   - queue_members → who answers each queue
 *   - queue_log → Asterisk WRITES queue events here directly
 *
 * Schema tracks Asterisk 22's contrib/realtime/postgresql files. We
 * use the canonical column names + types so ALTER's against future
 * Asterisk versions are a single column add per table, not a full
 * rewrite. App code (the sync layer in Phase 4) writes to these
 * tables via Laravel's Eloquent / DB facade — Asterisk only reads.
 *
 * A few opinionated trims from the canonical schema:
 *   - ps_endpoints: ~70 columns total; we keep the ~40 we use
 *     (PJSIP transport / WebRTC / SIP phone / AI agent paths) and
 *     skip telco-side rarities (ISDN, T.38 fax, asciidoc commentary).
 *     Add columns by patch migration as use cases need them.
 *   - queue_log: bigserial PK rather than int because queue events
 *     for 1000 tenants will accumulate fast and we want to roll up
 *     to queue_metrics_daily without worrying about wraparound.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ps_endpoints — pjsip endpoint definitions ───────────
        Schema::create('ps_endpoints', function (Blueprint $table) {
            // Sorcery requires `id` as the primary key column name.
            $table->string('id', 40)->primary();
            $table->string('transport', 40)->nullable();
            $table->string('aors', 200)->nullable();
            $table->string('auth', 40)->nullable();
            $table->string('context', 40)->nullable();
            $table->string('disallow', 200)->nullable();
            $table->string('allow', 200)->nullable();
            $table->string('direct_media', 3)->nullable();        // yes/no
            $table->string('connected_line_method', 16)->nullable();
            $table->string('direct_media_method', 16)->nullable();
            $table->string('direct_media_glare_mitigation', 16)->nullable();
            $table->string('disable_direct_media_on_nat', 3)->nullable();
            $table->string('dtmf_mode', 16)->nullable();
            $table->string('external_media_address', 40)->nullable();
            $table->string('force_rport', 3)->nullable();
            $table->string('ice_support', 3)->nullable();
            $table->string('identify_by', 80)->nullable();
            $table->string('mailboxes', 40)->nullable();
            $table->string('moh_suggest', 40)->nullable();
            $table->string('outbound_auth', 40)->nullable();
            $table->string('outbound_proxy', 40)->nullable();
            $table->string('rewrite_contact', 3)->nullable();
            $table->string('rtp_ipv6', 3)->nullable();
            $table->string('rtp_symmetric', 3)->nullable();
            $table->string('send_diversion', 3)->nullable();
            $table->string('send_pai', 3)->nullable();
            $table->string('send_rpid', 3)->nullable();
            $table->string('timers_min_se', 16)->nullable();
            $table->string('timers', 16)->nullable();
            $table->string('timers_sess_expires', 16)->nullable();
            $table->string('callerid', 40)->nullable();
            $table->string('callerid_privacy', 32)->nullable();
            $table->string('callerid_tag', 40)->nullable();
            $table->string('trust_id_inbound', 3)->nullable();
            $table->string('trust_id_outbound', 3)->nullable();
            $table->string('use_ptime', 3)->nullable();
            $table->string('use_avpf', 3)->nullable();
            $table->string('media_encryption', 16)->nullable();
            $table->string('media_use_received_transport', 3)->nullable();
            $table->string('inband_progress', 3)->nullable();
            $table->string('call_group', 40)->nullable();
            $table->string('pickup_group', 40)->nullable();
            $table->string('named_call_group', 40)->nullable();
            $table->string('named_pickup_group', 40)->nullable();
            $table->string('device_state_busy_at', 16)->nullable();
            $table->string('t38_udptl', 3)->nullable();
            $table->string('t38_udptl_ec', 16)->nullable();
            $table->string('t38_udptl_maxdatagram', 16)->nullable();
            $table->string('fax_detect', 3)->nullable();
            $table->string('t38_udptl_nat', 3)->nullable();
            $table->string('t38_udptl_ipv6', 3)->nullable();
            $table->string('tone_zone', 40)->nullable();
            $table->string('language', 40)->nullable();
            $table->string('one_touch_recording', 3)->nullable();
            $table->string('record_on_feature', 40)->nullable();
            $table->string('record_off_feature', 40)->nullable();
            $table->string('rtp_engine', 40)->nullable();
            $table->string('allow_transfer', 3)->nullable();
            $table->string('allow_subscribe', 3)->nullable();
            $table->string('sdp_owner', 40)->nullable();
            $table->string('sdp_session', 40)->nullable();
            $table->string('tos_audio', 16)->nullable();
            $table->string('tos_video', 16)->nullable();
            $table->string('cos_audio', 16)->nullable();
            $table->string('cos_video', 16)->nullable();
            $table->string('sub_min_expiry', 16)->nullable();
            $table->string('from_user', 40)->nullable();
            $table->string('from_domain', 40)->nullable();
            $table->string('mwi_from_user', 40)->nullable();
            $table->string('dtls_verify', 40)->nullable();
            $table->string('dtls_rekey', 40)->nullable();
            $table->string('dtls_cert_file', 200)->nullable();
            $table->string('dtls_private_key', 200)->nullable();
            $table->string('dtls_cipher', 200)->nullable();
            $table->string('dtls_ca_file', 200)->nullable();
            $table->string('dtls_ca_path', 200)->nullable();
            $table->string('dtls_setup', 16)->nullable();
            $table->string('dtls_fingerprint', 16)->nullable();
            $table->string('dtls_auto_generate_cert', 3)->nullable();
            $table->string('srtp_tag_32', 3)->nullable();
            $table->string('set_var', 200)->nullable();
            $table->string('message_context', 40)->nullable();
            $table->string('accountcode', 80)->nullable();
            $table->string('rtcp_mux', 3)->nullable();
            $table->string('webrtc', 3)->nullable();
        });

        // ── ps_auths — pjsip authentication definitions ─────────
        Schema::create('ps_auths', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('auth_type', 16)->nullable();
            $table->integer('nonce_lifetime')->nullable();
            $table->string('md5_cred', 40)->nullable();
            $table->string('password', 80)->nullable();
            $table->string('realm', 40)->nullable();
            $table->string('username', 40)->nullable();
            $table->string('refresh_token', 255)->nullable();
            $table->string('oauth_clientid', 255)->nullable();
            $table->string('oauth_secret', 255)->nullable();
        });

        // ── ps_aors — pjsip Address-of-Record definitions ───────
        Schema::create('ps_aors', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('contact', 255)->nullable();
            $table->integer('default_expiration')->nullable();
            $table->string('mailboxes', 80)->nullable();
            $table->integer('max_contacts')->nullable();
            $table->integer('minimum_expiration')->nullable();
            $table->string('remove_existing', 3)->nullable();
            $table->integer('qualify_frequency')->nullable();
            $table->string('authenticate_qualify', 3)->nullable();
            $table->integer('maximum_expiration')->nullable();
            $table->string('outbound_proxy', 40)->nullable();
            $table->string('support_path', 3)->nullable();
            $table->string('qualify_timeout', 16)->nullable();
            $table->string('voicemail_extension', 40)->nullable();
        });

        // ── ps_endpoint_id_ips — identify endpoint by source IP ─
        Schema::create('ps_endpoint_id_ips', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('endpoint', 40)->nullable();
            $table->string('match', 80)->nullable();
            $table->string('srv_lookups', 3)->nullable();
            $table->string('match_header', 255)->nullable();
        });

        // ── ps_contacts — Asterisk writes these itself ──────────
        // Per-registration contact URIs. Asterisk (not us) inserts
        // and removes rows here as endpoints register and expire.
        // We just create the table and let Asterisk own it.
        Schema::create('ps_contacts', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('uri', 511)->nullable();
            $table->float('expiration_time')->nullable();
            $table->integer('qualify_frequency')->nullable();
            $table->string('outbound_proxy', 40)->nullable();
            $table->string('path', 511)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->float('qualify_timeout')->nullable();
            $table->string('reg_server', 255)->nullable();
            $table->string('authenticate_qualify', 3)->nullable();
            $table->string('via_addr', 40)->nullable();
            $table->integer('via_port')->nullable();
            $table->string('call_id', 255)->nullable();
            $table->string('endpoint', 40)->nullable();
            $table->float('prune_on_boot')->nullable();
        });

        // ── queues — app_queue config ──────────────────────────
        Schema::create('queues', function (Blueprint $table) {
            $table->string('name', 128)->primary();
            $table->string('musiconhold', 128)->nullable();
            $table->string('announce', 128)->nullable();
            $table->string('context', 128)->nullable();
            $table->integer('timeout')->nullable();
            $table->string('ringinuse', 8)->nullable();
            $table->string('setinterfacevar', 8)->nullable();
            $table->string('setqueuevar', 8)->nullable();
            $table->string('setqueueentryvar', 8)->nullable();
            $table->integer('monitor_format')->nullable();
            $table->integer('membermacro')->nullable();
            $table->integer('membergosub')->nullable();
            $table->integer('queue_youarenext')->nullable();
            $table->integer('queue_thereare')->nullable();
            $table->integer('queue_callswaiting')->nullable();
            $table->integer('queue_quantity1')->nullable();
            $table->integer('queue_quantity2')->nullable();
            $table->integer('queue_holdtime')->nullable();
            $table->integer('queue_minute')->nullable();
            $table->integer('queue_minutes')->nullable();
            $table->integer('queue_seconds')->nullable();
            $table->integer('queue_thankyou')->nullable();
            $table->integer('queue_callerannounce')->nullable();
            $table->integer('queue_reporthold')->nullable();
            $table->string('announce_frequency', 8)->nullable();
            $table->integer('announce_to_first_user')->nullable();
            $table->integer('min_announce_frequency')->nullable();
            $table->integer('announce_round_seconds')->nullable();
            $table->string('announce_holdtime', 16)->nullable();
            $table->string('announce_position', 16)->nullable();
            $table->integer('announce_position_limit')->nullable();
            $table->string('periodic_announce', 50)->nullable();
            $table->integer('periodic_announce_frequency')->nullable();
            $table->integer('relative_periodic_announce')->nullable();
            $table->string('random_periodic_announce', 8)->nullable();
            $table->integer('retry')->nullable();
            $table->integer('wrapuptime')->nullable();
            $table->integer('penaltymemberslimit')->nullable();
            $table->integer('autofill')->nullable();
            $table->string('monitor_type', 128)->nullable();
            $table->integer('autopause')->nullable();
            $table->integer('autopausedelay')->nullable();
            $table->integer('autopausebusy')->nullable();
            $table->integer('autopauseunavail')->nullable();
            $table->integer('maxlen')->nullable();
            $table->string('servicelevel', 8)->nullable();
            $table->string('strategy', 32)->nullable();
            $table->integer('joinempty')->nullable();
            $table->integer('leavewhenempty')->nullable();
            $table->string('reportholdtime', 8)->nullable();
            $table->string('memberdelay', 8)->nullable();
            $table->string('weight', 8)->nullable();
            $table->string('timeoutrestart', 8)->nullable();
            $table->integer('defaultrule')->nullable();
            $table->integer('timeoutpriority')->nullable();
        });

        // ── queue_members — who answers each queue ──────────────
        // `reason_paused` is required by Asterisk's app_queue
        // realtime require_columns check on startup. Without it
        // Asterisk logs "Realtime table queue_members@orbital
        // requires column 'reason_paused' but that column does not
        // exist" and refuses to load the queue backend, which
        // cascades into res_pjsip declining to load too.
        Schema::create('queue_members', function (Blueprint $table) {
            $table->string('queue_name', 128);
            $table->string('interface', 128);
            $table->string('membername', 128)->nullable();
            $table->string('state_interface', 128)->nullable();
            $table->integer('penalty')->nullable();
            $table->integer('paused')->nullable();
            $table->string('reason_paused', 80)->nullable();
            $table->bigInteger('uniqueid')->autoIncrement();
            $table->string('wrapuptime', 16)->nullable();

            $table->index(['queue_name', 'interface'], 'queue_members_queue_iface_idx');
        });

        // ── queue_log — Asterisk writes events here directly ───
        // app_queue with `queue_log_realtime = yes` (logger.conf)
        // appends every queue event to this table. We never write
        // to it from app code — only read for stats roll-up.
        Schema::create('queue_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('time')->nullable();
            $table->string('callid', 80)->nullable();
            $table->string('queuename', 128)->nullable();
            $table->string('agent', 80)->nullable();
            $table->string('event', 32)->nullable();
            $table->string('data1', 100)->nullable();
            $table->string('data2', 100)->nullable();
            $table->string('data3', 100)->nullable();
            $table->string('data4', 100)->nullable();
            $table->string('data5', 100)->nullable();

            $table->index(['queuename', 'time']);
            $table->index('time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_log');
        Schema::dropIfExists('queue_members');
        Schema::dropIfExists('queues');
        Schema::dropIfExists('ps_contacts');
        Schema::dropIfExists('ps_endpoint_id_ips');
        Schema::dropIfExists('ps_aors');
        Schema::dropIfExists('ps_auths');
        Schema::dropIfExists('ps_endpoints');
    }
};
