<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MessageEntry;
use App\Services\Messaging\ProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pulls MMS attachments off the carrier and into our own object store.
 *
 * Until this runs, `message_entries.media` holds nothing but the
 * provider's URLs — and those are not a copy of anything. They expire
 * on the carrier's schedule, they need the carrier's credentials to
 * read, and they vanish entirely when the account is closed or the
 * client changes vendor. A client who opens a six-month-old
 * conversation to find the photograph of the damage gone has lost the
 * part of the message that mattered.
 *
 * Same fetch-and-index shape as email attachments
 * (ProcessInboundEmailJob::storeAttachments), with the one difference
 * that the bytes come over HTTP with the provider's auth rather than
 * out of a parsed MIME part, so the download goes through the driver.
 *
 * Idempotent: an item that already carries a `storage_path` is skipped,
 * so a retry after a partial failure finishes the job rather than
 * duplicating what it already stored.
 */
class FetchMessageMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /**
     * Content types a browser will execute if it is allowed to render
     * them.
     *
     * These are stored with their bytes intact — it is the customer's
     * message and the client may need it — but relabelled
     * `application/octet-stream` AND given a neutral `.bin` extension,
     * so the object store hands them back as a download rather than as
     * a document. The store serves from a host we control, so an
     * `image/svg+xml` or `text/html` attachment rendered inline from
     * there is a script written by whoever texted the client, running
     * in our origin.
     *
     * A denylist rather than an allowlist on purpose: PDFs, vCards and
     * the other ordinary things people attach should keep their type
     * and open the way the recipient expects. Only the handful that
     * carry script need neutering. Carriers transcode almost everything
     * to JPEG, so in practice this fires on the pathological case and
     * nothing else.
     */
    private const SCRIPTABLE_TYPES = [
        'text/html',
        'text/xml',
        'text/javascript',
        'application/xhtml+xml',
        'application/xml',
        'application/javascript',
        'application/x-javascript',
        'application/ecmascript',
        'image/svg+xml',
    ];

    /**
     * Extensions that get the same treatment regardless of what the
     * carrier claimed the type was.
     *
     * Belt and braces: content-type sniffing is inconsistent across
     * carriers and across the libraries in between, and some
     * object-store configurations derive the served type from the key's
     * extension rather than from stored metadata. A `.svg` that ends up
     * served as SVG is the whole problem back again, so the extension
     * is neutered on its own evidence.
     */
    private const SCRIPTABLE_EXTENSIONS = [
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'js', 'mjs', 'jse', 'vbs',
    ];

    public function __construct(
        public int $entryId,
    ) {
        $this->onQueue('inbound-messages');
    }

    public function handle(ProviderRegistry $registry): void
    {
        $entry = MessageEntry::withoutGlobalScopes()
            ->with('thread.endpoint')
            ->find($this->entryId);

        if (! $entry) {
            return;
        }

        $media = (array) ($entry->media ?? []);

        if ($media === []) {
            return;
        }

        $providerKey = (string) ($entry->provider ?? '');

        if ($providerKey === '' || ! $registry->has($providerKey)) {
            Log::warning('message media fetch: unknown provider', [
                'entry_id' => $entry->id,
                'provider' => $providerKey,
            ]);

            return;
        }

        $provider = $registry->get($providerKey);
        $options = $entry->thread?->endpoint?->providerOptions() ?? [];

        $disk = (string) config('messaging.media.disk', 's3');
        $maxBytes = (int) config('messaging.media.max_bytes', 16 * 1024 * 1024);
        $deleteAfter = (bool) config('messaging.media.delete_from_provider', false);

        $changed = false;

        foreach ($media as $index => $item) {
            $item = (array) $item;
            $url = (string) ($item['url'] ?? '');

            if ($url === '' || ! empty($item['storage_path'])) {
                continue;
            }

            $fetched = $provider->fetchMedia($url, $options);

            if (! $fetched) {
                // Leave the provider URL in place. A missing local copy
                // is a degraded attachment, not a lost message, and the
                // operator can still open the carrier's link while the
                // credential or network problem is sorted out.
                continue;
            }

            if ($fetched->size() > $maxBytes) {
                Log::warning('message media too large, left with the provider', [
                    'entry_id' => $entry->id,
                    'bytes' => $fetched->size(),
                    'limit' => $maxBytes,
                ]);

                continue;
            }

            $contentType = $fetched->contentType ?: ($item['content_type'] ?? null);
            $filename = $fetched->filename ?: ($item['filename'] ?? null);

            // Both the fetched type and whatever the carrier originally
            // declared count as evidence — they disagree more often
            // than you would hope, and the dangerous answer wins.
            $scriptable = $this->isScriptable(
                [$contentType, $item['content_type'] ?? null],
                $filename,
            );

            $path = $this->storagePath($entry, $index, $filename, $contentType, $scriptable);

            try {
                Storage::disk($disk)->put($path, $fetched->contents, [
                    'ContentType' => $scriptable ? 'application/octet-stream' : $this->safeContentType($contentType),
                ]);
            } catch (\Throwable $e) {
                Log::error('message media store failed', [
                    'entry_id' => $entry->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            $media[$index] = array_merge($item, [
                'url' => $url,
                'content_type' => $contentType,
                'storage_path' => $path,
                'storage_disk' => $disk,
                'size' => $fetched->size(),
                'filename' => $filename ?: basename($path),
                'fetched_at' => now()->toIso8601String(),
            ]);

            $changed = true;

            // Only ever after our own copy is safely written, and only
            // when the operator asked for it.
            if ($deleteAfter && $provider->deleteMedia($url, $options)) {
                $media[$index]['url'] = null;
                $media[$index]['provider_deleted'] = true;
            }
        }

        if (! $changed) {
            return;
        }

        $entry->forceFill(['media' => array_values($media)])->save();

        Log::info('message media stored', [
            'entry_id' => $entry->id,
            'count' => count(array_filter($media, fn ($m) => ! empty($m['storage_path']))),
        ]);
    }

    /**
     * Per-thread, per-entry prefix so a client's media is contiguous in
     * the bucket and a thread's lifecycle rules can act on one prefix.
     */
    private function storagePath(
        MessageEntry $entry,
        int $index,
        ?string $filename,
        ?string $contentType,
        bool $scriptable,
    ): string {
        if ($scriptable) {
            $extension = 'bin';
        } else {
            $extension = $filename ? pathinfo($filename, PATHINFO_EXTENSION) : '';

            if ($extension === '') {
                $extension = $this->extensionFor($contentType);
            }
        }

        return sprintf(
            'message-media/%d/%d/%s-%d.%s',
            $entry->message_thread_id,
            $entry->id,
            (string) Str::ulid(),
            $index,
            preg_replace('/[^a-z0-9]+/i', '', $extension) ?: 'bin',
        );
    }

    private function extensionFor(?string $contentType): string
    {
        return match (strtolower(explode(';', (string) $contentType)[0])) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/mpeg' => 'mp3',
            'audio/amr' => 'amr',
            'application/pdf' => 'pdf',
            'text/vcard', 'text/x-vcard' => 'vcf',
            default => 'bin',
        };
    }

    /**
     * The content type we store the object under — see
     * SCRIPTABLE_TYPES.
     */
    private function safeContentType(?string $contentType): string
    {
        $type = $this->normaliseType($contentType);

        return $type === '' ? 'application/octet-stream' : $type;
    }

    /**
     * @param  array<int, ?string>  $contentTypes  every type claimed for
     *                                             this attachment
     */
    private function isScriptable(array $contentTypes, ?string $filename): bool
    {
        foreach ($contentTypes as $contentType) {
            if (in_array($this->normaliseType($contentType), self::SCRIPTABLE_TYPES, true)) {
                return true;
            }
        }

        $extension = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));

        return in_array($extension, self::SCRIPTABLE_EXTENSIONS, true);
    }

    private function normaliseType(?string $contentType): string
    {
        return strtolower(trim(explode(';', (string) $contentType)[0]));
    }
}
