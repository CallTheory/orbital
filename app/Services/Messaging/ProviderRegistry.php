<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Messaging\Contracts\MessagingProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a provider driver key to its implementation.
 *
 * Drivers are listed explicitly in config/messaging.php rather than
 * auto-discovered. This is the class that decides which code handles a
 * public, unauthenticated webhook — the set of things it can reach
 * should be greppable in one file, the same reasoning the Tools admin
 * page uses for its artisan allowlist.
 */
class ProviderRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @throws \InvalidArgumentException when the key isn't registered
     */
    public function get(string $key): MessagingProvider
    {
        $class = config('messaging.drivers.'.$key);

        if (! is_string($class) || $class === '') {
            throw new \InvalidArgumentException("Unknown messaging provider [{$key}].");
        }

        $provider = $this->container->make($class);

        if (! $provider instanceof MessagingProvider) {
            throw new \InvalidArgumentException("[{$class}] is not a MessagingProvider.");
        }

        return $provider;
    }

    public function has(string $key): bool
    {
        return is_string(config('messaging.drivers.'.$key));
    }

    /**
     * Driver keys available on this installation, for admin selects.
     *
     * @return array<string, string> key => human label
     */
    public function options(): array
    {
        $labels = (array) config('messaging.labels', []);

        $out = [];

        foreach (array_keys((array) config('messaging.drivers', [])) as $key) {
            $out[$key] = $labels[$key] ?? ucfirst((string) $key);
        }

        return $out;
    }
}
