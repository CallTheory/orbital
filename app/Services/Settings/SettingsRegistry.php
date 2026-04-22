<?php

declare(strict_types=1);

namespace App\Services\Settings;

/**
 * Single source of truth for every DB-backed runtime setting in the panel.
 *
 * Each entry says:
 *   - which Filament field type to render (text/password/select/url/email/number/textarea/toggle)
 *   - which Laravel config key to override at boot (so `config('mail.from.address')`
 *     etc. transparently reflect DB values)
 *   - whether the value is a secret (encrypted at rest, masked in the form)
 *   - section metadata so the form can be grouped tidily
 *
 * Adding a new editable setting is a one-liner here — no repository, provider,
 * or form changes required.
 */
final class SettingsRegistry
{
    /**
     * Section metadata for the platform settings form. Order = display order.
     *
     * @return array<string, array{label: string, icon: string, description: string}>
     */
    public static function sections(): array
    {
        return [
            'branding' => [
                'label' => 'Branding',
                'icon' => 'heroicon-o-identification',
                'description' => 'How the platform identifies itself in UIs, emails, and error messages.',
            ],
            'app' => [
                'label' => 'Application',
                'icon' => 'heroicon-o-globe-alt',
                'description' => 'Core Laravel app settings — name, URL, locale, timezone.',
            ],
            'mail' => [
                'label' => 'Mail',
                'icon' => 'heroicon-o-envelope',
                'description' => 'Outbound email transport. Applies to all notifications, password resets, and tenant messages.',
            ],
            'inbound_mail' => [
                'label' => 'Inbound Mail',
                'icon' => 'heroicon-o-inbox-arrow-down',
                'description' => 'SendGrid-style inbound parsing: the Haraka SMTP shim accepts mail for tenant account numbers and posts it to the Laravel webhook.',
            ],
            'logging' => [
                'label' => 'Logging',
                'icon' => 'heroicon-o-document-text',
                'description' => 'Default log channel and verbosity. Applies instantly to HTTP requests; Horizon workers pick up changes after restart.',
            ],
            'icecast' => [
                'label' => 'Icecast',
                'icon' => 'heroicon-o-musical-note',
                'description' => 'Credentials Laravel uses to authenticate to the Icecast admin endpoint for live status and listener counts.',
            ],
            'sessions' => [
                'label' => 'Sessions',
                'icon' => 'heroicon-o-clock',
                'description' => 'Session lifetime and cookie protection.',
            ],
            'security' => [
                'label' => 'Security',
                'icon' => 'heroicon-o-lock-closed',
                'description' => 'Password hashing and other platform-wide security tuning.',
            ],
            'broadcasting' => [
                'label' => 'Broadcasting',
                'icon' => 'heroicon-o-signal',
                'description' => 'Real-time event broadcasting (Pusher / Reverb / Ably).',
            ],
            'ai_providers' => [
                'label' => 'AI Provider Credentials',
                'icon' => 'heroicon-o-key',
                'description' => 'API keys for the LLM, STT, and TTS providers Orbital can talk to.',
            ],
            'asterisk' => [
                'label' => 'Asterisk',
                'icon' => 'heroicon-o-phone-arrow-up-right',
                'description' => 'SIP telephony backbone — AMI, ARI, and SIP transport settings.',
            ],
            'livekit' => [
                'label' => 'LiveKit',
                'icon' => 'heroicon-o-bolt',
                'description' => 'Real-time media and AI agent runtime credentials.',
            ],
            'agent_worker' => [
                'label' => 'Agent Worker',
                'icon' => 'heroicon-o-cpu-chip',
                'description' => 'Python LiveKit worker that runs AI agents.',
            ],
            'telescope' => [
                'label' => 'Telescope',
                'icon' => 'heroicon-o-magnifying-glass',
                'description' => 'Laravel Telescope debug + audit dashboard. Toggle it off and prune entries from here.',
            ],
            'knowledge' => [
                'label' => 'Knowledge & Embeddings',
                'icon' => 'heroicon-o-book-open',
                'description' => 'How retrieval-augmented intake goals embed and search tenant knowledge stores.',
            ],
            'recording' => [
                'label' => 'Call Recording',
                'icon' => 'heroicon-o-microphone',
                'description' => 'Platform defaults for call recording. Tenants can override per-call via their own settings.',
            ],
        ];
    }

