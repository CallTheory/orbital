<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Asterisk AMI (Asterisk Manager Interface)
    |--------------------------------------------------------------------------
    */
    'asterisk' => [
        'ami' => [
            'host' => env('ASTERISK_AMI_HOST', '127.0.0.1'),
            'port' => (int) env('ASTERISK_AMI_PORT', 5038),
            'username' => env('ASTERISK_AMI_USERNAME', 'orbital'),
            'secret' => env('ASTERISK_AMI_SECRET'),
        ],

        'ari' => [
            'url' => env('ASTERISK_ARI_URL', 'http://127.0.0.1:8088'),
            'username' => env('ASTERISK_ARI_USERNAME', 'orbital'),
            'password' => env('ASTERISK_ARI_PASSWORD'),
        ],

        'sip_domain' => env('ASTERISK_SIP_DOMAIN', '127.0.0.1'),
        'wss_url' => env('ASTERISK_WSS_URL', 'wss://127.0.0.1:8089/ws'),

        // Path where generated configs are written (mounted into Asterisk container)
        'config_path' => env('ASTERISK_CONFIG_PATH', '/home/user/projects/orbital/docker/asterisk/config'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LiveKit
    |--------------------------------------------------------------------------
    |
    | Mode: "local" (self-hosted), "cloud" (LiveKit Cloud), or "failover"
    | (try local first, fall back to cloud).
    |
    */
    'livekit' => [
        'mode' => env('LIVEKIT_MODE', 'local'),

        'local' => [
            'url' => env('LIVEKIT_URL', 'http://livekit:7880'),
            'ws_url' => env('LIVEKIT_WS_URL', 'ws://livekit:7880'),
            'api_key' => env('LIVEKIT_API_KEY'),
            'api_secret' => env('LIVEKIT_API_SECRET'),
        ],

        'cloud' => [
            'url' => env('LIVEKIT_CLOUD_URL'),
            'api_key' => env('LIVEKIT_CLOUD_API_KEY'),
            'api_secret' => env('LIVEKIT_CLOUD_API_SECRET'),
        ],

        'sip' => [
            'port' => (int) env('LIVEKIT_SIP_PORT', 5069),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Codecs
    |--------------------------------------------------------------------------
    */
    'default_codecs' => ['ulaw', 'alaw', 'g722', 'opus', 'gsm'],

    /*
    |--------------------------------------------------------------------------
    | Call Recording
    |--------------------------------------------------------------------------
    |
    | Platform-wide defaults for call recording. Individual tenants can
    | override any of these via `teams.recording_overrides` JSON, and
    | individual extensions can force recording on/off via
    | `extensions.recording_mode`. See CallRecordingService for the
    | resolution order: extension → tenant → platform default.
    |
    */
    'recording' => [
        // Master toggle — disables all recording everywhere when false.
        'enabled' => env('RECORDING_ENABLED', true),

        // Output format: wav (lossless, larger) or mp3 (smaller).
        // Asterisk MixMonitor picks the encoder from the file extension.
        'format' => env('RECORDING_FORMAT', 'wav'),

        // How long recordings are kept before the prune job deletes
        // them. 0 = keep forever.
        'retention_days' => (int) env('RECORDING_RETENTION_DAYS', 90),

        // Laravel filesystem disk recordings are uploaded to. Any
        // S3-compatible disk works; local dev uses MinIO.
        'storage_disk' => env('RECORDING_STORAGE_DISK', 's3'),

        // Whether Asterisk plays a beep to the caller at the START of
        // the recording. Required in some jurisdictions.
        'beep_on_record' => (bool) env('RECORDING_BEEP', false),

        // Seconds between repeated notification beeps DURING an active
        // recording. Some jurisdictions (e.g. parts of California,
        // Germany, Switzerland) require a periodic audible reminder
        // while a call is being recorded. 0 = no periodic beep.
        'beep_interval_seconds' => (int) env('RECORDING_BEEP_INTERVAL', 0),

        // Optional TTS disclosure message played to the caller at the
        // very start of a recorded call — e.g. "This call may be
        // monitored or recorded for quality assurance purposes."
        // Empty string = no disclosure played. Tenants may override.
        'disclosure_message' => env('RECORDING_DISCLOSURE_MESSAGE', ''),
    ],

];
