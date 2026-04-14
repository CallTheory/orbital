FROM debian:bookworm-slim AS builder

ARG ASTERISK_VERSION=22-current

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    ca-certificates \
    curl \
    libedit-dev \
    libjansson-dev \
    libsqlite3-dev \
    libssl-dev \
    libxml2-dev \
    libxslt1-dev \
    libncurses5-dev \
    libsrtp2-dev \
    uuid-dev \
    libspeex-dev \
    libspeexdsp-dev \
    libopus-dev \
    libgsm1-dev \
    libcurl4-openssl-dev \
    libnewt-dev \
    libpopt-dev \
    libiksemel-dev \
    pkg-config \
    python3 \
    subversion \
    unixodbc-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /usr/src

RUN curl -fsSL "https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-${ASTERISK_VERSION}.tar.gz" \
    | tar xz --strip-components=1

# Configure with the modules we need
RUN ./configure \
    --with-jansson-bundled \
    --with-pjproject-bundled \
    --with-crypto \
    --with-ssl \
    --with-srtp

# Enable modules via menuselect
RUN make menuselect.makeopts \
    && menuselect/menuselect \
        --enable res_pjsip \
        --enable res_pjsip_session \
        --enable res_pjsip_registrar \
        --enable res_pjsip_authenticator_digest \
        --enable res_pjsip_caller_id \
        --enable res_pjsip_endpoint_identifier_ip \
        --enable res_pjsip_endpoint_identifier_user \
        --enable res_pjsip_outbound_authenticator_digest \
        --enable res_pjsip_transport_websocket \
        --enable res_http_websocket \
        --enable res_ari \
        --enable res_ari_channels \
        --enable res_ari_bridges \
        --enable res_ari_endpoints \
        --enable res_stasis \
        --enable res_musiconhold \
        --enable res_parking \
        --enable app_confbridge \
        --enable app_voicemail \
        --enable app_queue \
        --enable app_dial \
        --enable app_playback \
        --enable app_echo \
        --enable app_verbose \
        --enable app_page \
        --enable app_chanspy \
        --enable app_stack \
        --enable codec_ulaw \
        --enable codec_alaw \
        --enable codec_gsm \
        --enable codec_opus \
        --enable codec_g722 \
        --enable format_wav \
        --enable format_wav_gsm \
        --enable format_gsm \
        --enable format_pcm \
        --enable format_sln \
        --enable pbx_config \
        --enable func_callerid \
        --enable func_channel \
        --enable func_pjsip_endpoint \
        --enable cdr_csv \
        --enable bridge_simple \
        --enable bridge_native_rtp \
        --enable chan_bridge_media \
        menuselect.makeopts

RUN make -j$(nproc) && make install && make samples

# Runtime image
FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    libedit2 \
    libjansson4 \
    libsqlite3-0 \
    libssl3 \
    libxml2 \
    libxslt1.1 \
    libncurses6 \
    libsrtp2-1 \
    uuid-runtime \
    libspeex1 \
    libspeexdsp1 \
    libopus0 \
    libgsm1 \
    libcurl4 \
    libnewt0.52 \
    ca-certificates \
    openssl \
    sox \
    mpg123 \
    && rm -rf /var/lib/apt/lists/* \
    && adduser --system --group --no-create-home asterisk

COPY --from=builder /usr/lib/asterisk /usr/lib/asterisk
# Asterisk installs libasteriskssl / libasteriskpj into /usr/lib directly
# alongside the asterisk subdir. Without these copies the runtime asterisk
# binary fails at startup with "libasteriskssl.so.1: cannot open shared
# object file".
COPY --from=builder /usr/lib/libasterisk*.so* /usr/lib/
COPY --from=builder /usr/sbin/asterisk /usr/sbin/asterisk
COPY --from=builder /var/lib/asterisk /var/lib/asterisk
COPY --from=builder /var/spool/asterisk /var/spool/asterisk
COPY --from=builder /etc/asterisk /etc/asterisk

# Bake Orbital's baseline Asterisk configs into the image. These are
# the static/infrastructure files — every tenant install needs them
# identically and they don't depend on DB state. Tenant-owned content
# (endpoints, dialplan, queues) is rendered by the Laravel config
# generator into /etc/asterisk/generated/*.conf (mounted read-only from
# the host) and #tryinclude'd from the baked files below.
COPY manager.conf    /etc/asterisk/manager.conf
COPY pjsip.conf      /etc/asterisk/pjsip.conf
COPY http.conf       /etc/asterisk/http.conf
COPY rtp.conf        /etc/asterisk/rtp.conf
COPY extensions.conf /etc/asterisk/extensions.conf
COPY queues.conf     /etc/asterisk/queues.conf

# Entrypoint generates a self-signed TLS cert on first boot if none is
# present, so SIP TLS (5061) and WSS (8089) work out of the box. Mount
# a real cert pair over /etc/asterisk/keys in production to override.
COPY entrypoint.sh /usr/local/bin/orbital-asterisk-entrypoint

RUN chmod +x /usr/local/bin/orbital-asterisk-entrypoint \
    && ldconfig \
    && mkdir -p /var/run/asterisk /var/log/asterisk /etc/asterisk/keys /etc/asterisk/generated \
    && chown -R asterisk:asterisk /var/lib/asterisk /var/spool/asterisk /var/run/asterisk /var/log/asterisk /etc/asterisk

EXPOSE 5038/tcp 5060/udp 5060/tcp 5061/tcp 8089/tcp 10000-10099/udp

USER asterisk

ENTRYPOINT ["/usr/local/bin/orbital-asterisk-entrypoint"]
CMD []
