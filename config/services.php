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

];
