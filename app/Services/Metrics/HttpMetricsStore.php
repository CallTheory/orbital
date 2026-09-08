<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use Illuminate\Support\Facades\Redis;

/**
 * Valkey-backed accumulator for per-instance HTTP metrics.
 *
 * PHP-FPM gives every request a fresh process memory space, so an
 * in-process counter would reset constantly and report nonsense. The
 * counters therefore live in Valkey, in one hash per app instance:
 *
 *   orbital:metrics:http:{instance}
 *     c|{surface}|{method}|{class}   request count
 *     b|{surface}|{bucketIndex}      requests at or below that bucket bound
 *     s|{surface}                    total duration, seconds
 *     n|{surface}                    total requests (histogram _count)
 *
 * Keyed per instance rather than globally so that scraping two app
 * replicas yields two honest series instead of both reporting the same
 * shared total. That also makes "is one replica serving all the traffic"
 * a question the dashboard can answer.
 *
 * Every method here swallows its own errors. Metrics are diagnostics; a
 * Valkey blip must never turn into a failed request or a 500 on the
 * scrape endpoint.
 */
class HttpMetricsStore
{
    private const KEY_PREFIX = 'orbital:metrics:http:';

    public function __construct(
        private readonly string $instance,
    ) {}

    public static function forCurrentInstance(): self
    {
        return new self((string) config('metrics.instance', 'unknown'));
    }

    /**
     * Record one completed request.
     *
     * @param  string  $surface  admin|operator|portal|api|web
     * @param  float  $durationSeconds  wall time from request start
     */
    public function record(string $surface, string $method, int $status, float $durationSeconds): void
    {
        try {
            $key = self::KEY_PREFIX.$this->instance;
            $class = self::statusClass($status);
            $bucketIndex = $this->bucketIndexFor($durationSeconds);

            $connection = Redis::connection();

            $connection->pipeline(function ($pipe) use ($key, $surface, $method, $class, $bucketIndex, $durationSeconds): void {
                $pipe->hincrby($key, "c|{$surface}|{$method}|{$class}", 1);
                $pipe->hincrby($key, "n|{$surface}", 1);
                $pipe->hincrbyfloat($key, "s|{$surface}", $durationSeconds);

                // Only the matching bucket is incremented; the cumulative
                // (`le`) shape Prometheus wants is computed on read. One
                // HINCRBY per request instead of one per bucket bound.
                if ($bucketIndex !== null) {
                    $pipe->hincrby($key, "b|{$surface}|{$bucketIndex}", 1);
                }

                $pipe->expire($key, max(1, (int) config('metrics.http.ttl_hours', 48)) * 3600);
            });
        } catch (\Throwable) {
            // Diagnostics must not be able to break the thing they measure.
        }
    }

    /**
     * Add every recorded HTTP family for this instance to the exposition.
     */
    public function addTo(Exposition $exposition): void
    {
        try {
            $raw = Redis::connection()->hgetall(self::KEY_PREFIX.$this->instance);
        } catch (\Throwable) {
            return;
        }

        if (! is_array($raw) || $raw === []) {
            return;
        }

        $counters = [];
        $sums = [];
        $counts = [];
        $buckets = [];

        foreach ($raw as $field => $value) {
            $parts = explode('|', (string) $field);
            $kind = array_shift($parts);

            match ($kind) {
                'c' => $counters[] = [$parts, (int) $value],
                's' => $sums[$parts[0] ?? ''] = (float) $value,
                'n' => $counts[$parts[0] ?? ''] = (int) $value,
                'b' => $buckets[$parts[0] ?? ''][(int) ($parts[1] ?? 0)] = (int) $value,
                default => null,
            };
        }

        foreach ($counters as [$labels, $value]) {
            [$surface, $method, $class] = array_pad($labels, 3, 'unknown');

            $exposition->counter(
                'orbital_http_requests_total',
                $value,
                [
                    'instance_name' => $this->instance,
                    'surface' => $surface,
                    'method' => $method,
                    'status_class' => $class,
                ],
                'Total HTTP requests served by this Orbital instance.',
            );
        }

        $bounds = self::bounds();

        foreach ($counts as $surface => $count) {
            $cumulative = 0;
            $family = [];

            foreach ($bounds as $index => $bound) {
                $cumulative += $buckets[$surface][$index] ?? 0;
                $family[] = [$bound, $cumulative];
            }

            $exposition->histogram(
                'orbital_http_request_duration_seconds',
                $family,
                $sums[$surface] ?? 0.0,
                $count,
                [
                    'instance_name' => $this->instance,
                    'surface' => $surface,
                ],
                'HTTP request duration served by this Orbital instance.',
            );
        }
    }

    /**
     * Which bucket a duration falls into, or null when it exceeds every
     * bound (those requests still land in the +Inf bucket via _count).
     */
    private function bucketIndexFor(float $seconds): ?int
    {
        foreach (self::bounds() as $index => $bound) {
            if ($seconds <= $bound) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<int, float>
     */
    private static function bounds(): array
    {
        /** @var array<int, float> $bounds */
        $bounds = array_values(array_map(
            static fn ($b): float => (float) $b,
            (array) config('metrics.http.buckets', [0.1, 0.5, 1.0, 5.0]),
        ));

        sort($bounds);

        return $bounds;
    }

    private static function statusClass(int $status): string
    {
        return match (true) {
            $status >= 500 => '5xx',
            $status >= 400 => '4xx',
            $status >= 300 => '3xx',
            $status >= 200 => '2xx',
            default => '1xx',
        };
    }
}
