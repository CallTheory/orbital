<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Providers\ObservabilityServiceProvider;
use App\Services\Observability\Scrubber;
use App\Support\Observability;
use App\Support\Release;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Sentry\Event;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The optional error-reporting integration.
 *
 * Two properties matter more than the feature itself.
 *
 * Off has to mean off. This is an installable platform: most operators
 * will never turn this on, and for them it must cost nothing and must
 * not be switchable by a stray environment variable inherited from a
 * base image or a CI runner.
 *
 * On has to be safe. Orbital handles other people's callers, and an
 * exception report is a copy of whatever the process was holding when
 * it failed. Once this is pointed at somebody's GlitchTip — or worse,
 * a hosted service — there is no recalling what was sent.
 */
class ErrorReportingTest extends TestCase
{
    use RefreshDatabase;

    // ── Off by default, and hard to switch on by accident ───────────

    public function test_it_is_off_out_of_the_box(): void
    {
        $this->assertFalse(config('observability.errors.enabled'));
        $this->assertFalse(Observability::errorsEnabled());
        $this->assertNull(config('sentry.dsn'));
    }

    /**
     * A toggle with no DSN is a half-configured integration. Treating
     * it as on would build a client that discards everything while the
     * operator believes errors are being captured — the one state worse
     * than being off.
     */
    public function test_the_toggle_alone_does_not_enable_it(): void
    {
        config(['observability.errors.enabled' => true, 'observability.errors.dsn' => null]);

        $this->assertFalse(Observability::errorsEnabled());

        $this->configure();

        $this->assertNull(config('sentry.dsn'));
    }

    public function test_a_blank_dsn_is_treated_as_no_dsn(): void
    {
        config(['observability.errors.enabled' => true, 'observability.errors.dsn' => '   ']);

        $this->assertFalse(Observability::errorsEnabled());
    }

    /**
     * The Sentry package is auto-discovered and reads SENTRY_LARAVEL_DSN
     * from the environment on its own. Nothing outside Orbital's own
     * settings may switch on off-site reporting.
     */
    public function test_a_stray_vendor_dsn_cannot_switch_reporting_on(): void
    {
        config(['sentry.dsn' => 'https://key@sentry.example.com/9']);

        $this->configure();

        $this->assertNull(config('sentry.dsn'));
        $this->assertFalse(Observability::errorsEnabled());
    }

    // ── When it is on ───────────────────────────────────────────────

    public function test_enabling_it_configures_the_client(): void
    {
        $this->enable();

        $this->assertSame('https://key@glitchtip.example.com/1', config('sentry.dsn'));
        $this->assertTrue(Observability::errorsEnabled());

        // Ties a report to the build that produced it. Without this a
        // regression cannot be attributed to a deploy.
        $this->assertSame(Release::version(), config('sentry.release'));
    }

    public function test_personal_data_collection_is_forced_off(): void
    {
        $this->enable();

        $this->assertFalse(config('sentry.send_default_pii'));
    }

    /**
     * Traces belong to the OTLP pipeline. Letting the error SDK also
     * sample traces would produce a second, sparser answer to "what did
     * this request do", and two partial answers are worse than one.
     */
    public function test_it_does_not_start_a_second_tracing_pipeline(): void
    {
        $this->enable();

        $this->assertSame(0.0, config('sentry.traces_sample_rate'));
        $this->assertSame(0.0, config('sentry.profiles_sample_rate'));
    }

    public function test_ordinary_http_failures_are_not_reported_as_errors(): void
    {
        $this->enable();

        $ignored = config('sentry.ignore_exceptions');

        $this->assertContains(ValidationException::class, $ignored);
        $this->assertContains(AuthenticationException::class, $ignored);
        $this->assertContains(NotFoundHttpException::class, $ignored);
    }

    public function test_the_environment_label_falls_back_to_the_app_environment(): void
    {
        config(['observability.errors.environment' => null]);

        $this->assertSame(app()->environment(), Observability::environment());

        config(['observability.errors.environment' => 'staging-eu']);

        $this->assertSame('staging-eu', Observability::environment());
    }

    // ── Nothing leaves without being scrubbed ───────────────────────

