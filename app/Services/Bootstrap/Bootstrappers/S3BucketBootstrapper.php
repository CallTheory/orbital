<?php

declare(strict_types=1);

namespace App\Services\Bootstrap\Bootstrappers;

use App\Services\Bootstrap\Bootstrapper;
use App\Services\Bootstrap\BootstrapReport;
use App\Services\Bootstrap\BootstrapStatus;
use Aws\S3\S3Client;
use Throwable;

/**
 * Verifies — and if necessary creates — the S3 buckets the platform
 * depends on. Works against any S3-compatible endpoint: SeaweedFS
 * (the default for local dev), AWS S3, Cloudflare R2, Backblaze B2,
 * Wasabi, etc. The client reads from Laravel's `s3` filesystem disk
 * config so no extra credentials plumbing is required.
 *
 *   orbital            — primary bucket for uploads / assets
 *   orbital-recordings — call recordings written by Asterisk
 *
 * The orbital bucket gets a public-read policy so avatars and other
 * static assets can be served directly without a signed URL.
 * Recordings stay private.
 *
 * Named `S3BucketBootstrapper` because the platform migrated off
 * MinIO (archived upstream Feb 2026) to SeaweedFS in dev, and this
 * class is agnostic about the backend — it only knows the S3 API.
 */
class S3BucketBootstrapper implements Bootstrapper
{
    protected const ASSET_BUCKET = 'orbital';
    protected const RECORDING_BUCKET = 'orbital-recordings';

    public function key(): string
    {
        return 's3_buckets';
    }

    public function name(): string
    {
        return 'Object storage (S3 buckets)';
    }

    public function description(): string
    {
        return 'Ensures the asset and recording buckets exist and are reachable.';
    }

    public function icon(): string
    {
        return 'heroicon-o-archive-box';
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function status(): BootstrapReport
    {
        try {
            $client = $this->client();
        } catch (Throwable $e) {
            return new BootstrapReport(
                status: BootstrapStatus::Missing,
                message: 'Object storage client could not be constructed: '.$e->getMessage(),
            );
        }

        $asset = $this->bucketExists($client, self::ASSET_BUCKET);
        $recording = $this->bucketExists($client, self::RECORDING_BUCKET);

        $steps = [
            ['label' => self::ASSET_BUCKET.' bucket', 'ok' => $asset, 'detail' => null],
            ['label' => self::RECORDING_BUCKET.' bucket', 'ok' => $recording, 'detail' => null],
        ];

        if ($asset && $recording) {
            return new BootstrapReport(BootstrapStatus::Installed, 'Both buckets present.', $steps);
        }
        if (! $asset && ! $recording) {
            return new BootstrapReport(BootstrapStatus::Missing, 'Neither bucket exists yet.', $steps);
        }
        return new BootstrapReport(BootstrapStatus::Partial, 'Some buckets are missing.', $steps);
    }

    public function install(): BootstrapReport
    {
        $client = $this->client();

        $steps = [];
        foreach ([self::ASSET_BUCKET, self::RECORDING_BUCKET] as $bucket) {
            $ok = true;
            $detail = null;
            try {
                if (! $this->bucketExists($client, $bucket)) {
                    $client->createBucket(['Bucket' => $bucket]);
                    $detail = 'Created.';
                } else {
                    $detail = 'Already present.';
                }
            } catch (Throwable $e) {
                $ok = false;
                $detail = $e->getMessage();
            }
            $steps[] = ['label' => $bucket.' bucket', 'ok' => $ok, 'detail' => $detail];
        }

        // Public read on the asset bucket so avatars / logos / etc can
        // be served directly without a signed URL. Recordings stay
        // private because they're client-sensitive.
        try {
            $client->putBucketPolicy([
                'Bucket' => self::ASSET_BUCKET,
                'Policy' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Sid' => 'PublicRead',
                        'Effect' => 'Allow',
                        'Principal' => '*',
                        'Action' => ['s3:GetObject'],
                        'Resource' => ['arn:aws:s3:::'.self::ASSET_BUCKET.'/*'],
                    ]],
                ]),
            ]);
            $steps[] = ['label' => self::ASSET_BUCKET.' public read policy', 'ok' => true, 'detail' => null];
        } catch (Throwable $e) {
            $steps[] = ['label' => self::ASSET_BUCKET.' public read policy', 'ok' => false, 'detail' => $e->getMessage()];
        }

        $allOk = true;
        foreach ($steps as $s) {
            if (! $s['ok']) {
                $allOk = false;
                break;
            }
        }

        return new BootstrapReport(
            status: $allOk ? BootstrapStatus::Installed : BootstrapStatus::Partial,
            message: $allOk ? 'Both buckets ready.' : 'Some bucket operations failed.',
            steps: $steps,
        );
    }

    protected function client(): S3Client
    {
        $config = config('filesystems.disks.s3');
        return new S3Client([
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
            'endpoint' => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
            'credentials' => [
                'key' => $config['key'] ?? '',
                'secret' => $config['secret'] ?? '',
            ],
        ]);
    }

    protected function bucketExists(S3Client $client, string $bucket): bool
    {
        try {
            $client->headBucket(['Bucket' => $bucket]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
