<?php

declare(strict_types=1);

namespace App\Services\Settings;

/**
 * What each registry setting would be if nothing were saved in
 * Platform Settings: the deployment's own value (.env, or the Helm
 * chart's app-env ConfigMap on Kubernetes).
 *
 * RuntimeConfigOverrideProvider overwrites config with saved values at
 * boot, so once it has run, config() alone can no longer tell you the
 * deployment's value for a saved setting. The provider records the
 * value it replaces here first; for a setting with nothing saved, the
 * live config still is the deployment's value.
 *
 * Bound as a singleton for the life of the request / worker.
 */
final class DeploymentDefaults
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * Record the deployment's value for $key, unless one is already
     * recorded (the first value seen is the one from before any
     * override).
     */
    public function remember(string $key, mixed $value): void
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $value;
        }
    }

    public function for(string $key): mixed
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        $targets = SettingsRegistry::configTargets($key);

        return $targets !== [] ? config($targets[0]) : null;
    }
}