    public function test_caller_data_is_stripped_before_an_event_is_sent(): void
    {
        $this->enable();

        $event = Event::createEvent();
        $event->setExtra([
            'caller_phone' => '+15551234567',
            'body' => 'I need a refill of my prescription',
            'thread_id' => 42,
        ]);
        $event->setRequest([
            'url' => 'https://orbital.test/operator/message-thread/42',
            'data' => ['reason' => 'chest pain', 'urgency' => 'urgent'],
        ]);

        $sent = $this->send($event);

        $this->assertSame(Scrubber::REDACTED, $sent->getExtra()['caller_phone']);
        $this->assertSame(Scrubber::REDACTED, $sent->getExtra()['body']);
        $this->assertSame(Scrubber::REDACTED, $sent->getRequest()['data']['reason']);

        // Diagnostics that are not somebody's private business survive —
        // a report with everything redacted is a report nobody can act on.
        $this->assertSame(42, $sent->getExtra()['thread_id']);
        $this->assertSame('urgent', $sent->getRequest()['data']['urgency']);
        $this->assertSame('https://orbital.test/operator/message-thread/42', $sent->getRequest()['url']);
    }

    public function test_credentials_are_stripped_before_an_event_is_sent(): void
    {
        $this->enable();

        $event = Event::createEvent();
        $event->setExtra([
            'auth_token' => 'ACtest:supersecret',
            'password' => 'hunter2',
            'provider' => 'twilio',
        ]);

        $sent = $this->send($event);

        $this->assertSame(Scrubber::REDACTED, $sent->getExtra()['auth_token']);
        $this->assertSame(Scrubber::REDACTED, $sent->getExtra()['password']);
        $this->assertSame('twilio', $sent->getExtra()['provider']);
    }

    public function test_an_anonymous_event_carries_no_user_identity(): void
    {
        $this->enable();

        $sent = $this->send(Event::createEvent());

        $this->assertNull($sent->getUser());
    }

    // ── Scrubber unit behaviour ─────────────────────────────────────

    public function test_the_scrubber_matches_key_variants_not_exact_names(): void
    {
        $scrubber = new Scrubber;

        // The same value arrives under different names depending on
        // which layer built the array. Listing exact spellings is how a
        // scrubber quietly stops working.
        foreach (['phone', 'caller_phone', 'from_phone', 'phone_number', 'PhoneNumber'] as $key) {
            $this->assertTrue($scrubber->isSensitive($key), "{$key} should be treated as sensitive");
        }
    }

    public function test_the_scrubber_preserves_shape_rather_than_dropping_keys(): void
    {
        $out = (new Scrubber)->scrub(['caller_phone' => '+15551234567']);

        // "We had a number and redacted it" is a different diagnosis
        // from "there was no number", and a dropped key looks like the
        // second.
        $this->assertArrayHasKey('caller_phone', $out);
        $this->assertSame(Scrubber::REDACTED, $out['caller_phone']);
    }

    public function test_the_scrubber_reaches_nested_structures(): void
    {
        $out = (new Scrubber)->scrub([
            'thread' => ['entries' => [['body' => 'secret text', 'id' => 7]]],
        ]);

        $this->assertSame(Scrubber::REDACTED, $out['thread']['entries'][0]['body']);
        $this->assertSame(7, $out['thread']['entries'][0]['id']);
    }

    public function test_the_scrubber_keeps_explicitly_safe_lookalikes(): void
    {
        $scrubber = new Scrubber;

        // Already a one-way normalisation, and needed to make an
        // opt-out bug reproducible.
        $this->assertFalse($scrubber->isSensitive('address_key'));
        $this->assertTrue($scrubber->isSensitive('from_address'));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function enable(): void
    {
        config([
            'observability.errors.enabled' => true,
            'observability.errors.dsn' => 'https://key@glitchtip.example.com/1',
        ]);

        $this->configure();
    }

    private function configure(): void
    {
        (new ObservabilityServiceProvider($this->app))->configureErrorReporting();
    }

    /**
     * Run an event through the same before_send gate the SDK uses, which
     * is the only thing standing between a stack trace and the operator's
     * error project.
     */
    private function send(Event $event): Event
    {
        $beforeSend = config('sentry.before_send');

        $this->assertIsCallable($beforeSend, 'before_send must be installed when reporting is enabled');

        return $beforeSend($event, null);
    }
}
