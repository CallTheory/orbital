<?php

declare(strict_types=1);

namespace Tests\Unit\Metrics;

use App\Services\Metrics\Exposition;
use PHPUnit\Framework\TestCase;

/**
 * The exposition format is the contract with Prometheus, and Prometheus
 * fails a scrape loudly but only at runtime — an unescaped quote in a
 * client name would take out every metric in the payload, not just the
 * one line. These tests pin the escaping and ordering rules so that
 * can't happen quietly.
 */
class ExpositionTest extends TestCase
{
    public function test_renders_help_and_type_once_per_family(): void
    {
        $out = (new Exposition)
            ->gauge('orbital_thing', 1, ['a' => 'x'], 'A thing.')
            ->gauge('orbital_thing', 2, ['a' => 'y'])
            ->render();

        $this->assertSame(1, substr_count($out, '# HELP orbital_thing'));
        $this->assertSame(1, substr_count($out, '# TYPE orbital_thing gauge'));
        $this->assertStringContainsString('orbital_thing{a="x"} 1', $out);
        $this->assertStringContainsString('orbital_thing{a="y"} 2', $out);
    }

    public function test_help_can_be_supplied_on_a_later_sample(): void
    {
        // Collectors loop over rows and pass the same help text every
        // time; the first sample often has it, but a caller that only
        // sets it on a later one should still get a HELP line.
        $out = (new Exposition)
            ->gauge('orbital_thing', 1)
            ->gauge('orbital_thing', 2, [], 'Described late.')
            ->render();

        $this->assertStringContainsString('# HELP orbital_thing Described late.', $out);
    }

    public function test_escapes_label_values(): void
    {
        // Client names are admin-editable free text and end up in labels.
        $out = (new Exposition)
            ->gauge('orbital_thing', 1, ['client' => 'A "quoted" \\ name'."\n".'second line'], 'A thing.')
            ->render();

        $this->assertStringContainsString('client="A \"quoted\" \\\\ name\nsecond line"', $out);
        // The raw newline must not survive — it would terminate the
        // sample line early and corrupt everything after it. HELP, TYPE,
        // and one sample: exactly three newlines.
        $this->assertSame(3, substr_count($out, "\n"));
    }

    public function test_histogram_buckets_are_cumulative_and_ordered(): void
    {
        $out = (new Exposition)
            ->histogram(
                'orbital_duration_seconds',
                // Deliberately out of order — the class must sort them.
                // A list of pairs, not a bound-keyed map: PHP would cast
                // 0.1 and 0.5 to the same int key and silently drop a
                // bucket.
                [[1.0, 9], [0.1, 4], [0.5, 7]],
                sum: 3.5,
                count: 12,
                labels: ['surface' => 'admin'],
                help: 'Durations.',
            )
            ->render();

        $lines = array_values(array_filter(
            explode("\n", $out),
            fn (string $line): bool => str_contains($line, '_bucket'),
        ));

        $this->assertStringContainsString('le="0.1"', $lines[0]);
        $this->assertStringContainsString('le="0.5"', $lines[1]);
        $this->assertStringContainsString('le="1"', $lines[2]);
        $this->assertStringContainsString('le="+Inf"', $lines[3]);

        // +Inf must equal _count, or Prometheus reports a broken histogram.
        $this->assertStringContainsString('orbital_duration_seconds_bucket{surface="admin",le="+Inf"} 12', $out);
        $this->assertStringContainsString('orbital_duration_seconds_count{surface="admin"} 12', $out);
        $this->assertStringContainsString('orbital_duration_seconds_sum{surface="admin"} 3.5', $out);
    }

    public function test_formats_non_finite_values_the_way_prometheus_spells_them(): void
    {
        $out = (new Exposition)
            ->gauge('orbital_a', INF)
            ->gauge('orbital_b', -INF)
            ->gauge('orbital_c', NAN)
            ->render();

        $this->assertStringContainsString('orbital_a +Inf', $out);
        $this->assertStringContainsString('orbital_b -Inf', $out);
        $this->assertStringContainsString('orbital_c NaN', $out);
        // PHP's own spelling would be "INF", which Prometheus rejects.
        $this->assertStringNotContainsString('INF', $out);
    }

    public function test_avoids_scientific_notation_for_small_floats(): void
    {
        $out = (new Exposition)->gauge('orbital_tiny', 0.000_001)->render();

        $this->assertStringContainsString('orbital_tiny 0.000001', $out);
        $this->assertStringNotContainsString('E-', $out);
    }

    public function test_renders_nothing_when_empty(): void
    {
        $exposition = new Exposition;

        $this->assertTrue($exposition->isEmpty());
        $this->assertSame('', $exposition->render());
    }
}
