<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Asterisk dialplan config files (extensions_generated.conf,
        // per-client includes, voicemail.conf, ...). Defaults to the
        // `local` driver pointing at the same bind-mounted path Sail
        // has always used; flip ASTERISK_STORAGE_DRIVER=s3 in
        // Kubernetes so the Horizon pod hands config off to the
        // Asterisk pod through SeaweedFS instead of a shared RWX
        // volume that doesn't exist there.
        'asterisk-config' => env('ASTERISK_STORAGE_DRIVER', 'local') === 's3'
            ? [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true),
                'root' => env('ASTERISK_CONFIG_PREFIX', 'asterisk/config'),
                'throw' => true,
                'report' => false,
            ]
            : [
                'driver' => 'local',
                'root' => env('ASTERISK_CONFIG_PATH', base_path('docker/asterisk/config')),
                'throw' => true,
                'report' => false,
            ],

        // Rendered TTS prompt WAVs (disclosures, voicemail greetings)
        // that Asterisk plays back at call time via absolute
        // /var/spool/asterisk/prompts/... paths. Same driver flip as
        // `asterisk-config` above — local bind mount on Sail, S3 in
        // Kubernetes.
        'asterisk-prompts' => env('ASTERISK_STORAGE_DRIVER', 'local') === 's3'
            ? [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true),
                'root' => env('ASTERISK_PROMPTS_PREFIX', 'asterisk/prompts'),
                'throw' => true,
                'report' => false,
            ]
            : [
                'driver' => 'local',
                'root' => env('ASTERISK_PROMPTS_PATH', '/var/spool/asterisk/prompts'),
                'throw' => true,
                'report' => false,
            ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
