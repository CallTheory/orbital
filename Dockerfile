# Production Laravel image — bakes the app code in, serves through
# nginx + php-fpm under supervisord. Used for the laravel/horizon/reverb
# pods in deploy/helm/orbital. Horizon and Reverb override the K8s
# `command:` so they bypass supervisord and run `php artisan` directly;
# the entrypoint detects that case via $# > 0.
#
# Dev workflow (`./vendor/bin/sail up -d`) still uses
# `docker/8.4/Dockerfile` with the bind-mounted source. This file is
# only built by the Forgejo Actions release pipeline.

# ---------- Stage 1: frontend assets ----------
FROM node:22-alpine AS frontend

WORKDIR /build

# pnpm-workspace.yaml carries the `allowBuilds` allowlist (esbuild's
# build is required for `vite build` below) and the minimumReleaseAge
# supply-chain policy. It must be present BEFORE install or pnpm's
# strict-dep-builds aborts on the ignored esbuild build script.
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./

# Activate the pnpm pinned in package.json's `packageManager` field (the
# same version CI and local dev use, and the one Renovate bumps) rather
# than a hard-coded version here that silently drifts.
RUN corepack enable \
    && corepack install \
    && pnpm install --frozen-lockfile

COPY . .
RUN pnpm run build

# ---------- Stage 2: composer / vendor build ----------
# composer:2 is itself an alpine image; --ignore-platform-reqs lets us
# resolve the tree without rebuilding every PECL ext here. The runtime
# stage installs the actual extensions, so the vendor tree is portable.
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev --no-scripts --no-autoloader \
        --prefer-dist --no-interaction \
        --ignore-platform-reqs

# ---------- Stage 3: production runtime ----------
FROM php:8.5-fpm-alpine AS production

LABEL maintainer="Orbital"
LABEL org.opencontainers.image.title="Orbital"
LABEL org.opencontainers.image.description="Multi-tenant answering-service and call center platform"
LABEL org.opencontainers.image.licenses="AGPL-3.0-only"

ARG WWWGROUP=1000
WORKDIR /var/www/html

# Release identity, baked in at build time so a running container can
# name exactly what it is without a .git directory. These feed
# config/orbital.php, which in turn feeds the About page, the panel
# footers, /source, and /api/version — i.e. Orbital's AGPL section 13
# source offer. Build with:
#   --build-arg ORBITAL_VERSION=1.4.2 --build-arg ORBITAL_COMMIT=$(git rev-parse HEAD)
ARG ORBITAL_VERSION=dev
ARG ORBITAL_COMMIT=""
ARG ORBITAL_RELEASE_CHANNEL=stable
ENV ORBITAL_VERSION=${ORBITAL_VERSION}
ENV ORBITAL_COMMIT=${ORBITAL_COMMIT}
ENV ORBITAL_RELEASE_CHANNEL=${ORBITAL_RELEASE_CHANNEL}
LABEL org.opencontainers.image.version="${ORBITAL_VERSION}"
LABEL org.opencontainers.image.revision="${ORBITAL_COMMIT}"

ENV TZ=UTC
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1

