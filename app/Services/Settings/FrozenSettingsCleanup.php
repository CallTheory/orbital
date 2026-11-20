<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\PlatformSetting;

/**
 * Removes Platform Settings values that were saved by accident, so
 * those settings follow the deployment again.
 *
 * Until saves started storing only changed fields, every save stored
 * every visible field, freezing the deployment's values of that moment.
 * Two kinds of row are left over:
 *
 *  - A value equal to the deployment's own. Removing it changes nothing
 *    today, and lets a later .env / chart change take effect.
 *  - A service address (url setting) still holding its docker-compose
 *    placeholder, e.g. http://ollama:11434, while the deployment sets a
 *    different address. Saved on a Kubernetes site before the chart
 *    provided the in-cluster address, it points at a host that doesn't
 *    exist there.
 *
 * Settings the deployment manages are left alone: boot ignores their
 * rows anyway.
 */
class FrozenSettingsCleanup
{
    public function __construct(
        private readonly PlatformSettingsRepository $repo,
        private readonly DeploymentDefaults $defaults,
    ) {}

    /**
     * @return array<int, string> the keys whose saved value was removed
     */
    public function run(): array
    {
        $removed = [];

        foreach (PlatformSetting::query()->pluck('key') as $key) {
            $def = SettingsRegistry::find($key);
            if ($def === null || SettingsRegistry::isManagedByDeployment($key)) {
                continue;
            }

            $saved = $this->repo->get($key);
            $default = $this->defaults->for($key);

            if ($saved === null || $default === null || $default === '') {
                continue;
            }

            $matchesDeployment = $saved == $default;
            $stalePlaceholder = ($def['type'] ?? null) === 'url'
                && isset($def['placeholder'])
                && $saved === $def['placeholder'];

            if ($matchesDeployment || $stalePlaceholder) {
                $this->repo->forget($key);
                $removed[] = $key;
            }
        }

        return $removed;
    }
}
