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
     *     secret?: bool,
     *     options?: array<string, string>,
     *     helper?: string,
     *     placeholder?: string,
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
            'app.name' => [
                'section' => 'app',
                'label' => 'App Name',
                'type' => 'text',
                'config_key' => 'app.name',
            ],
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
                'helper' => 'PHP timezone identifier — e.g. UTC, America/New_York, Europe/London.',
            ],
            'app.locale' => [
                'section' => 'app',
                'label' => 'Locale',
                'type' => 'text',
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
            'broadcasting.pusher.key' => [
                'section' => 'broadcasting',
                'label' => 'Pusher Key',
                'type' => 'text',
                'config_key' => 'broadcasting.connections.pusher.key',
            ],
            'broadcasting.pusher.secret' => [
                'section' => 'broadcasting',
                'label' => 'Pusher Secret',
                'type' => 'password',
                'config_key' => 'broadcasting.connections.pusher.secret',
                'secret' => true,
            ],
            'broadcasting.pusher.app_id' => [
                'section' => 'broadcasting',
                'label' => 'Pusher App ID',
                'type' => 'text',
                'config_key' => 'broadcasting.connections.pusher.app_id',
            ],
            'broadcasting.pusher.cluster' => [
                'section' => 'broadcasting',
                'label' => 'Pusher Cluster',
                'type' => 'text',
                'config_key' => 'broadcasting.connections.pusher.options.cluster',
                'placeholder' => 'mt1',
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
            'telephony.asterisk.ami.host' => [
                'section' => 'asterisk',
                'label' => 'AMI Host',
                'type' => 'text',
                'config_key' => 'telephony.asterisk.ami.host',
                'placeholder' => 'asterisk',
            ],
            'telephony.asterisk.ami.port' => [
                'section' => 'asterisk',
                'label' => 'AMI Port',
                'type' => 'number',
                'config_key' => 'telephony.asterisk.ami.port',
                'placeholder' => '5038',
            ],
            'telephony.asterisk.ami.username' => [
                'section' => 'asterisk',
                'label' => 'AMI Username',
                'type' => 'text',
                'config_key' => 'telephony.asterisk.ami.username',
            ],
            'telephony.asterisk.ami.secret' => [
                'section' => 'asterisk',
                'label' => 'AMI Secret',
                'type' => 'password',
                'config_key' => 'telephony.asterisk.ami.secret',
                'secret' => true,
            ],
            'telephony.asterisk.ari.url' => [
                'section' => 'asterisk',
                'label' => 'ARI URL',
                'type' => 'url',
                'config_key' => 'telephony.asterisk.ari.url',
                'placeholder' => 'http://asterisk:8088',
            ],
            'telephony.asterisk.ari.username' => [
                'section' => 'asterisk',
                'label' => 'ARI Username',
                'type' => 'text',
                'config_key' => 'telephony.asterisk.ari.username',
            ],
            'telephony.asterisk.ari.password' => [
                'section' => 'asterisk',
                'label' => 'ARI Password',
                'type' => 'password',
                'config_key' => 'telephony.asterisk.ari.password',
                'secret' => true,
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
                    's3' => 'S3 / MinIO (default)',
                    'local' => 'Local filesystem (development only)',
                ],
                'helper' => 'Laravel filesystem disk recordings are uploaded to. Must be S3-compatible in production.',
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
