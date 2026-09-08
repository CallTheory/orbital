FROM ubuntu:24.04

LABEL maintainer="Orbital"

ARG WWWGROUP=1000
ARG NODE_VERSION=22

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80"

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update && apt-get install -y --no-install-recommends \
    gnupg gosu curl ca-certificates zip unzip git supervisor sqlite3 libcap2-bin \
    python3 dnsutils librsvg2-bin fswatch ffmpeg nano sox libsox-fmt-mp3 \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c' | gpg --dearmor -o /etc/apt/keyrings/ppa_ondrej_php.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    # postgresql-client below is pg_dump / pg_restore for
    # `orbital:backup` and `orbital:restore` — parity with the
    # production image, so a backup taken in dev restores the same way.
    && apt-get install -y --no-install-recommends \
    php8.4-cli php8.4-dev \
    php8.4-pgsql php8.4-sqlite3 \
    php8.4-gd php8.4-curl \
    php8.4-imap php8.4-mbstring \
    php8.4-xml php8.4-zip php8.4-bcmath \
    php8.4-soap php8.4-intl php8.4-readline \
    php8.4-ldap php8.4-msgpack php8.4-igbinary \
    php8.4-redis php8.4-pcov php8.4-xdebug \
    php8.4-imagick php8.4-swoole \
    postgresql-client \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g pnpm \
    && apt-get -y autoremove && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN setcap "cap_net_bind_service=+ep" /usr/bin/php8.4

# Ubuntu 24.04 ships with a default `ubuntu` user at UID 1000 which collides
# with the host developer user. Remove it so we can claim 1000 for `sail`.
RUN userdel -r ubuntu 2>/dev/null || true \
    && groupadd --force -g $WWWGROUP sail \
    && useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1000 sail

COPY start-container /usr/local/bin/start-container
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY php.ini /etc/php/8.4/cli/conf.d/99-sail.ini
RUN chmod +x /usr/local/bin/start-container

EXPOSE 80/tcp

ENTRYPOINT ["start-container"]
