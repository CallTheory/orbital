<?php

declare(strict_types=1);

namespace App\Services\Bootstrap\Bootstrappers;

use App\Services\Bootstrap\Bootstrapper;
use App\Services\Bootstrap\BootstrapReport;
use App\Services\Bootstrap\BootstrapStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Throwable;

/**
 * Generates and rotates the shared secrets that make every
 * control-panel SSO flow work:
 *
 *   - REDIS_COMMANDER_SSO_SECRET — HS256 signing key for the
 *     Redis Commander JWT redirect flow.
 *   - REDIS_COMMANDER_SSO_ISSUER — stable issuer string that
 *     appears in the JWT `iss` claim and matches Commander's
 *     SSO_ISSUER env var. Default: `orbital-admin`.
 *   - GRAFANA_PROXY_TRUST_TOKEN — shared-secret header sent by
 *     the Laravel Grafana proxy on every forwarded request. For
 *     dev the sail network's IP whitelist is the enforced trust
 *     boundary; prod is expected to run an nginx / Traefik
 *     sidecar in front of Grafana that validates the token
 *     before forwarding.
 *   - PGADMIN_OAUTH2_CLIENT_ID / _SECRET — Passport OAuth2
 *     client for pgAdmin's OIDC signin. Created via the
 *     `orbital:create-oidc-client` artisan command which
 *     delegates to Passport's ClientRepository.
 *
 * Rotation semantics: every `install()` call generates fresh
 * values for ALL managed keys. This is the one-button "reset
 * SSO secrets" flow the admin explicitly asked for on the
 * System Setup page — no "leave alone if present" branch.
 * Every click invalidates every current SSO session across
 * Redis Commander, Grafana, and pgAdmin; the operator is warned
 * in the returned report and instructed to restart the affected
 * containers to pick up the new values.
 *
 * Reads/writes `.env` directly via a minimal rewriteEnv helper
 * (same pattern Laravel's own KeyGenerateCommand uses). After
 * rewriting, `config:clear` is called so the current request
 * sees the new values; any running workers still need a
 * container restart.
 *
 * `status()` reports Installed when all six `.env` keys are
 * present + non-empty AND the two Passport clients exist,
 * Partial when some, Missing when none.
 */
class SsoSecretsBootstrapper implements Bootstrapper
{
    /**
     * Plain-value keys managed via Str::random(48). Each is a
     * standalone shared secret with no DB side.
     */
    private const OPAQUE_KEYS = [
        'REDIS_COMMANDER_SSO_SECRET',
        'REDIS_COMMANDER_SSO_ISSUER' => 'orbital-admin',
        'GRAFANA_PROXY_TRUST_TOKEN',
    ];

    /**
     * OAuth2 clients managed via CreateOidcClient. Each entry:
     *   name       — Passport Client::name, also the identity the
     *                bootstrapper uses for idempotent rotation.
     *   envPrefix  — KEY_ID + KEY_SECRET variants written to .env.
     *   redirectFn — closure returning the redirect URI registered
     *                on the Passport client. Downstream tools must
     *                hit this URL after OAuth2 authorization; if the
     *                URL is wrong, the authorize response is rejected
     *                by Passport with a "redirect URI mismatch" error.
     */
    private const OIDC_CLIENTS = [
        [
            'name' => 'pgadmin',
            'envPrefix' => 'PGADMIN_OAUTH2',
            'redirectPath' => '/oauth2/authorize',
            'port' => 5050,
        ],
        // MinIO was removed when we migrated off MinIO Inc's
        // archived build to SeaweedFS. SeaweedFS doesn't ship an
        // OIDC login UI out of the box, and its filer web UI is
        // super-admin-gated via the nav item rather than SSO'd.
        // If we ever layer a reverse-proxy in front of SeaweedFS
        // that needs its own OAuth2 client, re-add a definition
        // here following the pgadmin shape above.
    ];

    public function key(): string
    {
        return 'sso_secrets';
    }

    public function name(): string
    {
        return 'SSO secrets';
    }

