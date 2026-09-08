# Third-party components

Orbital itself is AGPL-3.0 (see [`LICENSE`](../LICENSE)). It orchestrates a
number of independently licensed systems. This page is the inventory.

The GPL-compatibility reasoning — why shipping GPLv2 components alongside an
AGPLv3 application is aggregation rather than a combined work — lives in
[`NOTICE`](../NOTICE) and is not repeated here. The short version: everything
below runs as a separate program in its own container, reached over a network
or IPC interface. Nothing is linked into Orbital.

## Telephony and media

| Component | License | How Orbital talks to it |
| --- | --- | --- |
| Asterisk 22 LTS | GPLv2 with linking exception | AMI, ARI, ODBC realtime, generated config |
| Kamailio 5.5 | GPLv2-or-later | binrpc/jsonrpc, generated dispatcher list |
| rtpengine | GPLv3 | ng control protocol (UDP) |
| LiveKit Server | Apache-2.0 | HTTP/WebSocket server API |
| LiveKit SIP | Apache-2.0 | Separate container, LiveKit API |
| Icecast (`moul/icecast`) | GPLv2 | HTTP source/listener (hold music) |

## Data and storage

| Component | License |
| --- | --- |
| PostgreSQL 17 (`pgvector/pgvector:pg17`) | PostgreSQL License (BSD-style) |
| pgvector | PostgreSQL License |
| Patroni | MIT |
| etcd | Apache-2.0 |
| Barman | GPLv3 |
| Valkey 8 | BSD-3-Clause |
| SeaweedFS | Apache-2.0 |

## Networking and edge

| Component | License |
| --- | --- |
| HAProxy 3.0 | GPLv2-or-later (libs under LGPL) |
| nginx | BSD-2-Clause |
| keepalived | GPLv2 |
| acme.sh | GPLv3 |
| Haraka (inbound SMTP) | MIT |

## Observability

| Component | License |
| --- | --- |
| Prometheus | Apache-2.0 |
| Pushgateway | Apache-2.0 |
| Grafana | AGPL-3.0 |
| Loki | AGPL-3.0 |
| Promtail | Apache-2.0 |
| postgres_exporter | Apache-2.0 |
| redis_exporter | MIT |

Grafana and Loki are themselves AGPL-3.0 — same license as Orbital, same
obligations, and they are likewise unmodified separate programs.

## Application runtime

| Component | License |
| --- | --- |
| PHP 8.4 | PHP License 3.01 |
| Laravel Framework 12 | MIT |
| Filament 5 | MIT |
| Livewire 4 | MIT |
| Laravel Horizon / Passport / Pulse / Reverb / Sanctum / Telescope / Jetstream | MIT |
| spatie/laravel-permission | MIT |
| beyondcode/laravel-mailbox | MIT |
| league/csv, league/flysystem-aws-s3-v3 | MIT |
| phpoffice/phpspreadsheet | MIT |
| smalot/pdfparser | LGPL-3.0 |
| predis/predis | MIT |

`smalot/pdfparser` is LGPL-3.0 and is used as an unmodified library through
its public API. LGPL-3.0 is compatible with AGPL-3.0.

## Frontend

| Component | License |
| --- | --- |
| Svelte 5 | MIT |
| Svelte Flow (`@xyflow/svelte`) | MIT |
| Tailwind CSS | MIT |
| Alpine.js | MIT |
| SIP.js | MIT |
| wavesurfer.js | BSD-3-Clause |
| laravel-echo, pusher-js | MIT |
| Vite | MIT |
| Heroicons | MIT |

All frontend dependencies are bundled locally through Vite. Orbital never
loads assets from a public CDN — see the offline-first constraint in
[`README.md`](../README.md).

## Agent worker (Python)

| Component | License |
| --- | --- |
| livekit-agents + plugins | Apache-2.0 |
| requests | Apache-2.0 |
| Python 3.12 | PSF License |

## Base images

`debian:bookworm-slim`, `ubuntu:24.04`, `alpine:3.19`/`3.20`,
`php:8.4-fpm-alpine`, `node:22-alpine`, `python:3.12-slim` — each a
distribution of many separately licensed packages, carrying their upstream
licenses. Orbital adds no restrictions to any of them.

## Optional development-only services

Not part of a production deployment; listed for completeness because they
appear in `docker-compose.yml`:

| Component | License |
| --- | --- |
| Mailpit | MIT |
| pgAdmin 4 | PostgreSQL License |
| redis-commander | MIT |
| Ollama | MIT |

## Keeping this current

This inventory is maintained by hand. When you add a container image, a
composer package, or an npm dependency, add it here in the same change. A
license audit that runs once a year and finds twenty undocumented
dependencies is worth less than a table that is correct on every commit.