    /**
     * The full registry of editable settings.
     *
     * Each entry is keyed by the platform-settings storage key (e.g.
     * `mail.from.address`) and describes how it's displayed and what
     * Laravel config it overrides.
     *
     * @return array<string, array{
     *     section: string,
     *     label: string,
     *     type: 'text'|'password'|'email'|'url'|'number'|'textarea'|'select'|'toggle',
     *     config_key: ?string,
     *     config_keys?: array<int, string>,
     *     secret?: bool,
     *     options?: array<string, string>,
     *     helper?: string,
     *     placeholder?: string,
     *     restart_required?: array<int, string>,
     * }>
     */
    public static function all(): array
    {
        return [

            // ─── Branding ──────────────────────────────────────────────
            'orbital.platform_name' => [
                'section' => 'branding',
                'label' => 'Platform Name',
                'type' => 'text',
                'config_key' => 'orbital.platform_name',
                'helper' => 'Displayed in headers, login pages, and emails.',
            ],
            'orbital.platform_company' => [
                'section' => 'branding',
                'label' => 'Operating Company',
                'type' => 'text',
                'config_key' => 'orbital.platform_company',
                'helper' => 'Legal entity behind the platform — appears in footers and customer-facing copy.',
            ],
            'orbital.support_email' => [
                'section' => 'branding',
                'label' => 'Support Email',
                'type' => 'email',
                'config_key' => 'orbital.support_email',
            ],
            'orbital.support_message' => [
                'section' => 'branding',
                'label' => 'Support Message',
                'type' => 'textarea',
                'config_key' => 'orbital.support_message',
                'helper' => 'Shown when users hit a quota or error and need to be told who to contact.',
            ],

            // ─── Application ───────────────────────────────────────────
            // App Name intentionally not surfaced here — "Platform
            // Name" (Branding section) is the tenant-facing name and
            // now feeds everything that would have read app.name.
            // APP_NAME in .env still drives Laravel's internal config.
            'app.url' => [
                'section' => 'app',
                'label' => 'App URL',
                'type' => 'url',
                'config_key' => 'app.url',
                'helper' => 'Public-facing root URL. Used for password reset links and absolute URLs in emails.',
            ],
            'app.timezone' => [
                'section' => 'app',
                'label' => 'Timezone',
                'type' => 'text',
                'config_key' => 'app.timezone',
                'placeholder' => 'UTC',
                'helper' => 'Recommended: leave at UTC. Individual users set their own timezone on their profile, and UI dates render in each user\'s local zone. Changing this affects logs, scheduled-job timestamps, and database writes — only override if you have a specific operational reason.',
            ],
            'app.locale' => [
                'section' => 'app',
                'label' => 'Locale',
                'type' => 'text',
                'helper' => 'Recommended: leave at the default (en). Individual users set their own locale on their profile page, and the UI renders in each user\'s preferred language. This system-level value is only the fallback for system-generated content (scheduled-job logs, unauthenticated pages).',
                'config_key' => 'app.locale',
                'placeholder' => 'en',
            ],

            // ─── Mail ──────────────────────────────────────────────────
            'mail.default' => [
                'section' => 'mail',
                'label' => 'Default Mailer',
                'type' => 'select',
                'config_key' => 'mail.default',
                'options' => [
                    'smtp' => 'SMTP',
                    'log' => 'Log (write to laravel.log)',
                    'array' => 'Array (in-memory, testing only)',
                    'sendmail' => 'Sendmail',
                    'mailgun' => 'Mailgun',
                    'postmark' => 'Postmark',
                    'resend' => 'Resend',
                    'ses' => 'AWS SES',
                ],
            ],
            'mail.smtp.host' => [
                'section' => 'mail',
                'label' => 'SMTP Host',
                'type' => 'text',
                'config_key' => 'mail.mailers.smtp.host',
                'placeholder' => 'smtp.example.com',
            ],
            'mail.smtp.port' => [
                'section' => 'mail',
                'label' => 'SMTP Port',
                'type' => 'number',
                'config_key' => 'mail.mailers.smtp.port',
                'placeholder' => '587',
            ],
            'mail.smtp.username' => [
                'section' => 'mail',
                'label' => 'SMTP Username',
                'type' => 'text',
                'config_key' => 'mail.mailers.smtp.username',
            ],
            'mail.smtp.password' => [
                'section' => 'mail',
                'label' => 'SMTP Password',
                'type' => 'password',
                'config_key' => 'mail.mailers.smtp.password',
                'secret' => true,
            ],
            'mail.smtp.encryption' => [
                'section' => 'mail',
                'label' => 'SMTP Encryption',
                'type' => 'select',
                'config_key' => 'mail.mailers.smtp.encryption',
                'options' => [
                    'tls' => 'TLS',
                    'ssl' => 'SSL',
                    '' => 'None',
                ],
            ],
            'mail.from.address' => [
                'section' => 'mail',
                'label' => 'From Address',
                'type' => 'email',
                'config_key' => 'mail.from.address',
                'placeholder' => 'noreply@example.com',
            ],
            'mail.from.name' => [
                'section' => 'mail',
                'label' => 'From Name',
                'type' => 'text',
                'config_key' => 'mail.from.name',
            ],

            // ─── Inbound Mail ──────────────────────────────────────────
            // Pairs with docker-compose Haraka config — both sides MUST
            // carry the same token, and the MX record for the domain
            // MUST point at the Haraka container's public address. The
            // admin UI can only update the Laravel side, so these
            // helpers spell out the other half of the operation.
            'services.inbound_mail.domain' => [
                'section' => 'inbound_mail',
                'label' => 'Inbound domain',
                'type' => 'text',
                'config_key' => 'services.inbound_mail.domain',
                'placeholder' => 'inbound.orbital.test',
                'helper' => 'The domain tenant account-number local-parts land on — e.g. 100001@inbound.orbital.test. Used by outbound Reply-To headers. DNS MX record for this domain must point at the Haraka SMTP shim or inbound mail will bounce.',
            ],
            'services.inbound_mail.token' => [
                'section' => 'inbound_mail',
                'label' => 'Webhook shared secret',
                'type' => 'password',
                'config_key' => 'services.inbound_mail.token',
                'secret' => true,
                'helper' => 'Shared secret between the Haraka SMTP shim and the /api/mail/inbound webhook. Haraka sends it as `Authorization: Bearer {token}`. Rotating here WILL break inbound mail until you also update INBOUND_MAIL_TOKEN on the haraka service in docker-compose.yml and restart the container.',
            ],

            // ─── Logging ───────────────────────────────────────────────
            // LOG_CHANNEL controls which channel name `Log::info(...)`
            // writes to by default. LOG_LEVEL filters the minimum
            // severity each individual channel emits — baked into each
            // channel config at env-load time, so we fan a single
            // override out across every channel via `config_keys`.
            'logging.default' => [
                'section' => 'logging',
                'label' => 'Default channel',
                'type' => 'select',
                'config_key' => 'logging.default',
                'options' => [
                    'stack' => 'Stack (composes several channels)',
                    'single' => 'Single file (storage/logs/laravel.log)',
                    'daily' => 'Daily rotating files',
                    'stderr' => 'stderr (container stdout, recommended in HA)',
                    'syslog' => 'syslog',
                    'errorlog' => 'PHP error_log',
                    'slack' => 'Slack webhook (critical-only by default)',
                    'null' => 'Discard (no logging)',
                ],
                'helper' => 'To compose a stack of multiple channels (LOG_STACK), edit .env — the composition isn\'t surfaced here because it needs both channel names and an array type.',
                'restart_required' => ['horizon'],
            ],
            'logging.level' => [
                'section' => 'logging',
                'label' => 'Minimum log level',
                'type' => 'select',
                'config_keys' => [
                    'logging.channels.single.level',
                    'logging.channels.daily.level',
                    'logging.channels.stderr.level',
                    'logging.channels.syslog.level',
                    'logging.channels.errorlog.level',
                    'logging.channels.papertrail.level',
                    // Slack intentionally omitted — its default is
                    // 'critical' so it doesn't spam a channel with
                    // info-level chatter, and blanket-setting it to
                    // `debug` would flood the webhook.
                ],
                'options' => [
                    'debug' => 'Debug (most verbose)',
                    'info' => 'Info',
                    'notice' => 'Notice',
                    'warning' => 'Warning',
                    'error' => 'Error',
                    'critical' => 'Critical',
                    'alert' => 'Alert',
                    'emergency' => 'Emergency (least verbose)',
                ],
                'helper' => 'Applies to file, stderr, syslog, errorlog, and papertrail channels. Slack is left at `critical` regardless so low-severity events don\'t spam the webhook.',
                'restart_required' => ['horizon'],
            ],

            // ─── Icecast ───────────────────────────────────────────────
            // Laravel only uses the admin credentials — it reverse-
            // proxies the Icecast admin UI so operators can watch
            // listener counts without shipping a public Icecast admin.
            // The source/relay/main listener passwords are consumed
            // INSIDE the icecast container (and by the Asterisk MOH
            // publisher) via their own env; rotating them via this UI
            // wouldn't change anything, so they deliberately aren't
            // here — document `.env` for those.
            'services.icecast.admin_user' => [
                'section' => 'icecast',
                'label' => 'Admin username',
                'type' => 'text',
                'config_key' => 'services.icecast.admin_user',
                'placeholder' => 'admin',
                'helper' => 'Hardcoded to `admin` by every Icecast image we\'ve shipped against. Only change if a future image uses a different convention.',
            ],
            'services.icecast.admin_password' => [
                'section' => 'icecast',
                'label' => 'Admin password',
                'type' => 'password',
                'config_key' => 'services.icecast.admin_password',
                'secret' => true,
                'helper' => 'Used by the /icecast admin proxy to Basic-Auth to Icecast. Rotating here WILL break the proxy until you also update ICECAST_ADMIN_PASSWORD on the icecast service in docker-compose.yml and restart the container.',
            ],

            // ─── Sessions ──────────────────────────────────────────────
            // Applied at request time — live cookies aren't touched,
            // but newly-issued cookies use the updated values. Flipping
            // encryption on an existing deployment invalidates every
            // extant session on first read; helper calls it out.
            'session.lifetime' => [
                'section' => 'sessions',
                'label' => 'Session lifetime (minutes)',
                'type' => 'number',
                'config_key' => 'session.lifetime',
                'placeholder' => '120',
                'helper' => 'How long a user stays logged in between requests. Defaults to 120 (2 hours). Longer values reduce login friction; shorter values reduce the window a stolen cookie stays useful.',
            ],
            'session.encrypt' => [
                'section' => 'sessions',
                'label' => 'Encrypt session cookie',
                'type' => 'toggle',
                'config_key' => 'session.encrypt',
                'helper' => 'When on, Laravel encrypts the session cookie end-to-end. Turning this on (or off) after users are already signed in will invalidate every active session — everyone gets logged out on their next request.',
            ],

            // ─── Security ──────────────────────────────────────────────
            // Bcrypt cost rounds; higher = slower = harder to brute
            // force. Existing hashes keep working at their original
            // cost — only new hashes and rehashes use the new value.
            'hashing.bcrypt.rounds' => [
                'section' => 'security',
                'label' => 'Bcrypt cost rounds',
                'type' => 'number',
                'config_key' => 'hashing.bcrypt.rounds',
                'placeholder' => '12',
                'helper' => 'Cost factor for password hashing. Each +1 roughly doubles hash time. Laravel default is 12; raise to 13–14 on fast servers for a modest security bump. Existing password hashes aren\'t re-hashed retroactively — only new logins/password changes pick up the new cost.',
            ],

            // ─── Broadcasting ──────────────────────────────────────────
            'broadcasting.default' => [
                'section' => 'broadcasting',
                'label' => 'Driver',
                'type' => 'select',
                'config_key' => 'broadcasting.default',
                'options' => [
                    'null' => 'Disabled',
                    'log' => 'Log',
                    'reverb' => 'Reverb (self-hosted)',
                    'pusher' => 'Pusher',
                    'ably' => 'Ably',
                ],
            ],
            // Reverb — Laravel's self-hosted Pusher-protocol WebSocket
            // server. Orbital ships Reverb wired for real-time UI
            // updates (softphone events, call queue status, etc.). The
            // app-id / key / secret tuple authenticates both the
            // server process and the JS client (via VITE_REVERB_*),
            // so editing any of these requires a Reverb restart AND
            // a front-end rebuild (or at minimum a fresh page load
            // where the JS client picks up the new values).
            'broadcasting.reverb.app_id' => [
                'section' => 'broadcasting',
                'label' => 'Reverb App ID',
                'type' => 'text',
                'config_key' => 'reverb.apps.apps.0.app_id',
                'helper' => 'Shared secret identifier between the Reverb server and its clients.',
            ],
            'broadcasting.reverb.key' => [
                'section' => 'broadcasting',
                'label' => 'Reverb Key',
                'type' => 'text',
                'config_key' => 'reverb.apps.apps.0.key',
                'helper' => 'Public key the JS client uses to subscribe. Must match VITE_REVERB_APP_KEY.',
            ],
            'broadcasting.reverb.secret' => [
                'section' => 'broadcasting',
                'label' => 'Reverb Secret',
                'type' => 'password',
                'config_key' => 'reverb.apps.apps.0.secret',
                'secret' => true,
                'helper' => 'Server-side secret the Laravel app signs events with. Never shipped to the browser.',
            ],
            'broadcasting.reverb.host' => [
                'section' => 'broadcasting',
                'label' => 'Reverb Host',
                'type' => 'text',
                'config_key' => 'reverb.apps.apps.0.options.host',
                'placeholder' => 'orbital.test',
                'helper' => 'Public hostname the browser connects to for WebSockets. Usually your app domain.',
            ],
            'broadcasting.reverb.port' => [
                'section' => 'broadcasting',
                'label' => 'Reverb Port',
                'type' => 'number',
                'config_key' => 'reverb.apps.apps.0.options.port',
                'placeholder' => '443',
                'helper' => '443 in production (fronted by nginx), 8080 in local dev.',
            ],
            'broadcasting.reverb.scheme' => [
                'section' => 'broadcasting',
                'label' => 'Reverb Scheme',
                'type' => 'select',
                'config_key' => 'reverb.apps.apps.0.options.scheme',
                'options' => [
                    'https' => 'HTTPS (wss://)',
                    'http' => 'HTTP (ws://) — dev only',
                ],
            ],

            // ─── AI Providers ──────────────────────────────────────────
            'services.anthropic.api_key' => [
                'section' => 'ai_providers',
                'label' => 'Anthropic API Key',
                'type' => 'password',
                'config_key' => 'services.anthropic.api_key',
                'secret' => true,
                'placeholder' => 'sk-ant-...',
            ],
            'services.openai.api_key' => [
                'section' => 'ai_providers',
                'label' => 'OpenAI API Key',
                'type' => 'password',
                'config_key' => 'services.openai.api_key',
                'secret' => true,
                'placeholder' => 'sk-...',
            ],
            'services.openrouter.api_key' => [
                'section' => 'ai_providers',
                'label' => 'OpenRouter API Key',
                'type' => 'password',
                'config_key' => 'services.openrouter.api_key',
                'secret' => true,
                'placeholder' => 'sk-or-...',
            ],
            'services.elevenlabs.api_key' => [
                'section' => 'ai_providers',
                'label' => 'ElevenLabs API Key',
                'type' => 'password',
                'config_key' => 'services.elevenlabs.api_key',
                'secret' => true,
            ],
            'services.deepgram.api_key' => [
                'section' => 'ai_providers',
                'label' => 'Deepgram API Key',
                'type' => 'password',
                'config_key' => 'services.deepgram.api_key',
                'secret' => true,
            ],
            'services.cartesia.api_key' => [
                'section' => 'ai_providers',
                'label' => 'Cartesia API Key',
                'type' => 'password',
                'config_key' => 'services.cartesia.api_key',
                'secret' => true,
            ],

            // ─── Asterisk ──────────────────────────────────────────────
            // AMI host/port and all ARI config live per-backend under
            // Telephony → Asterisk Backends. Only cluster-wide
            // credentials and external-facing endpoints belong here.
            'telephony.asterisk.ami.username' => [
                'section' => 'asterisk',
                'label' => 'AMI Username',
                'type' => 'text',
                'config_key' => 'telephony.asterisk.ami.username',
                'helper' => 'Shared AMI login applied to every Asterisk backend. Per-node hostnames live under Telephony → Asterisk Backends.',
            ],
            'telephony.asterisk.ami.secret' => [
                'section' => 'asterisk',
                'label' => 'AMI Secret',
                'type' => 'password',
                'config_key' => 'telephony.asterisk.ami.secret',
                'secret' => true,
                'helper' => 'Shared AMI password applied to every Asterisk backend.',
            ],
            'telephony.asterisk.sip_domain' => [
                'section' => 'asterisk',
                'label' => 'SIP Domain',
                'type' => 'text',
                'config_key' => 'telephony.asterisk.sip_domain',
            ],
            'telephony.asterisk.wss_url' => [
                'section' => 'asterisk',
                'label' => 'WebRTC (WSS) URL',
                'type' => 'url',
                'config_key' => 'telephony.asterisk.wss_url',
                'placeholder' => 'wss://example.com:8089/ws',
            ],
            'telephony.asterisk.internal_did_simulation' => [
                'section' => 'asterisk',
                'label' => 'Internal DID simulation',
                'type' => 'toggle',
                'config_key' => 'telephony.asterisk.internal_did_simulation',
                'helper' => 'Development-only shortcut that lets operator softphones dial a tenant DID directly through the internal dialplan, as if it arrived on a real trunk. Leave OFF in production — a real operator should never be able to self-originate a call as if it came from outside the building.',
                'restart_required' => ['asterisk'],
            ],

            // ─── LiveKit ───────────────────────────────────────────────
            'telephony.livekit.mode' => [
                'section' => 'livekit',
                'label' => 'Mode',
                'type' => 'select',
                'config_key' => 'telephony.livekit.mode',
                'options' => [
                    'local' => 'Local (self-hosted)',
                    'cloud' => 'LiveKit Cloud',
                    'failover' => 'Failover (local → cloud)',
                ],
            ],
            'telephony.livekit.local.url' => [
                'section' => 'livekit',
                'label' => 'Local URL',
                'type' => 'url',
                'config_key' => 'telephony.livekit.local.url',
                'placeholder' => 'http://livekit:7880',
            ],
            'telephony.livekit.local.api_key' => [
                'section' => 'livekit',
                'label' => 'Local API Key',
                'type' => 'text',
                'config_key' => 'telephony.livekit.local.api_key',
            ],
            'telephony.livekit.local.api_secret' => [
                'section' => 'livekit',
                'label' => 'Local API Secret',
                'type' => 'password',
                'config_key' => 'telephony.livekit.local.api_secret',
                'secret' => true,
            ],
            'telephony.livekit.cloud.url' => [
                'section' => 'livekit',
                'label' => 'Cloud URL',
                'type' => 'url',
                'config_key' => 'telephony.livekit.cloud.url',
                'placeholder' => 'wss://your-project.livekit.cloud',
            ],
            'telephony.livekit.cloud.api_key' => [
                'section' => 'livekit',
                'label' => 'Cloud API Key',
                'type' => 'text',
                'config_key' => 'telephony.livekit.cloud.api_key',
            ],
            'telephony.livekit.cloud.api_secret' => [
                'section' => 'livekit',
                'label' => 'Cloud API Secret',
                'type' => 'password',
                'config_key' => 'telephony.livekit.cloud.api_secret',
                'secret' => true,
            ],

            // ─── Agent worker ──────────────────────────────────────────
            'services.agent_worker.url' => [
                'section' => 'agent_worker',
                'label' => 'Worker URL',
                'type' => 'url',
                'config_key' => 'services.agent_worker.url',
                'placeholder' => 'http://agent-worker:8089',
            ],
            'services.agent_worker.token' => [
                'section' => 'agent_worker',
                'label' => 'Worker Auth Token',
                'type' => 'password',
                'config_key' => 'services.agent_worker.token',
                'secret' => true,
                'helper' => 'Shared secret the Python worker uses to POST heartbeats and call the Orbital API.',
            ],

            // ─── Telescope ─────────────────────────────────────────────
            'telescope.enabled' => [
                'section' => 'telescope',
                'label' => 'Enabled',
                'type' => 'toggle',
                'config_key' => 'telescope.enabled',
                'helper' => 'Master switch. When off, Telescope stops recording entries. The /telescope route still loads.',
            ],
            'telescope.retention_hours' => [
                'section' => 'telescope',
                'label' => 'Retention (hours)',
                'type' => 'number',
                'config_key' => null, // consumed by the Prune action, not Laravel core
                'placeholder' => '48',
                'helper' => 'How long to keep entries before the Prune button (or `telescope:prune` cron) deletes them.',
            ],
            'telescope.watchers.cache' => [
                'section' => 'telescope',
                'label' => 'Cache Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\CacheWatcher.enabled',
            ],
            'telescope.watchers.command' => [
                'section' => 'telescope',
                'label' => 'Command Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\CommandWatcher.enabled',
            ],
            'telescope.watchers.event' => [
                'section' => 'telescope',
                'label' => 'Event Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\EventWatcher.enabled',
            ],
            'telescope.watchers.exception' => [
                'section' => 'telescope',
                'label' => 'Exception Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\ExceptionWatcher.enabled',
            ],
            'telescope.watchers.job' => [
                'section' => 'telescope',
                'label' => 'Job Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\JobWatcher.enabled',
            ],
            'telescope.watchers.log' => [
                'section' => 'telescope',
                'label' => 'Log Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\LogWatcher.enabled',
            ],
            'telescope.watchers.mail' => [
                'section' => 'telescope',
                'label' => 'Mail Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\MailWatcher.enabled',
            ],
            'telescope.watchers.model' => [
                'section' => 'telescope',
                'label' => 'Model Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\ModelWatcher.enabled',
            ],
            'telescope.watchers.notification' => [
                'section' => 'telescope',
                'label' => 'Notification Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\NotificationWatcher.enabled',
            ],
            'telescope.watchers.query' => [
                'section' => 'telescope',
                'label' => 'Query Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\QueryWatcher.enabled',
            ],
            'telescope.watchers.redis' => [
                'section' => 'telescope',
                'label' => 'Redis Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\RedisWatcher.enabled',
            ],
            'telescope.watchers.request' => [
                'section' => 'telescope',
                'label' => 'Request Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\RequestWatcher.enabled',
            ],
            'telescope.watchers.schedule' => [
                'section' => 'telescope',
                'label' => 'Schedule Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\ScheduleWatcher.enabled',
            ],
            'telescope.watchers.view' => [
                'section' => 'telescope',
                'label' => 'View Watcher',
                'type' => 'toggle',
                'config_key' => 'telescope.watchers.Laravel\\Telescope\\Watchers\\ViewWatcher.enabled',
            ],

            // ─── Knowledge & Embeddings ────────────────────────────────
            'services.embeddings.default_provider' => [
                'section' => 'knowledge',
                'label' => 'Default embedding model',
                'type' => 'select',
                'config_key' => 'services.embeddings.default_provider',
                'options' => [
                    'openai:text-embedding-3-small' => 'OpenAI — text-embedding-3-small (1536 dims, hosted)',
                    'openai:text-embedding-3-large' => 'OpenAI — text-embedding-3-large (3072 dims, hosted)',
                    'ollama:nomic-embed-text' => 'Ollama — nomic-embed-text (768 dims, local)',
                    'ollama:mxbai-embed-large' => 'Ollama — mxbai-embed-large (1024 dims, local)',
                    'ollama:bge-m3' => 'Ollama — bge-m3 (1024 dims, local, multilingual)',
                ],
                'helper' => 'New knowledge stores default to this model. Ollama options require starting the container with `docker compose --profile local-ai up -d ollama` and pulling the model.',
            ],
            'services.ollama.url' => [
                'section' => 'knowledge',
                'label' => 'Ollama URL',
                'type' => 'url',
                'config_key' => 'services.ollama.url',
                'placeholder' => 'http://ollama:11434',
                'helper' => 'Only used when a store\'s embedding_model starts with `ollama:`. In-cluster default is http://ollama:11434.',
            ],

            // ─── Call Recording ───────────────────────────────────────
            'telephony.recording.enabled' => [
                'section' => 'recording',
                'label' => 'Call recording enabled',
                'type' => 'toggle',
                'config_key' => 'telephony.recording.enabled',
                'helper' => 'Master switch. When off, no calls are recorded regardless of tenant or extension settings.',
            ],
            'telephony.recording.format' => [
                'section' => 'recording',
                'label' => 'Recording format',
                'type' => 'select',
                'config_key' => 'telephony.recording.format',
                'options' => [
                    'wav' => 'WAV (lossless, larger files)',
                    'mp3' => 'MP3 (compressed, smaller files)',
                ],
                'helper' => 'Asterisk MixMonitor picks the encoder from the file extension.',
            ],
            'telephony.recording.retention_days' => [
                'section' => 'recording',
                'label' => 'Retention (days)',
                'type' => 'number',
                'config_key' => 'telephony.recording.retention_days',
                'placeholder' => '90',
                'helper' => 'How long recordings are kept before the prune job deletes them. Set to 0 to keep forever.',
            ],
            'telephony.recording.storage_disk' => [
                'section' => 'recording',
                'label' => 'Storage disk',
                'type' => 'select',
                'config_key' => 'telephony.recording.storage_disk',
                'options' => [
                    's3' => 'S3 / SeaweedFS (default)',
                    'local' => 'Local filesystem (development only)',
                ],
                'helper' => 'Laravel filesystem disk recordings are uploaded to. Must be S3-compatible in production — Orbital ships with SeaweedFS as the default object store.',
            ],
            'telephony.recording.beep_on_record' => [
                'section' => 'recording',
                'label' => 'Play beep when recording starts',
                'type' => 'toggle',
                'config_key' => 'telephony.recording.beep_on_record',
                'helper' => 'Required in some jurisdictions to notify the caller that the conversation is being recorded.',
            ],
            'telephony.recording.beep_interval_seconds' => [
                'section' => 'recording',
                'label' => 'Periodic beep interval (seconds)',
                'type' => 'number',
                'config_key' => 'telephony.recording.beep_interval_seconds',
                'helper' => 'Seconds between repeated notification beeps DURING a recording. Some jurisdictions require a periodic audible reminder throughout the call. Set to 0 to only beep once at the start (or disable entirely if the setting above is off).',
            ],
            'telephony.recording.disclosure_message' => [
                'section' => 'recording',
                'label' => 'Disclosure message (TTS)',
                'type' => 'textarea',
                'config_key' => 'telephony.recording.disclosure_message',
                'helper' => 'Optional text spoken to the caller at the start of a recorded call — e.g. "This call may be monitored or recorded for quality assurance purposes." Leave blank to skip. Tenants can override with their own message.',
            ],

        ];
    }

    /**
     * Filter the full registry to entries in a given section, preserving order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forSection(string $section): array
    {
        return array_filter(self::all(), fn (array $def) => ($def['section'] ?? null) === $section);
    }

    /**
     * Lookup a single entry. Returns null for unknown keys.
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Whether a setting key is defined as secret (and therefore encrypted at rest).
     */
    public static function isSecret(string $key): bool
    {
        return (bool) (self::find($key)['secret'] ?? false);
    }
}
