<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | HA keeps you up when a node dies. Replication keeps you up when a
    | disk dies. Neither helps when somebody drops a table, a migration
    | goes wrong, or an account is compromised — those replicate to every
    | copy instantly and faithfully. That is what this is for, and it is
    | the only one of the three that can recover from a mistake.
    |
    | Scope, stated plainly so nobody discovers it during a restore:
    | this backs up the DATABASE. Object storage content (call
    | recordings, MMS media, email attachments) is NOT copied — see
    | `docs/admin/backups.md`. The database is the part that cannot be
    | reconstructed from anything else and the part that fits in a
    | nightly window.
    |
    */
    'enabled' => (bool) env('BACKUP_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Destination
    |--------------------------------------------------------------------------
    |
    | A filesystem disk name. The shipped `backups` disk is S3-compatible
    | and reads its OWN credentials (BACKUP_AWS_*), deliberately separate
    | from the application's.
    |
    | That separation is the point. If backups live in the same bucket
    | under the same key the app uses every day, then the compromised
    | credential or the buggy delete that destroys your recordings
    | destroys the backups in the same breath. Give this key write and
    | list on its own bucket, and nothing else.
    |
    */
    'destination' => env('BACKUP_DESTINATION', 'backups'),

    // Prefix within the destination disk.
    'prefix' => env('BACKUP_PREFIX', 'orbital-backups'),

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | A database dump is the single most sensitive artifact this platform
    | produces: every caller name, every phone number, every message
    | body, and the encrypted platform settings. It does not leave the
    | host in the clear.
    |
    | Encryption is libsodium's secretstream (XChaCha20-Poly1305), which
    | is AUTHENTICATED — a truncated or altered archive fails to decrypt
    | rather than restoring silent garbage. That is also why there is no
    | separate checksum: tampering is detected by construction.
    |
    | The passphrase is stretched with sodium_crypto_pwhash and a random
    | per-archive salt, so a short one is expensive to attack rather than
    | instantly broken.
    |
    | LOSING THIS PASSPHRASE MEANS LOSING EVERY BACKUP TAKEN WITH IT.
    | There is no recovery path and that is not an oversight — a backup
    | system with a way in for us is a backup system with a way in for
    | somebody else. Store it somewhere that is not this platform.
    |
    */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Archives older than this are deleted from the destination after a
    | successful run. Pruning happens only AFTER the new archive has
    | uploaded, so a failing backup can never age out the last good one.
    |
    | 0 keeps everything forever.
    |
    */
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | pg_dump
    |--------------------------------------------------------------------------
    |
    | Custom format (-Fc): compressed, and restorable selectively with
    | pg_restore. Plain SQL would be simpler to eyeball and considerably
    | worse to restore from at 3am.
    |
    | Version rule: pg_dump must be the same major version as the server
    | or NEWER. A newer server than client is refused outright. The
    | images install alpine's current postgresql-client for that reason.
    |
    */
    'pg_dump_path' => env('BACKUP_PG_DUMP_PATH', 'pg_dump'),
    'pg_restore_path' => env('BACKUP_PG_RESTORE_PATH', 'pg_restore'),

    // Seconds. A large database on slow storage is not a failure.
    'timeout' => (int) env('BACKUP_TIMEOUT', 3600),

];
