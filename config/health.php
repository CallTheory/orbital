<?php

return [
    // Components not present in this deployment topology. Their health
    // cards render as "not deployed" (neutral) instead of down. Comma-
    // separated list from HEALTH_DISABLED_COMPONENTS; the k8s chart sets
    // it (observability/edge/optional services live elsewhere here).
    'disabled' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HEALTH_DISABLED_COMPONENTS', '')),
    ))),
];
