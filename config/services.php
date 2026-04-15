<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'agent_worker' => [
        'url' => env('AGENT_WORKER_URL'),
        'token' => env('AGENT_WORKER_TOKEN'),
    ],

    /*
     * Shared secret between the Haraka SMTP shim and the
     * `/api/mail/inbound` webhook. Haraka sends it as
     * `Authorization: Bearer {token}`. Rotate by setting
     * INBOUND_MAIL_TOKEN in both the Laravel .env and the
     * haraka service env in docker-compose.yml.
     */
    'inbound_mail' => [
        'token' => env('INBOUND_MAIL_TOKEN'),
        'domain' => env('INBOUND_MAIL_DOMAIN', 'inbound.orbital.test'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
    ],

    'deepgram' => [
        'api_key' => env('DEEPGRAM_API_KEY'),
    ],

    'cartesia' => [
        'api_key' => env('CARTESIA_API_KEY'),
    ],

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://ollama:11434'),
    ],

    'embeddings' => [
        // Default provider for new knowledge stores. Individual stores
        // can override this by setting their own embedding_model string.
        // Format: "provider:model" — e.g. "openai:text-embedding-3-small"
        // or "ollama:nomic-embed-text".
        'default_provider' => env('EMBEDDINGS_DEFAULT_PROVIDER', 'openai:text-embedding-3-small'),
    ],

    // Unified SSO — all four control-panel auth paths read their
    // config here. Values come from .env, populated by the
    // `SsoSecretsBootstrapper` on first install and rotated on
    // re-run. See app/Services/Bootstrap/Bootstrappers/SsoSecretsBootstrapper.php.
    'redis_commander' => [
        'sso_secret' => env('REDIS_COMMANDER_SSO_SECRET'),
        'sso_issuer' => env('REDIS_COMMANDER_SSO_ISSUER', 'orbital-admin'),
        'public_port' => env('FORWARD_REDIS_COMMANDER_PORT', 8082),
    ],
    'grafana' => [
        // In-network URL the Laravel reverse proxy forwards to.
        // Uses the compose service name so traffic stays on the
        // sail network.
        'internal_url' => env('GRAFANA_INTERNAL_URL', 'http://grafana:3000'),
        // Shared-secret header value sent by the Laravel proxy on
        // every forwarded request. Validated by a prod sidecar in
        // front of Grafana (see plan for how).
        'proxy_trust_token' => env('GRAFANA_PROXY_TRUST_TOKEN'),
    ],
    'icecast' => [
        // In-network URL for the Icecast admin reverse-proxy.
        // Uses the compose service name so traffic stays on the
        // telephony network (Icecast is dual-homed for its source
        // connections too).
        'internal_url' => env('ICECAST_INTERNAL_URL', 'http://icecast:8000'),
        // Admin credentials injected as HTTP Basic Auth on every
        // proxied request. The username is hardcoded as `admin`
        // by the moul/icecast image and every other Icecast build
        // we've seen, but it's still env-overridable in case a
        // future image changes the convention.
        'admin_user' => env('ICECAST_ADMIN_USER', 'admin'),
        'admin_password' => env('ICECAST_ADMIN_PASSWORD', 'changeme'),
    ],
    'pgadmin' => [
        'oauth_client_id' => env('PGADMIN_OAUTH2_CLIENT_ID'),
        'oauth_client_secret' => env('PGADMIN_OAUTH2_CLIENT_SECRET'),
    ],
    'minio_console' => [
        'oidc_client_id' => env('MINIO_OIDC_CLIENT_ID'),
        'oidc_client_secret' => env('MINIO_OIDC_CLIENT_SECRET'),
    ],

];
