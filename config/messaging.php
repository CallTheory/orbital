<?php

declare(strict_types=1);

use App\Services\Messaging\Providers\LogProvider;
use App\Services\Messaging\Providers\TwilioProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Messaging drivers
    |--------------------------------------------------------------------------
    |
    | Transports that can carry text-shaped traffic. The key is what's
    | stored on messaging_endpoints.provider and what appears in the
    | inbound webhook URL: POST /api/messaging/inbound/{provider}.
    |
    | Listed explicitly rather than auto-discovered. This map decides
    | which code handles a PUBLIC, UNAUTHENTICATED request — the carrier
    | has to be able to reach it — so the set of reachable classes
    | should be greppable in one place.
    |
    | Adding a transport (Telnyx, Bandwidth, an SMPP binding, a WCTP
    | gateway) means implementing MessagingProvider and adding a line
    | here. Nothing in the routing pipeline changes.
    |
    */
    'drivers' => [
        'twilio' => TwilioProvider::class,
        'log' => LogProvider::class,
    ],

    'labels' => [
        'twilio' => 'Twilio',
        'log' => 'Log (development)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider credentials
    |--------------------------------------------------------------------------
    |
    | Platform-wide defaults. Individual endpoints can override any of
    | these through their encrypted `provider_config` — which is how one
    | installation serves clients who bring their own carrier accounts.
    |
    */
    'providers' => [

        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'base_url' => env('TWILIO_BASE_URL', 'https://api.twilio.com/2010-04-01'),
            'timeout' => (int) env('TWILIO_TIMEOUT', 10),

            // Hosts we will attach the account's basic-auth credentials
            // to when downloading MMS media. The URL comes out of a
            // signature-verified webhook so this is defence in depth,
            // but it is the layer that matters if the auth token ever
            // leaks: without it, a forged webhook turns the media
            // fetcher into an SSRF proxy that helpfully authenticates
            // to whatever host it is pointed at.
            'media_hosts' => array_filter(array_map(
                'trim',
                explode(',', (string) env('TWILIO_MEDIA_HOSTS', 'api.twilio.com')),
            )),
        ],

        'log' => [
            // Shared secret for the dev provider's webhook. No default:
            // a dev-only transport that fails open is exactly the thing
            // that gets left enabled on a staging box with a real DNS
            // name. Unset means the endpoint rejects everything.
            'secret' => env('MESSAGING_LOG_SECRET'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Threading
    |--------------------------------------------------------------------------
    |
    | SMS has no Message-ID chain, so a thread is identified by
    | (endpoint, remote address). The open question is how long after a
    | conversation closes a new message should reopen it rather than
    | start fresh.
    |
    | Three days is a compromise: long enough that "sorry, one more
    | thing" the next morning stays in context, short enough that an
    | unrelated call six weeks later doesn't get stapled onto a resolved
    | complaint. Raise it for clients with long-running cases.
    |
    */
    'thread_reopen_hours' => (int) env('MESSAGING_THREAD_REOPEN_HOURS', 72),

    /*
    |--------------------------------------------------------------------------
    | Inbound limits
    |--------------------------------------------------------------------------
    |
    | The webhook is public. Signature verification is the real defence,
    | but a rate limit bounds the damage from a provider malfunctioning
    | or an attacker with a leaked secret, and keeps a message flood off
    | the Horizon queue that telephony shares.
    |
    */
    'inbound_rate_limit' => (int) env('MESSAGING_INBOUND_RATE_LIMIT', 300),

    /*
    |--------------------------------------------------------------------------
    | Auto-reply
    |--------------------------------------------------------------------------
    |
    | When a queue has an orchestration and an overflow persona, the AI
    | can answer inbound messages directly. Off by default: an AI that
    | starts texting a client's customers without the client having
    | asked for it is a much worse failure than a slow human reply.
    |
    */
    'auto_reply_enabled' => (bool) env('MESSAGING_AUTO_REPLY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | MMS media
    |--------------------------------------------------------------------------
    |
    | Inbound attachments arrive as provider URLs, which are not a copy
    | of anything: they expire on the carrier's schedule, they need the
    | carrier's credentials to read, and they disappear with the
    | account. FetchMessageMediaJob pulls the bytes into our own object
    | store the same way inbound email attachments are handled.
    |
    */
    'media' => [

        // Filesystem disk for stored attachments. Matches the email
        // attachment path; SeaweedFS presents an S3 API, so the `s3`
        // disk is the object store in every deployment shape we ship.
        'disk' => env('MESSAGING_MEDIA_DISK', 's3'),

        // Anything larger stays with the provider rather than being
        // stored. Twilio accepts 5MB and US carriers transcode well
        // below that, so this is a guard against a misbehaving
        // transport, not a normal limit.
        'max_bytes' => (int) env('MESSAGING_MEDIA_MAX_BYTES', 16 * 1024 * 1024),

        'timeout' => (int) env('MESSAGING_MEDIA_TIMEOUT', 30),

        // How long a signed attachment link stays valid. Short: an MMS
        // to an answering service is somebody's insurance photograph or
        // their prescription label, and a long-lived URL is a copy of
        // it that outlives the session that was allowed to see it.
        'link_ttl_minutes' => (int) env('MESSAGING_MEDIA_LINK_TTL', 15),

        // Delete the carrier's copy once ours is safely written.
        //
        // Off by default and deliberately so. Carriers retain MMS media
        // indefinitely and bill for it, and a customer's photograph
        // living on a third party's storage forever is a privacy
        // problem — both good reasons to turn this on. But it is
        // irreversible, and defaulting to destroying the only other
        // copy of a client's data on somebody else's installation is
        // not a decision this file gets to make.
        'delete_from_provider' => (bool) env('MESSAGING_MEDIA_DELETE_FROM_PROVIDER', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Opt-out
    |--------------------------------------------------------------------------
    |
    | Requiring a carrier sender pool means STOP is already enforced at
    | the carrier for the transports that have one. Orbital keeps its
    | own list anyway — see the migration for why — and this switch
    | exists only for the installation that has some other arrangement
    | it can point at. Turning it off does NOT make sending compliant;
    | it moves the responsibility somewhere this code can't see.
    |
    */
    'honour_opt_out_keywords' => (bool) env('MESSAGING_HONOUR_OPT_OUT', true),

];
