<?php

return [

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