    public function description(): string
    {
        return 'Generates and rotates the shared secrets + OAuth2 clients used by the Grafana, Redis Commander, and pgAdmin SSO flows.';
    }

    public function icon(): string
    {
        return 'heroicon-o-key';
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function status(): BootstrapReport
    {
        $steps = [];
        $allOk = true;

        foreach ($this->expectedOpaqueKeys() as $key) {
            $set = filled(env($key));
            $steps[] = [
                'label' => $key,
                'ok' => $set,
                'detail' => $set ? 'set' : 'empty',
            ];
            if (! $set) {
                $allOk = false;
            }
        }

        foreach (self::OIDC_CLIENTS as $def) {
            $exists = Client::query()->where('name', $def['name'])->exists();
            $idSet = filled(env($def['envPrefix'].'_CLIENT_ID'));
            $secretSet = filled(env($def['envPrefix'].'_CLIENT_SECRET'));
            $ok = $exists && $idSet && $secretSet;
            $steps[] = [
                'label' => $def['name'].' OAuth2 client',
                'ok' => $ok,
                'detail' => $ok ? 'registered' : 'missing or out of sync with .env',
            ];
            if (! $ok) {
                $allOk = false;
            }
        }

        $anyOk = false;
        foreach ($steps as $s) {
            if ($s['ok']) {
                $anyOk = true;
                break;
            }
        }

        if ($allOk) {
            return new BootstrapReport(BootstrapStatus::Installed, 'All SSO secrets are in place.', $steps);
        }
        if (! $anyOk) {
            return new BootstrapReport(BootstrapStatus::Missing, 'No SSO secrets have been generated yet.', $steps);
        }

        return new BootstrapReport(BootstrapStatus::Partial, 'Some SSO secrets are missing — re-run install.', $steps);
    }

    public function install(): BootstrapReport
    {
        $steps = [];
        $allOk = true;

        // Opaque secrets first — simple random-string rotation.
        foreach ($this->expectedOpaqueKeys() as $key) {
            try {
                $value = $this->defaultOrRandomFor($key);
                $this->rewriteEnv($key, $value);
                $steps[] = ['label' => $key, 'ok' => true, 'detail' => 'rotated'];
            } catch (Throwable $e) {
                $allOk = false;
                $steps[] = ['label' => $key, 'ok' => false, 'detail' => $e->getMessage()];
            }
        }

        // OAuth2 clients — delete-and-recreate via the
        // CreateOidcClient artisan command. Output is parsed for
        // CLIENT_ID= and CLIENT_SECRET= lines.
        foreach (self::OIDC_CLIENTS as $def) {
            try {
                $redirect = $this->redirectFor($def);
                $exitCode = Artisan::call('orbital:create-oidc-client', [
                    'name' => $def['name'],
                    'redirect' => $redirect,
                ]);
                if ($exitCode !== 0) {
                    throw new \RuntimeException('orbital:create-oidc-client exited with '.$exitCode);
                }

                $output = Artisan::output();
                $parsed = $this->parseCreateOidcClientOutput($output);

                if (empty($parsed['id']) || empty($parsed['secret'])) {
                    throw new \RuntimeException('Could not parse CLIENT_ID / CLIENT_SECRET from command output.');
                }

                $this->rewriteEnv($def['envPrefix'].'_CLIENT_ID', $parsed['id']);
                $this->rewriteEnv($def['envPrefix'].'_CLIENT_SECRET', $parsed['secret']);
                $steps[] = [
                    'label' => $def['name'].' OAuth2 client',
                    'ok' => true,
                    'detail' => "rotated (redirect={$redirect})",
                ];
            } catch (Throwable $e) {
                $allOk = false;
                Log::warning('Failed to rotate OAuth2 client for SSO bootstrapper', [
                    'client' => $def['name'],
                    'error' => $e->getMessage(),
                ]);
                $steps[] = [
                    'label' => $def['name'].' OAuth2 client',
                    'ok' => false,
                    'detail' => $e->getMessage(),
                ];
            }
        }

        // Cache clear so the current request sees rotated values;
        // long-running workers (Horizon, agent-worker, Reverb) need
        // a container restart before they'll pick them up.
        try {
            Artisan::call('config:clear');
        } catch (Throwable $e) {
            // Non-fatal — the rotation still succeeded, the warning
            // just can't guarantee in-process config is fresh.
            Log::warning('config:clear failed after SSO rotation', ['error' => $e->getMessage()]);
        }

        $steps[] = [
            'label' => 'Restart required',
            'ok' => true,
            'detail' => 'Run `sail restart redis-commander grafana pgadmin` to apply new secrets to running containers.',
        ];
        $steps[] = [
            'label' => 'Sessions invalidated',
            'ok' => true,
            'detail' => 'Every current SSO session in Redis Commander, Grafana, and pgAdmin has been kicked.',
        ];

        return new BootstrapReport(
            status: $allOk ? BootstrapStatus::Installed : BootstrapStatus::Partial,
            message: $allOk
                ? 'SSO secrets rotated.'
                : 'Some SSO secrets could not be rotated — see step details.',
            steps: $steps,
        );
    }

    /**
     * @return list<string>
     */
    private function expectedOpaqueKeys(): array
    {
        $keys = [];
        foreach (self::OPAQUE_KEYS as $k => $v) {
            $keys[] = is_string($k) ? $k : $v;
        }

        return $keys;
    }

    /**
     * Most opaque secrets are rotated to a fresh Str::random(48).
     * REDIS_COMMANDER_SSO_ISSUER is a stable string, not a secret,
     * so it's set to its default when missing but left alone when
     * already set — the admin may have intentionally customized it.
     */
    private function defaultOrRandomFor(string $key): string
    {
        if ($key === 'REDIS_COMMANDER_SSO_ISSUER') {
            $existing = env('REDIS_COMMANDER_SSO_ISSUER');

            return filled($existing) ? (string) $existing : 'orbital-admin';
        }

        return Str::random(48);
    }

    /**
     * Build the redirect URL for a downstream OAuth2 client.
     *
     * The redirect must match what the USER'S BROWSER sends when
     * the OAuth2 authorize step redirects back — that's always
     * `http://{app host}:{tool port}{path}`, not an internal
     * compose network hostname. `config('app.url')` gives the
     * canonical admin hostname.
     */
    private function redirectFor(array $def): string
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return "http://{$appHost}:{$def['port']}{$def['redirectPath']}";
    }

