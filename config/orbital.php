<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Release Identity & Licensing
    |--------------------------------------------------------------------------
    |
    | Orbital is AGPL-3.0. Section 13 of that license obliges anyone who
    | offers modified Orbital to users over a network to offer those users
    | the corresponding source. The app satisfies that itself — the panel
    | footers, the /source route, the /api/version endpoint, and the About
    | page all read from here.
    |
    | IF YOU FORK AND MODIFY ORBITAL, POINT `ORBITAL_SOURCE_URL` AT YOUR OWN
    | REPOSITORY. Leaving it aimed at upstream while shipping changed code
    | does not discharge your obligation — it misdirects your users to
    | source that isn't what they're running.
    |
    | `version` and `commit` are baked at image build time (Dockerfile ARGs
    | ORBITAL_VERSION / ORBITAL_COMMIT) so a running container can name
    | exactly what it is without a .git directory present.
    |
    */
    'version' => env('ORBITAL_VERSION', 'dev'),
    'commit' => env('ORBITAL_COMMIT'),
    'release_channel' => env('ORBITAL_RELEASE_CHANNEL', 'dev'),

    'license' => [
        'spdx' => 'AGPL-3.0-only',
        'name' => 'GNU Affero General Public License v3.0',
        'url' => 'https://www.gnu.org/licenses/agpl-3.0.html',
    ],

    'source_url' => env('ORBITAL_SOURCE_URL', 'https://github.com/calltheory/orbital'),

    /*
    |--------------------------------------------------------------------------
    | Support Subscription
    |--------------------------------------------------------------------------
    |
    | A support subscription key gates SUPPORT SURFACES ONLY — in-app ticket
    | submission and the signed update channel. It never gates a product
    | feature, never expires the software, and its absence is a completely
    | normal, fully supported state. See LICENSING.md.
    |
    | Verification is offline: the key is a base64 payload plus a detached
    | Ed25519 signature checked against the public key below. No phone-home,
    | consistent with the offline-first constraint.
    |
    */
    'support' => [
        // Ed25519 public key (base64, 32 raw bytes) used to verify
        // subscription keys. Null here on purpose: official release builds
        // bake Call Theory's key in via env, and a downstream distributor
        // selling their own support sets their own. With no key configured
        // there is simply no support subscription — which is the correct
        // default for a source build.
        'public_key' => env('ORBITAL_SUPPORT_PUBLIC_KEY'),
        'portal_url' => env('ORBITAL_SUPPORT_PORTAL_URL', 'https://calltheory.com/orbital/support'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Two-factor enforcement applies to EVERYONE — platform staff and
    | client portal users alike. Staff have access to every client's
    | messages and recordings, so the argument for a second factor is
    | stronger for them, not weaker.
    |
    | The grace window is the only knob, and it's per-client on
    | `teams.two_factor_grace_days`; the value here is the fallback for
    | platform staff, who have no client team.
    |
    | TWO_FACTOR_REQUIRED exists because enabling this is a change that
    | can lock people out. An operator has to be able to turn it off
    | from the environment without a code change.
    |
    */
    'security' => [
        'two_factor_required' => (bool) env('TWO_FACTOR_REQUIRED', true),
        'two_factor_grace_days' => (int) env('TWO_FACTOR_GRACE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform Branding
    |--------------------------------------------------------------------------
    |
    | These values identify the company operating this installation of Orbital
    | ("the platform operator"). They're surfaced in the admin UI, operator
    | desktop, customer portal, error messages, and outbound emails.
    |
    */
    'platform_name' => env('PLATFORM_NAME', 'Orbital'),
    'platform_company' => env('PLATFORM_COMPANY', 'Orbital'),
    'support_email' => env('PLATFORM_SUPPORT_EMAIL'),
    'support_message' => env('PLATFORM_SUPPORT_MESSAGE', 'Contact your platform administrator'),

    // Image paths (on the `s3` disk). Set via the Branding admin UI;
    // null when nothing's been uploaded yet. Dark/light are separate
    // so the UI can swap based on the viewer's color mode — if only
    // one is uploaded, the helper uses it in both modes.
    'platform_logo_light' => null,
    'platform_logo_dark' => null,
    'platform_favicon' => null,

    // Per-panel accent colors — names of public constants on
    // Filament's Color class (Indigo, Emerald, Sky, etc.).
    // Resolved at render time by \App\Support\Branding.
    'admin_primary_color' => env('ADMIN_PRIMARY_COLOR', 'Emerald'),
    'operator_primary_color' => env('OPERATOR_PRIMARY_COLOR', 'Indigo'),

    /*
    |--------------------------------------------------------------------------
    | Portal Branding
    |--------------------------------------------------------------------------
    |
    | Customer-facing identity shown on the client portal AND the shared
    | root /login page. Every user — staff or client — sees the portal
    | branding when they authenticate; post-login they may land on an
    | admin/operator panel that uses platform branding instead.
    |
    */
    'portal_name' => env('PORTAL_NAME', 'Customer Portal'),
    'portal_logo_light' => null,
    'portal_logo_dark' => null,
    'portal_favicon' => null,
    'portal_primary_color' => env('PORTAL_PRIMARY_COLOR', 'Rose'),

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Settings
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'default_llm_provider' => env('DEFAULT_LLM_PROVIDER', 'anthropic'),
        'default_llm_model' => env('DEFAULT_LLM_MODEL', 'claude-sonnet-4-20250514'),
        'default_stt_provider' => env('DEFAULT_STT_PROVIDER', 'elevenlabs'),
        'default_tts_provider' => env('DEFAULT_TTS_PROVIDER', 'elevenlabs'),

        'providers' => [
            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
            ],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'base_url' => env('OPENAI_BASE_URL'),
            ],
            'openrouter' => [
                'api_key' => env('OPENROUTER_API_KEY'),
            ],
            'elevenlabs' => [
                'api_key' => env('ELEVENLABS_API_KEY'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Worker
    |--------------------------------------------------------------------------
    */
    'agent_worker' => [
        'url' => env('AGENT_WORKER_URL', 'http://agent-worker:8089'),
        'token' => env('AGENT_WORKER_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff Softphone Extensions
    |--------------------------------------------------------------------------
    |
    | When a platform staff member is created, the system allocates them a
    | WebRTC SIP extension automatically from the configured range. The
    | credentials are surfaced on the staff user's edit page.
    |
    */
    'staff_extensions' => [
        'base' => (int) env('STAFF_EXTENSION_BASE', 2000),
        'max' => (int) env('STAFF_EXTENSION_MAX', 2999),
    ],

];