# install-php-extensions is the upstream community installer that
# resolves Alpine apk deps + PECL builds for every extension we need.
# Pin to the major tag so the image stays reproducible per-rebuild.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# Runtime apk packages: nginx + supervisor for the web pod, su-exec
# (alpine's gosu replacement) for entrypoint user-drop, bash for the
# entrypoint script's `set -euo pipefail`, ca-certs + tzdata for TLS
# + timezone, sox/ffmpeg/librsvg/sqlite for the recording + Filament
# pipelines. install-php-extensions handles its own apk deps for each
# PHP extension and cleans up build artefacts before we exit the layer.
RUN apk add --no-cache \
        bash \
        ca-certificates \
        curl \
        ffmpeg \
        librsvg \
        nginx \
        # PostgreSQL client tools — pg_dump / pg_restore for
        # `orbital:backup` and `orbital:restore`. Deliberate addition to
        # an image that otherwise keeps its package list minimal: there
        # is no substitute for pg_dump, and a platform holding other
        # people's call records without a backup path is not shippable.
        #
        # Version rule: pg_dump must match the server's major version or
        # be NEWER. Alpine's current postgresql-client covers the
        # postgres:16 the chart deploys and managed 17 alike.
        postgresql-client \
        sox \
        sqlite \
        su-exec \
        supervisor \
        tzdata \
    && cp /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone \
    && install-php-extensions \
        bcmath gd igbinary imagick imap intl ldap msgpack opcache \
        pcntl pdo_pgsql pgsql pdo_sqlite posix redis soap swoole zip \
    && rm -rf /tmp/* /var/cache/apk/* /usr/local/lib/php/test \
              /usr/local/lib/php/doc /usr/src

# Match the dev image's uid/gid contract so K8s securityContext
# fsGroup=1000 lands on the same id space.
RUN addgroup -g $WWWGROUP -S sail \
    && adduser -S -D -H -u 1000 -G sail -s /sbin/nologin sail

# Composer is needed only for `dump-autoload` after the source COPY.
# Removed at the end of the stage so it doesn't ship in the image.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Shared php.ini overrides — same as dev. Applied to both CLI and FPM
# SAPIs so the artisan-driven Horizon / Reverb pods share the runtime
# the web pod's fpm workers see.
COPY docker/8.4/php.ini /usr/local/etc/php/conf.d/99-orbital.ini

# fpm pool: Unix socket, run as sail, keep K8s env vars.
RUN sed -i \
        -e 's|^;*listen = .*|listen = /run/php/php-fpm.sock|' \
        -e 's|^;*listen.owner = .*|listen.owner = sail|' \
        -e 's|^;*listen.group = .*|listen.group = sail|' \
        -e 's|^user = www-data|user = sail|' \
        -e 's|^group = www-data|group = sail|' \
        -e 's|^;\?clear_env = .*|clear_env = no|' \
        -e 's|^;\?catch_workers_output = .*|catch_workers_output = yes|' \
        -e 's|^;\?decorate_workers_output = .*|decorate_workers_output = no|' \
        /usr/local/etc/php-fpm.d/www.conf

# Run nginx workers as sail so they can talk to the fpm socket. Reduce
# worker_processes from auto (= CPU count, often dozens in K8s) to a
# fixed 4 — fpm is the bottleneck, not nginx.
RUN sed -i \
        -e 's|^user nginx;|user sail;|' \
        -e 's|^worker_processes .*;|worker_processes 4;|' \
        /etc/nginx/nginx.conf \
    && mkdir -p /run/nginx /run/php \
                /var/lib/nginx/tmp /var/lib/nginx/logs /var/log/nginx \
    && chown -R sail:sail /run/nginx /run/php /var/lib/nginx /var/log/nginx

# Vendor tree from the composer-only builder stage.
COPY --from=vendor /app/vendor ./vendor

# Application source. .dockerignore strips out node_modules, vendor,
# storage runtime state, .env, etc.
COPY . .

# Frontend-build artifacts from stage 1.
COPY --from=frontend /build/public/build ./public/build

# Finalize autoload after the full source is in place. config:cache is
# intentionally NOT run — it freezes env at build time and breaks
# K8s ConfigMap-driven config.
RUN composer dump-autoload --optimize --classmap-authoritative \
    && php artisan view:cache \
    && php artisan event:cache \
    && rm -f /usr/local/bin/composer
# NOTE: `route:cache` is intentionally NOT run here. Livewire 4 serves its
# JS from a hashed path (/livewire-<hash>/livewire.min.js) whose hash is
# computed from runtime config; caching routes at build time freezes an
# hash that never matches the one the running app generates, so the asset
# 404s and Livewire/Filament stops working. Same reason config:cache is
# skipped. Routes register per-request at runtime instead.

# Storage / cache writable by the runtime user.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache \
    && chown -R sail:sail storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Runtime config: nginx site, supervisor program list, entrypoint.
# Alpine nginx includes /etc/nginx/http.d/*.conf (not sites-available).
COPY docker/laravel/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/laravel/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/laravel/entrypoint.sh /usr/local/bin/orbital-laravel-entrypoint
RUN chmod +x /usr/local/bin/orbital-laravel-entrypoint

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/orbital-laravel-entrypoint"]