    /**
     * @return array{id: string|null, secret: string|null}
     */
    private function parseCreateOidcClientOutput(string $output): array
    {
        $id = null;
        $secret = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^CLIENT_ID=(.+)$/', trim($line), $m)) {
                $id = $m[1];
            } elseif (preg_match('/^CLIENT_SECRET=(.+)$/', trim($line), $m)) {
                $secret = $m[1];
            }
        }

        return ['id' => $id, 'secret' => $secret];
    }

    /**
     * In-place `.env` rewriter. If the key exists, its value is
     * replaced; if it doesn't, the key=value pair is appended.
     * Same shape Laravel's own `artisan key:generate` uses — we
     * could pull that helper out of Passport/Framework but the
     * code is 10 lines, cleaner to keep here.
     */
    private function rewriteEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            throw new \RuntimeException('.env does not exist at '.$path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read .env.');
        }

        // Quote values containing spaces or # so the parser
        // doesn't truncate them. Plain alphanumerics + common
        // secret characters don't need quoting.
        $needsQuoting = (bool) preg_match('/\s|#|"|\'/', $value);
        $formattedValue = $needsQuoting ? '"'.addcslashes($value, '"\\').'"' : $value;

        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $replacement = $key.'='.$formattedValue;

        if (preg_match($pattern, $contents)) {
            $newContents = preg_replace($pattern, $replacement, $contents);
        } else {
            $newContents = rtrim($contents, "\n")."\n".$replacement."\n";
        }

        if ($newContents === null || file_put_contents($path, $newContents) === false) {
            throw new \RuntimeException('Unable to write .env.');
        }
    }
}
