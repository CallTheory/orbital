<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Services\Metrics\PlatformMetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_endpoint_serves_the_prometheus_content_type(): void
    {
        $response = $this->get('/metrics');

        $response->assertOk();
        // Prometheus is strict about this header; getting it wrong means
        // a scrape that "succeeds" and stores nothing.
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $response->assertSee('orbital_build_info');
        $response->assertSee('# TYPE orbital_build_info gauge');
    }

    public function test_build_info_carries_version_and_license_labels(): void
    {
        config()->set('orbital.version', '7.7.7');

        $this->get('/metrics')
            ->assertOk()
            ->assertSee('version="7.7.7"', false)
            ->assertSee('license="AGPL-3.0-only"', false);
    }

    public function test_endpoint_can_be_disabled(): void
    {
        config()->set('metrics.enabled', false);

        $this->get('/metrics')->assertNotFound();
    }

    public function test_token_is_required_when_configured(): void
    {
        config()->set('metrics.token', 'sekrit');

        $this->get('/metrics')->assertUnauthorized();
        $this->withToken('wrong')->get('/metrics')->assertUnauthorized();
        $this->withToken('sekrit')->get('/metrics')->assertOk();
    }

    /**
     * The collector runs against a live deployment where any one
     * subsystem may be mid-failover. Each family is individually
     * guarded, so an empty database (and, here, no Valkey at all) must
     * still produce a usable payload rather than an exception.
     */
    public function test_platform_collector_degrades_instead_of_throwing(): void
    {
        $exposition = app(PlatformMetricsCollector::class)->collect();

        $rendered = $exposition->render();

        $this->assertStringContainsString('orbital_build_info', $rendered);
        $this->assertStringContainsString('orbital_clients_total', $rendered);
        $this->assertStringContainsString('orbital_calls_in_progress', $rendered);
    }
}
