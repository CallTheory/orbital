<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Failover tier modes
|--------------------------------------------------------------------------
|
| Drives whether FailoverCentral renders the cluster-aware tier rows
| (Patroni / Sentinel / SeaweedFS-master) or skips them when the
| customer is using a managed equivalent (RDS / ElastiCache / S3).
|
| Modes per tier:
|   - 'cluster' : full cluster-state UI + drain actions; runs the
|                 cluster-specific health probe (Patroni REST,
|                 Sentinel SENTINEL MASTER, SeaweedFS master /cluster/status).
|   - 'managed' : tier hidden from FailoverCentral; SystemHealthService
|                 falls back to a generic connectivity probe (TCP +
|                 a cheap auth check). Drain actions hidden — the
|                 managed service handles its own failover.
|   - 'none'    : tier hidden everywhere, no probe at all.
|
| The Helm chart sets these env vars from the per-tier external.enabled
| flag (configmap-failover-tiers.yaml). Operators on docker-compose
| dev or older installs without those env vars get the legacy default,
| which is 'cluster' (the existing behavior pre-Phase-2).
*/

return [
    'postgres' => env('ORBITAL_FAILOVER_POSTGRES_MODE', 'cluster'),
    'valkey' => env('ORBITAL_FAILOVER_VALKEY_MODE', 'cluster'),
    'object_storage' => env('ORBITAL_FAILOVER_OBJECT_STORAGE_MODE', 'cluster'),
];
