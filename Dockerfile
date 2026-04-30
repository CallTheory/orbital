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
FROM node:22-slim AS frontend

WORKDIR /build

RUN corepack enable \
    && corepack prepare pnpm@latest --activate

COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

COPY . .
RUN pnpm run build

# ---------- Stage 2: production runtime ----------
FROM ubuntu:24.04 AS production

LABEL maintainer="Orbital"

ARG WWWGROUP=1000
WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# PHP 8.4 + nginx + supervisor. Mirrors `docker/8.4/Dockerfile` for
# extension parity with dev (Filament, Livewire, the agent-worker API,
# the recording pipeline, and the SipJS softphone all assume the same
# extension set), with `php8.4-fpm` and `nginx` added on top.
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        gnupg gosu curl ca-certificates zip unzip git supervisor sqlite3 libcap2-bin \
        nginx \
        python3 dnsutils librsvg2-bin fswatch ffmpeg sox libsox-fmt-mp3 \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c' | gpg --dearmor -o /etc/apt/keyrings/ppa_ondrej_php.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-fpm \
        php8.4-pgsql php8.4-sqlite3 \
        php8.4-gd php8.4-curl \
        php8.4-imap php8.4-mbstring \
        php8.4-xml php8.4-zip php8.4-bcmath \
        php8.4-soap php8.4-intl php8.4-readline \
        php8.4-ldap php8.4-msgpack php8.4-igbinary \
        php8.4-redis \
        php8.4-imagick php8.4-swoole \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && apt-get -y autoremove && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Ubuntu 24.04 ships a default `ubuntu` user at uid 1000; remove it so
# we can claim 1000 for `sail` (matching the dev image's uid contract).
RUN userdel -r ubuntu 2>/dev/null || true \
    && groupadd --force -g $WWWGROUP sail \
    && useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1000 sail

# Shared php.ini overrides — same as dev. Applied to both CLI and FPM
# SAPIs so the artisan-driven Horizon / Reverb pods share the runtime
# the web pod's fpm workers see.
COPY docker/8.4/php.ini /etc/php/8.4/cli/conf.d/99-orbital.ini
COPY docker/8.4/php.ini /etc/php/8.4/fpm/conf.d/99-orbital.ini

# Override the fpm pool: Unix socket, run as sail, keep K8s env vars.
RUN sed -i \
        -e 's|^listen = .*|listen = /run/php/php8.4-fpm.sock|' \
        -e 's|^;\?listen.owner = .*|listen.owner = sail|' \
        -e 's|^;\?listen.group = .*|listen.group = sail|' \
        -e 's|^user = www-data|user = sail|' \
        -e 's|^group = www-data|group = sail|' \
        -e 's|^;\?clear_env = .*|clear_env = no|' \
        -e 's|^;\?catch_workers_output = .*|catch_workers_output = yes|' \
        -e 's|^;\?decorate_workers_output = .*|decorate_workers_output = no|' \
        /etc/php/8.4/fpm/pool.d/www.conf

# Run nginx workers as sail so they can talk to the fpm socket. Reduce
# the default worker_processes from `auto` (= CPU count, often dozens
# in K8s) to a fixed 4 — fpm is the bottleneck, not nginx.
RUN sed -i \
        -e 's|^user www-data;|user sail;|' \
        -e 's|^worker_processes .*;|worker_processes 4;|' \
        /etc/nginx/nginx.conf

# Composer install first, before the source copy, so layer cache
# survives code-only changes.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev --no-scripts --no-autoloader \
        --prefer-dist --no-interaction

# Application source. .dockerignore strips out node_modules, vendor,
# storage runtime state, .env, etc.
COPY . .

# Frontend-build artifacts from stage 1.
COPY --from=frontend /build/public/build ./public/build

# Finalize autoload after the full source is in place. config:cache is
# intentionally NOT run — it freezes env at build time and breaks
# K8s ConfigMap-driven config.
RUN composer dump-autoload --optimize --classmap-authoritative \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan event:cache

# Storage / cache writable by the runtime user.
RUN mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chown -R sail:sail storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Runtime config: nginx site, supervisor program list, entrypoint.
COPY docker/laravel/nginx.conf /etc/nginx/sites-available/default
COPY docker/laravel/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/laravel/entrypoint.sh /usr/local/bin/orbital-laravel-entrypoint
RUN chmod +x /usr/local/bin/orbital-laravel-entrypoint

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/orbital-laravel-entrypoint"]
