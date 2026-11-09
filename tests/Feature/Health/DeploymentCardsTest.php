<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Services\Health\HealthCheck;
use App\Services\Health\SystemHealthService;
use Tests\TestCase;

/**
 * Status-page cards that depend on how a site is deployed: where its
 * components live (HEALTH_PROBES), which mailer it sends through, and
 * the certificate its ingress serves.
 */
class DeploymentCardsTest extends TestCase
{
    /** @var list<resource> */
    private array $servers = [];

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        foreach ($this->servers as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        if ($this->tmp) {
            array_map('unlink', glob("{$this->tmp}/*") ?: []);
            rmdir($this->tmp);
        }
        parent::tearDown();
    }

    private function invokeHealth(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(SystemHealthService::class, $method))
            ->invoke(app(SystemHealthService::class), ...$args);
    }

    public function test_health_probes_env_is_parsed_into_host_and_port(): void
    {
        putenv('HEALTH_PROBES=haraka=orbital-haraka:2525, prometheus=prom.monitoring.svc:9090,broken=nohost,=x:1');
        try {
            $config = require base_path('config/health.php');
        } finally {
            putenv('HEALTH_PROBES');
        }

        $this->assertSame([
            'haraka' => ['host' => 'orbital-haraka', 'port' => 2525],
            'prometheus' => ['host' => 'prom.monitoring.svc', 'port' => 9090],
        ], $config['probes']);
    }

    public function test_configured_probes_replace_compose_hostnames_for_known_cards_only(): void
    {
        config(['health.probes' => [
            'haraka' => ['host' => 'orbital-haraka', 'port' => 2525],
            'not_a_card' => ['host' => 'x', 'port' => 1],
        ]]);

        $targets = $this->invokeHealth('withConfiguredProbes', [
            'haraka' => ['host' => 'haraka', 'port' => 25],
            'loki' => ['host' => 'loki', 'port' => 3100],
        ]);

        $this->assertSame([
            'haraka' => ['host' => 'orbital-haraka', 'port' => 2525],
            'loki' => ['host' => 'loki', 'port' => 3100],
        ], $targets);
    }

    /** @return array{host: string, port: int, ok: bool, error: ?string} */
    private static function smtp(bool $ok): array
    {
        return ['host' => 'relay.example.com', 'port' => 587, 'ok' => $ok, 'error' => $ok ? null : 'timed out'];
    }

    public function test_mail_card_warns_when_mail_is_only_logged(): void
    {
        config(['mail.default' => 'log']);

        $check = $this->invokeHealth('checkMail', self::smtp(false));

        $this->assertSame(HealthCheck::WARN, $check->status);
        $this->assertStringContainsString('only written to the log', $check->message);
    }

    public function test_mail_card_needs_the_api_key_of_an_api_mailer(): void
    {
        config(['mail.default' => 'sendgrid', 'services.sendgrid.key' => 'SG.test']);
        $this->assertSame(HealthCheck::OK, $this->invokeHealth('checkMail', self::smtp(false))->status);

        config(['services.sendgrid.key' => null]);
        $this->assertSame(HealthCheck::DOWN, $this->invokeHealth('checkMail', self::smtp(false))->status);
    }

    public function test_mail_card_probes_an_smtp_relay(): void
    {
        config(['mail.default' => 'smtp']);

        $this->assertSame(HealthCheck::OK, $this->invokeHealth('checkMail', self::smtp(true))->status);
        $down = $this->invokeHealth('checkMail', self::smtp(false));
        $this->assertSame(HealthCheck::DOWN, $down->status);
        $this->assertStringContainsString('relay.example.com:587', $down->message);
    }

    /**
     * Serve a certificate for $names that expires in $days, and return
     * the host:port it listens on.
     *
     * @param  list<string>  $names
     */
    private function serveCertificate(array $names, int $days): string
    {
        if (! is_executable(trim((string) shell_exec('command -v openssl')))) {
            $this->markTestSkipped('openssl is not installed');
        }

        $this->tmp = sys_get_temp_dir().'/orbital-tls-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);

        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        file_put_contents("{$this->tmp}/san.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName="
            .implode(',', array_map(fn (string $n) => "DNS:{$n}", $names))."\n");
        $csr = openssl_csr_new(['commonName' => $names[0]], $key, ['config' => "{$this->tmp}/san.cnf", 'req_extensions' => 'ext']);
        $cert = openssl_csr_sign($csr, null, $key, $days, ['config' => "{$this->tmp}/san.cnf", 'x509_extensions' => 'ext']);
        openssl_x509_export_to_file($cert, "{$this->tmp}/cert.pem");
        openssl_pkey_export_to_file($key, "{$this->tmp}/key.pem");

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->servers[] = proc_open(
            ['openssl', 's_server', '-accept', (string) $port, '-cert', "{$this->tmp}/cert.pem", '-key', "{$this->tmp}/key.pem", '-quiet', '-naccept', '5'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }

        return "127.0.0.1:{$port}";
    }

    public function test_tls_card_reads_the_served_certificate_when_orbital_does_not_manage_one(): void
    {
        $endpoint = $this->serveCertificate(['orbital.example.com'], 60);
        config(['tls.enabled' => false, 'app.url' => 'https://orbital.example.com', 'health.served_tls' => $endpoint]);

        $check = $this->invokeHealth('checkTlsCertificate');

        $this->assertSame(HealthCheck::OK, $check->status, $check->message);
        $this->assertStringContainsString('days remaining (orbital.example.com)', $check->message);
    }

    public function test_tls_card_flags_a_certificate_for_another_name(): void
    {
        $endpoint = $this->serveCertificate(['other.example.com'], 60);
        config(['tls.enabled' => false, 'app.url' => 'https://orbital.example.com', 'health.served_tls' => $endpoint]);

        $check = $this->invokeHealth('checkTlsCertificate');

        $this->assertSame(HealthCheck::DOWN, $check->status);
        $this->assertStringContainsString("doesn't cover orbital.example.com", $check->message);
    }

    public function test_tls_card_warns_when_renewal_looks_stuck(): void
    {
        $endpoint = $this->serveCertificate(['*.example.com'], 5);
        config(['tls.enabled' => false, 'app.url' => 'https://orbital.example.com', 'health.served_tls' => $endpoint]);

        $this->assertSame(HealthCheck::WARN, $this->invokeHealth('checkTlsCertificate')->status);
    }

    public function test_tls_card_warns_when_nothing_answers(): void
    {
        config(['tls.enabled' => false, 'app.url' => 'https://orbital.example.com', 'health.served_tls' => '127.0.0.1:1']);

        $check = $this->invokeHealth('checkTlsCertificate');

        $this->assertSame(HealthCheck::WARN, $check->status);
        $this->assertStringContainsString('Could not read the certificate', $check->message);
    }
}
