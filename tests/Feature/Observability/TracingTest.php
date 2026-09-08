<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Services\Observability\Tracer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use RuntimeException;
use Tests\TestCase;

/**
 * Distributed tracing over OTLP.
 *
 * The properties worth pinning are the same two as error reporting, plus
 * one this pipeline has and that one does not.
 *
 * Off has to cost nothing — no exporter, no listeners on the query path,
 * which runs on every request of every install that will never turn this
 * on.
 *
 * On has to be safe. Spans carry identifiers, never content. The
 * dangerous one is the database seam: Laravel hands the listener both
 * the statement and its bindings, and the bindings are the caller's
 * phone number and the body of somebody's text.
 *
 * And tracing must never break the request it is observing. An
 * unreachable collector or a bad endpoint is a monitoring problem; a
 * 500 caused by a monitoring problem is an outage.
 */
class TracingTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exporter = new InMemoryExporter;
    }

    // ── Off by default ──────────────────────────────────────────────

    public function test_it_is_off_out_of_the_box(): void
    {
        $this->assertFalse(config('observability.tracing.enabled'));
        $this->assertFalse(app(Tracer::class)->enabled());
    }

    public function test_an_enabled_toggle_with_no_endpoint_is_still_off(): void
    {
        config([
            'observability.tracing.enabled' => true,
            'observability.tracing.endpoint' => '',
        ]);

        $this->assertFalse(app(Tracer::class)->enabled());
    }

    public function test_nothing_is_recorded_while_tracing_is_off(): void
    {
        $tracer = app(Tracer::class);
        $tracer->useExporter($this->exporter);

        $tracer->startRoot('GET /operator');
        $tracer->record('db SELECT', 0.01);
        $tracer->endRoot();

        $this->assertSame([], $this->exporter->getSpans());
        $this->assertFalse($tracer->hasActiveTrace());
    }

    // ── Recording a trace ───────────────────────────────────────────

    public function test_a_root_span_is_exported_with_its_attributes(): void
    {
        $tracer = $this->tracer();

        $tracer->startRoot('GET /operator/message-inbox', ['orbital.surface' => 'operator']);
        $tracer->endRoot(['http.response.status_code' => 200]);

        $span = $this->onlySpan();

        $this->assertSame('GET /operator/message-inbox', $span->getName());
        $this->assertSame('operator', $span->getAttributes()->get('orbital.surface'));
        $this->assertSame(200, $span->getAttributes()->get('http.response.status_code'));
    }

    public function test_child_spans_are_parented_to_the_root(): void
    {
        $tracer = $this->tracer();

        $tracer->startRoot('GET /operator/message-inbox');
        $tracer->record('db SELECT', 0.004);
        $tracer->endRoot();

        $spans = $this->exporter->getSpans();
        $this->assertCount(2, $spans);

        [$child, $root] = [$this->spanNamed('db SELECT'), $this->spanNamed('GET /operator/message-inbox')];

        // One trace, not two. A child that starts its own trace is worse
        // than no child: the parent appears to have done nothing.
        $this->assertSame($root->getTraceId(), $child->getTraceId());
        $this->assertSame($root->getSpanId(), $child->getParentSpanId());
    }

    /**
     * The header is what turns two processes into one story — the Python
     * agent worker calling our API, or another service upstream. Without
     * it every inbound request starts an orphan trace.
     */
    public function test_an_inbound_traceparent_continues_the_callers_trace(): void
    {
        $tracer = $this->tracer();

        $traceId = '0af7651916cd43dd8448eb211c80319c';
        $parentSpanId = 'b7ad6b7169203331';

        $tracer->startRoot(
            'POST /api/agent-worker/heartbeat',
            traceparent: "00-{$traceId}-{$parentSpanId}-01",
        );
        $tracer->endRoot();

        $span = $this->onlySpan();

        $this->assertSame($traceId, $span->getTraceId());
        $this->assertSame($parentSpanId, $span->getParentSpanId());
    }

    public function test_a_traceparent_is_available_for_outbound_calls(): void
    {
        $tracer = $this->tracer();

        $this->assertNull($tracer->traceparent(), 'nothing to propagate before a trace starts');

        $tracer->startRoot('GET /operator');
        $traceparent = $tracer->traceparent();
        $tracer->endRoot();

        $this->assertNotNull($traceparent);
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/', $traceparent);
    }

    public function test_an_error_is_recorded_on_the_root_span(): void
    {
        $tracer = $this->tracer();

        $tracer->startRoot('job SendMessage');
        $tracer->endRoot([], new RuntimeException('carrier rejected the message'));

        $span = $this->onlySpan();

        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertNotEmpty($span->getEvents());
    }

    // ── Bounds ──────────────────────────────────────────────────────

    /**
     * An accidental N+1 can produce thousands of queries in one request.
     * Uncapped, that pins the exporter and usually breaches the backend's
     * per-trace span limit — which rejects the WHOLE trace, so a
     * performance bug destroys the evidence of itself.
     */
    public function test_database_spans_are_capped_per_trace(): void
    {
        config(['observability.tracing.max_db_spans' => 3]);

        $tracer = $this->tracer();
        $tracer->startRoot('GET /admin');

        $accepted = 0;

        for ($i = 0; $i < 10; $i++) {
            if ($tracer->acceptDbSpan()) {
                $accepted++;
                $tracer->record('db SELECT', 0.001);
            }
        }

        $tracer->endRoot();

        $this->assertSame(3, $accepted);
    }

    public function test_the_cap_resets_between_traces(): void
    {
        config(['observability.tracing.max_db_spans' => 2]);

        $tracer = $this->tracer();

        $tracer->startRoot('GET /admin');
        $this->assertTrue($tracer->acceptDbSpan());
        $this->assertTrue($tracer->acceptDbSpan());
        $this->assertFalse($tracer->acceptDbSpan());
        $tracer->endRoot();

        $tracer->startRoot('GET /admin');
        $this->assertTrue($tracer->acceptDbSpan(), 'a fresh request starts with a fresh budget');
        $tracer->endRoot();
    }

    /**
     * A ratio of zero is the documented way to keep the pipeline wired
     * but silent — useful for confirming an endpoint and credential
     * before turning real traffic on.
     */
    public function test_a_zero_sample_ratio_records_nothing(): void
    {
        config([
            'observability.tracing.enabled' => true,
            'observability.tracing.endpoint' => 'http://tempo.test:4318/v1/traces',
            'observability.tracing.sample_ratio' => 0.0,
        ]);

        $tracer = app(Tracer::class);
        $tracer->useExporter($this->exporter);

        $tracer->startRoot('GET /operator');
        $tracer->record('db SELECT', 0.01);
        $tracer->endRoot();

        $this->assertSame([], $this->exporter->getSpans());
    }

    /**
     * Parent-based sampling: an upstream service that decided to sample
     * this trace gets its decision honoured, whatever our own ratio says.
     *
     * This is what keeps cross-process traces whole. Sampling each hop
     * independently at 5% would mean a two-service trace survives intact
     * one time in four hundred, and the rest arrive with holes — which
     * read as work that never happened.
     */
    public function test_an_upstream_sampling_decision_is_honoured(): void
    {
        config([
            'observability.tracing.enabled' => true,
            'observability.tracing.endpoint' => 'http://tempo.test:4318/v1/traces',
            // Our own ratio would almost certainly drop this trace.
            'observability.tracing.sample_ratio' => 0.0000001,
        ]);

        $tracer = app(Tracer::class);
        $tracer->useExporter($this->exporter);

        // Trailing 01: the caller sampled it.
        $tracer->startRoot(
            'POST /api/agent-worker/heartbeat',
            traceparent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        );
        $tracer->endRoot();

        $this->assertCount(1, $this->exporter->getSpans());
    }

    public function test_an_upstream_decision_not_to_sample_is_also_honoured(): void
    {
        config([
            'observability.tracing.enabled' => true,
            'observability.tracing.endpoint' => 'http://tempo.test:4318/v1/traces',
            'observability.tracing.sample_ratio' => 1.0,
        ]);

        $tracer = app(Tracer::class);
        $tracer->useExporter($this->exporter);

        // Trailing 00: the caller did not sample it. Recording our half
        // would produce a fragment nobody can join up.
        $tracer->startRoot(
            'POST /api/agent-worker/heartbeat',
            traceparent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00',
        );
        $tracer->endRoot();

        $this->assertSame([], $this->exporter->getSpans());
    }

    // ── Ownership: who may close a root span ────────────────────────

    /**
     * The failure this prevents: a job dispatched on the `sync` driver is
     * processed INSIDE the web request that dispatched it, so the queue
     * seam's JobProcessing/JobProcessed pair fires while the request's
     * own span is open. A queue listener that closed it would truncate
     * the request's trace and orphan every span after the dispatch.
     */
    public function test_a_sync_job_cannot_close_the_requests_span(): void
    {
        $tracer = $this->tracer();

        $tracer->startRoot('GET /operator/message-inbox', owner: 'http');

        // What the queue seam does when a job runs inside the request.
        $this->assertFalse($tracer->startRoot('job SendMessage', owner: 'queue'));
        $tracer->endRoot(['orbital.job_outcome' => 'processed'], owner: 'queue');

        $this->assertTrue($tracer->hasActiveTrace(), 'the request span must survive the job');
        $this->assertSame([], $this->exporter->getSpans(), 'nothing exported until the request ends');

        // Work done during the job still belongs to the request's trace.
        $tracer->record('db INSERT', 0.002);
        $tracer->endRoot([], owner: 'http');

        $names = array_map(fn ($s) => $s->getName(), $this->exporter->getSpans());
        $this->assertContains('GET /operator/message-inbox', $names);
        $this->assertContains('db INSERT', $names);
    }

    /**
     * Artisan::call() inside a command, or a job dispatched inside a job,
     * re-enters the same seam. The inner run should nest, not end the
     * outer trace.
     */
    public function test_a_nested_command_nests_rather_than_ending_the_trace(): void
    {
        $tracer = $this->tracer();

        $this->assertTrue($tracer->startRoot('artisan orbital:bootstrap', owner: 'console'));
        $this->assertFalse($tracer->startRoot('artisan migrate', owner: 'console'));

        $tracer->endRoot(['orbital.exit_code' => 0], owner: 'console');
        $this->assertTrue($tracer->hasActiveTrace(), 'the inner command must not end the outer trace');

        $tracer->endRoot(['orbital.exit_code' => 0], owner: 'console');
        $this->assertFalse($tracer->hasActiveTrace());

        $this->assertCount(1, $this->exporter->getSpans());
    }

    // ── Never breaks the request ────────────────────────────────────

    /**
     * A misconfigured endpoint is a monitoring problem. A 500 caused by a
     * monitoring problem is an outage.
     */
    public function test_a_broken_exporter_does_not_surface_to_the_caller(): void
    {
        config([
            'observability.tracing.enabled' => true,
            'observability.tracing.endpoint' => 'not-a-url-at-all',
        ]);

        $tracer = app(Tracer::class);

        $tracer->startRoot('GET /operator');
        $tracer->record('db SELECT', 0.01);
        $tracer->endRoot();

        // And having failed once, it stands down rather than retrying a
        // dead connection on every span of every request.
        $this->assertFalse($tracer->enabled());
    }

    public function test_the_request_still_succeeds_with_an_unreachable_collector(): void
    {
        config([
            'observability.tracing.enabled' => true,
            'observability.tracing.endpoint' => 'http://127.0.0.1:9/v1/traces',
            'observability.tracing.timeout' => 0.1,
        ]);

        $this->get('/up')->assertOk();
    }

    // ── What a span may carry ───────────────────────────────────────

    /**
     * The database seam is the one that matters. Laravel's QueryExecuted
     * carries the statement AND its bindings; the bindings are the
     * caller's phone number, the body of somebody's text, and the
     * contents of an intake form.
     */
    public function test_query_bindings_never_reach_a_span(): void
    {
        config(['observability.tracing.enabled' => true]);

        $tracer = $this->tracer();
        $tracer->startRoot('GET /operator');

        // Register the same listener the provider installs.
        DB::listen(function ($query) use ($tracer): void {
            if (! $tracer->hasActiveTrace() || ! $tracer->acceptDbSpan()) {
                return;
            }

            $tracer->record('db SELECT', $query->time / 1000, [
                'db.query.text' => $query->sql,
            ]);
        });

        DB::select('select * from users where email = ?', ['patient@example.com']);

        $tracer->endRoot();

        $exported = json_encode(array_map(
            fn (ImmutableSpan $s): array => [$s->getName(), $s->getAttributes()->toArray()],
            $this->exporter->getSpans(),
        ));

        $this->assertStringNotContainsString('patient@example.com', (string) $exported);
        $this->assertStringContainsString('select * from users where email = ?', (string) $exported);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function tracer(): Tracer
    {
        config([
            'observability.tracing.enabled' => true,
            'observability.tracing.endpoint' => 'http://tempo.test:4318/v1/traces',
            // Tests assert on exported spans, so they must all be
            // sampled. The shipped default is 0.05 — see the dedicated
            // sampling tests for that behaviour.
            'observability.tracing.sample_ratio' => 1.0,
        ]);

        $tracer = app(Tracer::class);
        $tracer->useExporter($this->exporter);

        return $tracer;
    }

    private function onlySpan(): ImmutableSpan
    {
        $spans = $this->exporter->getSpans();

        $this->assertCount(1, $spans);

        return $spans[0];
    }

    private function spanNamed(string $name): ImmutableSpan
    {
        foreach ($this->exporter->getSpans() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        $this->fail("no span named {$name} was exported");
    }
}
