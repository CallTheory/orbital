<?php

/**
 * Parse "key=host:port,key=host:port" into [key => ['host' => …, 'port' => …]].
 * Entries without a usable host:port are skipped.
 *
 * @return array<string, array{host: string, port: int}>
 */
$parseProbes = static function (string $raw): array {
    $probes = [];
    foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
        [$key, $address] = array_pad(explode('=', $pair, 2), 2, '');
        $host = parse_url('tcp://'.trim($address), PHP_URL_HOST);
        $port = parse_url('tcp://'.trim($address), PHP_URL_PORT);
        if (trim($key) !== '' && is_string($host) && $host !== '' && is_int($port)) {
            $probes[trim($key)] = ['host' => trim($host, '[]'), 'port' => $port];
        }
    }

    return $probes;
};

return [
    // Components not present in this deployment topology. Their health
    // cards render as "not deployed" (neutral) instead of down. Comma-
    // separated list from HEALTH_DISABLED_COMPONENTS; the Helm chart
    // derives it from the features a site has turned on.
    'disabled' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HEALTH_DISABLED_COMPONENTS', '')),
    ))),

    // Where to probe a component that isn't at its docker-compose
    // hostname, by card key: HEALTH_PROBES="haraka=orbital-haraka:2525,…".
    // The Helm chart sets the ones it deploys; operators add the rest.
    'probes' => $parseProbes((string) env('HEALTH_PROBES', '')),

    // The TLS card checks the certificate this host actually serves when
    // Orbital doesn't manage its own (TLS_ENABLED off, as on Kubernetes,
    // where cert-manager and the ingress hold it). Blank = the APP_URL
    // host on 443. Set host:port to reach the ingress from inside the
    // cluster if the public address doesn't loop back.
    'served_tls' => (string) env('HEALTH_SERVED_TLS', ''),
];
