<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams email attachments from MinIO to the requesting user.
 *
 * Access rules:
 *   - super_admin: can fetch any attachment
 *   - platform staff (operator/supervisor roles): can fetch any
 *     attachment on any client's threads, because operators
 *     work the shared pool
 *   - client portal user: can only fetch attachments on
 *     messages belonging to a client they're attached to
 *     (Phase 5 surfacing — portal users don't see email yet,
 *     but the check is in place now so we don't forget)
 *
 * The file is streamed via `Storage::disk('s3')->readStream()`
 * so we don't pull the whole blob into PHP memory for large
 * attachments.
 */
class AttachmentDownloadController extends Controller
{
    public function download(Request $request, EmailAttachment $attachment): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->authorizeAccess($user, $attachment);

        abort_unless(
            Storage::disk('s3')->exists($attachment->storage_path),
            404,
            'Attachment blob missing in storage',
        );

        $filename = $attachment->filename ?: "attachment-{$attachment->id}";

        return Storage::disk('s3')->download(
            $attachment->storage_path,
            $filename,
            [
                'Content-Type' => $attachment->content_type ?: 'application/octet-stream',
            ],
        );
    }

    /**
     * Super-admin-only raw MIME viewer. Streams the original
     * RFC822 blob back as plain text so admins can debug
     * weird-looking messages (malformed headers, encoding
     * issues, bounce wrappers, etc.) by seeing exactly what
     * Haraka received.
     *
     * No client access check — super_admin is required
     * because raw MIME can contain headers that wouldn't
     * otherwise be surfaced in the UI.
     */
    public function rawMessage(Request $request, EmailMessage $emailMessage): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        abort_unless(
            method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(),
            403,
        );

        $raw = Storage::disk('s3')->get($emailMessage->raw_storage_path);
        abort_unless($raw !== null, 404, 'Raw MIME blob missing in storage');

        return response($raw, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Abort with 403 unless the user has access to the client
     * that owns this attachment.
     */
    private function authorizeAccess(User $user, EmailAttachment $attachment): void
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }

        // Platform operators / supervisors can work any client's
        // threads in the shared pool, so they can fetch any
        // attachment. This mirrors how they can see any thread
        // in the operator inbox.
        if (method_exists($user, 'hasAnyPlatformRole') && $user->hasAnyPlatformRole()) {
            return;
        }

        // Otherwise the user must belong to the client that owns
        // the attachment's message. Pull the team_id through the
        // message relation to avoid an N+1 on repeat downloads.
        $ownerTeamId = $attachment->message?->team_id;
        abort_unless($ownerTeamId !== null, 403);
        $team = Team::find($ownerTeamId);
        abort_unless($team && $user->belongsToTeam($team), 403);
    }
}
