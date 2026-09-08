<?php

declare(strict_types=1);

namespace App\Services\Metrics;

/**
 * Builder for the Prometheus text exposition format (version 0.0.4).
 *
 * Why hand-rolled instead of a client library: every metric Orbital
 * publishes is either read from a shared store at scrape time (Postgres,
 * Valkey, Horizon) or pushed by a single scheduled writer. Nothing
 * accumulates in PHP process memory, so the hard part a client library
 * solves — aggregating counters across PHP-FPM workers — is a problem we
 * don't have. What's left is string formatting, and the format is two
 * pages of spec.
 *
 * The format itself:
 *
 *     # HELP metric_name Some description.
 *     # TYPE metric_name gauge
 *     metric_name{label="value"} 1.23
 *
 * Rules this class enforces so we can't emit something Prometheus will
 * reject or silently mis-parse:
 *
 *   - HELP/TYPE emitted once per family, before its samples
 *   - label values escaped for backslash, double-quote, and newline
 *   - non-finite floats rendered as the literal Prometheus spellings
 *     (+Inf / -Inf / NaN) rather than PHP's "INF"
 *   - families kept in insertion order so diffs of scraped output are
 *     readable by a human
 */
final class Exposition
{
    /** @var array<string, array{type: string, help: string, samples: array<int, string>}> */
    private array $families = [];

    public function gauge(string $name, float|int $value, array $labels = [], string $help = ''): self
    {
        return $this->sample($name, 'gauge', $help, $name, $value, $labels);
    }

    public function counter(string $name, float|int $value, array $labels = [], string $help = ''): self
    {
        return $this->sample($name, 'counter', $help, $name, $value, $labels);
    }

    /**
     * A histogram family: cumulative bucket counts, plus _sum and _count.
     *
     * Buckets are a LIST of [upperBound, cumulativeCount] pairs, not a
     * bound-keyed map. PHP silently casts float array keys to int, so
     * `[0.1 => 4, 0.5 => 7]` would collapse both into key 0 and lose a
     * bucket — a bug that produces a plausible-looking but wrong
     * histogram. A list of pairs can't be mangled that way.
     *
     * @param  array<int, array{0: float, 1: int}>  $buckets
     */
    public function histogram(
        string $name,
        array $buckets,
        float $sum,
        int $count,
        array $labels = [],
        string $help = '',
    ): self {
        $this->declare($name, 'histogram', $help);

        // Prometheus requires buckets in ascending "le" order, with +Inf
        // last and equal to _count. Sorting here means callers can build
        // the list in whatever order is convenient.
        usort($buckets, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($buckets as [$bound, $bucketCount]) {
            $this->families[$name]['samples'][] = $this->line(
                $name.'_bucket',
                $bucketCount,
                $labels + ['le' => self::formatBound((float) $bound)],
            );
        }

        $this->families[$name]['samples'][] = $this->line(
            $name.'_bucket',
            $count,
            $labels + ['le' => '+Inf'],
        );
        $this->families[$name]['samples'][] = $this->line($name.'_sum', $sum, $labels);
        $this->families[$name]['samples'][] = $this->line($name.'_count', $count, $labels);

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->families === [];
    }

    /**
     * Render the full exposition. Always ends with a newline — some
     * scrapers are unhappy without the trailing one.
     */
    public function render(): string
    {
        $out = '';

        foreach ($this->families as $name => $family) {
            if ($family['help'] !== '') {
                $out .= '# HELP '.$name.' '.self::escapeHelp($family['help'])."\n";
            }
            $out .= '# TYPE '.$name.' '.$family['type']."\n";
            $out .= implode("\n", $family['samples'])."\n";
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->render();
    }

    private function sample(
        string $family,
        string $type,
        string $help,
        string $name,
        float|int $value,
        array $labels,
    ): self {
        $this->declare($family, $type, $help);
        $this->families[$family]['samples'][] = $this->line($name, $value, $labels);

        return $this;
    }

    private function declare(string $name, string $type, string $help): void
    {
        if (! isset($this->families[$name])) {
            $this->families[$name] = ['type' => $type, 'help' => $help, 'samples' => []];

            return;
        }

        // First non-empty help wins, so a caller adding a second sample
        // to a family doesn't have to repeat the description.
        if ($this->families[$name]['help'] === '' && $help !== '') {
            $this->families[$name]['help'] = $help;
        }
    }

    /**
     * @param  array<string, string|int|float>  $labels
     */
    private function line(string $name, float|int $value, array $labels): string
    {
        return $name.self::renderLabels($labels).' '.self::formatValue($value);
    }

    /**
     * @param  array<string, string|int|float>  $labels
     */
    private static function renderLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $key => $value) {
            $pairs[] = $key.'="'.self::escapeLabelValue((string) $value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private static function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    private static function escapeHelp(string $value): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $value);
    }

    private static function formatValue(float|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        // Fixed precision rather than PHP's default float formatting,
        // which can emit scientific notation that older Prometheus
        // parsers handle inconsistently.
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }

    private static function formatBound(float $bound): string
    {
        return is_infinite($bound) ? '+Inf' : self::formatValue($bound);
    }
}
