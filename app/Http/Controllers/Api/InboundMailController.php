<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\EmailMessage;
use App\Models\Team;
use App\Services\Mail\LocalPartParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Entry point for the Haraka → Laravel inbound-mail webhook.
 *
 * Haraka's `orbital-webhook` plugin POSTs `multipart/form-data` at
 * end-of-DATA with:
 *   - `raw`          — the full RFC822 message as a file upload
 *   - `envelope_from` — the MAIL FROM address
 *   - `envelope_to[]` — the RCPT TO addresses (array)
 *
 * This endpoint does the absolute minimum work to free Haraka's
 * SMTP connection quickly: save the raw blob to MinIO, create a
 * stub `EmailMessage` row with `routing_status=pending`, and
 * dispatch `ProcessInboundEmailJob` onto the dedicated
 * `inbound-mail` queue. All parsing, header extraction, tenant
 * routing, threading, and attachment storage happens in the job.
 *
 * Auth is the shared-secret bearer token in
 * `services.inbound_mail.token`, enforced by
 * `VerifyInboundMailToken` middleware.
 */
class InboundMailController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'raw' => ['required', 'file'],
            'envelope_from' => ['nullable', 'string'],
            'envelope_to' => ['nullable', 'array'],
            'envelope_to.*' => ['string'],
        ]);

        // Persist raw RFC822 to MinIO first so the job always has
        // something to parse, even if the DB insert fails. Using a
        // ULID key gives us sortability + no collision risk.
        $ulid = (string) Str::ulid();
        $storagePath = "mail-raw/{$ulid}.eml";
        Storage::disk('s3')->put(
            $storagePath,
            file_get_contents($request->file('raw')->getRealPath()),
        );

        // Stub row — the job fills in headers, bodies, team_id,
        // thread_id, and flips routing_status when it processes.
        $message = EmailMessage::create([
            'team_id' => null,
            'direction' => 'inbound',
            'raw_storage_path' => $storagePath,
            'received_at' => now(),
            'routing_status' => 'pending',
            'metadata' => [
                'envelope_from' => $request->input('envelope_from'),
                'envelope_to' => $request->input('envelope_to', []),
            ],
        ]);

        Log::info('inbound mail accepted', [
            'id' => $message->id,
            'storage_path' => $storagePath,
            'envelope_to' => $request->input('envelope_to', []),
        ]);

        // Dispatch onto the dedicated inbound-mail queue so a
        // flood of email doesn't starve the telephony queues.
        ProcessInboundEmailJob::dispatch($message->id)->onQueue('inbound-mail');

        return response()->json([
            'accepted' => true,
            'id' => $message->id,
        ], 202);
    }

    /**
     * RCPT-TO validation endpoint. Haraka's plugin calls this
     * during the SMTP conversation to decide whether to accept a
     * recipient before the sender DATA's the message body. If
     * the address doesn't resolve to a known tenant, we 404 and
     * Haraka responds 550 to the sender — we never even see the
     * payload.
     *
     * Input:  `?address={local-part}@{domain}`
     * Output: 200 {"ok":true,"team_id":N} on known tenants
     *         404 {"ok":false} on unknown account_number
     *
     * Results are cached for 60 seconds per address so a sender
     * retrying a burst of recipients doesn't hammer the DB.
     */
    public function validateRecipient(Request $request, LocalPartParser $parser): JsonResponse
    {
        $address = (string) $request->query('address', '');
        if ($address === '') {
            return response()->json(['ok' => false, 'reason' => 'no address'], 400);
        }

        $cacheKey = 'inbound_mail:rcpt:'.strtolower($address);
        $result = Cache::remember($cacheKey, 60, function () use ($parser, $address) {
            $parts = $parser->parse($address);
            if ($parts['account_number'] === null) {
                return ['ok' => false, 'team_id' => null];
            }
            $team = Team::query()
                ->where('account_number', $parts['account_number'])
                ->where('personal_team', false)
                ->first();
            return $team
                ? ['ok' => true, 'team_id' => $team->id]
                : ['ok' => false, 'team_id' => null];
        });

        return response()->json($result, $result['ok'] ? 200 : 404);
    }
}
