<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Services\Telephony\AsteriskConfigService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Maps a "service slug" (what a SettingsRegistry entry names in its
 * `restart_required` field) to the label shown in the UI and the
 * handler that actually applies the change.
 *
 * Some slugs trigger an in-process reload (Asterisk dialplan via AMI,
 * Horizon via `horizon:terminate` — Horizon's supervisor respawns it).
 * Others need an out-of-band container bounce (Reverb, scheduler) and
 * return false so the UI can tell the operator to `docker compose
 * restart <service>` themselves.
 *
 * Registering a new slug:
 *   1. Add a case to {@see handlers()} keyed by slug.
 *   2. Reference the slug from one or more SettingsRegistry entries'
 *      `restart_required` array.
 */
final class ServiceRestartCatalog
{
    /**
     * @return array<string, array{label: string, description: string, handler: callable(): array{ok: bool, message: string}}>
     */
    public static function handlers(): array
    {
        return [
            'asterisk' => [
                'label' => 'Asterisk',
                'description' => 'Regenerates dialplan files and issues `core reload` on every active Asterisk backend via AMI.',
                'handler' => function (): array {
                    $svc = app(AsteriskConfigService::class);
                    $svc->writeAllDialplans();
                    $svc->writeDialplanIndex();
                    $ok = $svc->reloadAsterisk();
                    return [
                        'ok' => $ok,
                        'message' => $ok
                            ? 'Asterisk reloaded on all backends.'
                            : 'Reload failed on at least one backend — check logs.',
                    ];
                },
            ],

            'horizon' => [
                'label' => 'Horizon workers',
                'description' => 'Signals Horizon to restart all queue workers so they pick up any cache/queue config changes.',
                'handler' => function (): array {
                    try {
                        Artisan::call('horizon:terminate');
                        return ['ok' => true, 'message' => 'Horizon workers signalled to restart.'];
                    } catch (\Throwable $e) {
                        Log::warning('service-restart: horizon:terminate failed', ['err' => $e->getMessage()]);
                        return ['ok' => false, 'message' => 'horizon:terminate failed: '.$e->getMessage()];
                    }
                },
            ],
        ];
    }

    /**
     * Labels for a given set of service slugs, preserving order.
     * Unknown slugs are silently dropped so a stale registry reference
     * doesn't crash the UI — they just won't appear on the button.
     *
     * @param  array<int, string>  $slugs
     * @return array<int, string>
     */
    public static function labelsFor(array $slugs): array
    {
        $handlers = self::handlers();
        return array_values(array_map(
            fn (string $slug) => $handlers[$slug]['label'],
            array_filter($slugs, fn (string $slug) => isset($handlers[$slug])),
        ));
    }

    /**
     * Run the handler for each slug, collecting results.
     *
     * @param  array<int, string>  $slugs
     * @return array<int, array{slug: string, label: string, ok: bool, message: string}>
     */
    public static function run(array $slugs): array
    {
        $handlers = self::handlers();
        $results = [];

        foreach ($slugs as $slug) {
            if (! isset($handlers[$slug])) {
                continue;
            }
            $res = ($handlers[$slug]['handler'])();
            $results[] = [
                'slug' => $slug,
                'label' => $handlers[$slug]['label'],
                'ok' => (bool) $res['ok'],
                'message' => (string) $res['message'],
            ];
        }

        return $results;
    }
}
