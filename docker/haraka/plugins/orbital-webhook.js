// Haraka plugin: forwards each accepted inbound message to the
// Laravel `/api/mail/inbound` webhook as multipart/form-data.
//
// This is the self-hosted equivalent of SendGrid Inbound Parse:
// Haraka accepts the SMTP conversation, the plugin captures the
// raw RFC822 + envelope at end-of-DATA, POSTs them to Laravel,
// and maps the HTTP response back to an SMTP reply code. Laravel
// owns everything else — parsing, routing, threading, storage.
//
// Hooks used:
//   hook_queue  — fires once the message has been fully DATA'd
//                 and is ready to be "queued for delivery". Our
//                 plugin replaces the default disk-queue behavior
//                 with an HTTP POST.
//
// Env vars (set by docker-compose):
//   ORBITAL_WEBHOOK_URL   — e.g. http://orbital.test/api/mail/inbound
//   ORBITAL_WEBHOOK_TOKEN — shared secret matching
//                           services.inbound_mail.token in Laravel

'use strict';

const http = require('http');
const https = require('https');
const { URL } = require('url');

exports.register = function () {
    const plugin = this;
    plugin.cfg = plugin.config.get('orbital-webhook.ini');

    // Pull runtime config from env. Plugin won't function without
    // both, so fail loudly at register time if either is missing.
    plugin.webhook_url = process.env.ORBITAL_WEBHOOK_URL;
    plugin.webhook_token = process.env.ORBITAL_WEBHOOK_TOKEN;

    if (!plugin.webhook_url || !plugin.webhook_token) {
        plugin.logcrit(
            'orbital-webhook: missing ORBITAL_WEBHOOK_URL or ' +
            'ORBITAL_WEBHOOK_TOKEN env — plugin will reject all mail',
        );
    }

    // Validate each RCPT TO against Laravel before accepting.
    // Splits `{account}@...` or `{account}.{function}@...`, calls
    // `GET /api/mail/validate-recipient?address=...` with the
    // bearer token, and maps the response to an SMTP code:
    //
    //   200 ok → next(OK)          accept, let the sender DATA it
    //   404    → next(DENY, 550)   reject, sender gives up
    //   other  → next(DENYSOFT)    reject soft, sender retries
    //
    // Laravel caches validation per-address for 60s so retry
    // bursts don't hammer the DB. This runs inside the SMTP
    // conversation before the sender sends any DATA, so junk
    // mail for unknown account numbers never touches MinIO.
    plugin.register_hook('rcpt', 'validate_recipient');

    plugin.register_hook('queue', 'forward_to_laravel');
};

exports.validate_recipient = function (next, connection, params) {
    const plugin = this;
    const rcpt = params && params[0];
    if (!rcpt) {
        return next(DENYSOFT, 'Missing recipient');
    }
    const address = rcpt.address();

    // Derive the validation URL from the webhook URL (same
    // host/port/scheme, different path). Cheaper than adding a
    // second env var.
    const validationUrl = plugin.webhook_url.replace(/\/inbound\/?$/, '/validate-recipient');
    const url = new URL(validationUrl);
    url.searchParams.set('address', address);
    const lib = url.protocol === 'https:' ? https : http;

    const req = lib.request({
        method: 'GET',
        protocol: url.protocol,
        hostname: url.hostname,
        port: url.port || (url.protocol === 'https:' ? 443 : 80),
        path: url.pathname + url.search,
        headers: {
            'Authorization': `Bearer ${plugin.webhook_token}`,
            'Accept': 'application/json',
        },
        timeout: 5000,
    }, (res) => {
        res.on('data', () => {});
        res.on('end', () => {
            if (res.statusCode === 200) {
                plugin.loginfo(`orbital-webhook: accepted ${address}`);
                return next(OK);
            }
            if (res.statusCode === 404) {
                plugin.logwarn(`orbital-webhook: rejected unknown recipient ${address}`);
                return next(DENY, `550 5.1.1 <${address}>: Recipient address rejected: Unknown account`);
            }
            plugin.logerror(`orbital-webhook: validation returned ${res.statusCode}; deferring`);
            return next(DENYSOFT, `Recipient validation temporarily unavailable`);
        });
    });

    req.on('error', (err) => {
        plugin.logerror(`orbital-webhook: validation request failed: ${err.message}`);
        next(DENYSOFT, 'Recipient validation unreachable');
    });

    req.on('timeout', () => {
        req.destroy();
        plugin.logerror('orbital-webhook: validation request timed out');
        next(DENYSOFT, 'Recipient validation timed out');
    });

    req.end();
};

exports.forward_to_laravel = function (next, connection) {
    const plugin = this;
    const txn = connection.transaction;

    if (!plugin.webhook_url || !plugin.webhook_token) {
        return next(DENYSOFT, 'Mail gateway not configured');
    }

    // Collect the full RFC822 message into a Buffer via
    // `message_stream.get_data(cb)` — Haraka's message_stream is
    // a paused Readable, so you can't just attach a 'data'
    // listener and expect events to flow. `get_data` pipes it
    // into an internal GetDataStream and fires the callback once
    // the whole message has been buffered.
    txn.message_stream.get_data((raw) => {

        // Build a multipart/form-data body by hand. Avoids pulling
        // in a full FormData polyfill for the one POST this plugin
        // ever does. Boundary is a random string unique per message.
        const boundary = '----OrbitalMail' + Math.random().toString(36).slice(2);
        const CRLF = '\r\n';
        const parts = [];

        // envelope_from
        if (txn.mail_from && txn.mail_from.address) {
            parts.push(Buffer.from(
                `--${boundary}${CRLF}` +
                `Content-Disposition: form-data; name="envelope_from"${CRLF}${CRLF}` +
                `${txn.mail_from.address()}${CRLF}`,
            ));
        }

        // envelope_to[] — one part per recipient
        for (const rcpt of (txn.rcpt_to || [])) {
            parts.push(Buffer.from(
                `--${boundary}${CRLF}` +
                `Content-Disposition: form-data; name="envelope_to[]"${CRLF}${CRLF}` +
                `${rcpt.address()}${CRLF}`,
            ));
        }

        // raw — the RFC822 message as a file upload
        parts.push(Buffer.from(
            `--${boundary}${CRLF}` +
            `Content-Disposition: form-data; name="raw"; filename="message.eml"${CRLF}` +
            `Content-Type: message/rfc822${CRLF}${CRLF}`,
        ));
        parts.push(raw);
        parts.push(Buffer.from(`${CRLF}--${boundary}--${CRLF}`));

        const body = Buffer.concat(parts);
        const url = new URL(plugin.webhook_url);
        const lib = url.protocol === 'https:' ? https : http;

        const req = lib.request({
            method: 'POST',
            protocol: url.protocol,
            hostname: url.hostname,
            port: url.port || (url.protocol === 'https:' ? 443 : 80),
            path: url.pathname + url.search,
            headers: {
                'Content-Type': `multipart/form-data; boundary=${boundary}`,
                'Content-Length': body.length,
                'Authorization': `Bearer ${plugin.webhook_token}`,
                'Accept': 'application/json',
            },
            timeout: Number(plugin.cfg.main && plugin.cfg.main.timeout) || 10000,
        }, (res) => {
            // Drain the response body even though we don't need it
            // — skipping this leaks sockets on some Node versions.
            res.on('data', () => {});
            res.on('end', () => {
                if (res.statusCode >= 200 && res.statusCode < 300) {
                    plugin.loginfo(
                        `orbital-webhook: forwarded to Laravel ` +
                        `(status ${res.statusCode}, ${raw.length} bytes, ` +
                        `to ${(txn.rcpt_to || []).map(r => r.address()).join(',')})`,
                    );
                    next(OK, 'Message queued for processing');
                } else {
                    plugin.logwarn(
                        `orbital-webhook: Laravel returned ${res.statusCode}; ` +
                        `deferring for retry`,
                    );
                    next(DENYSOFT, `Mail gateway returned ${res.statusCode}`);
                }
            });
        });

        req.on('error', (err) => {
            plugin.logerror(`orbital-webhook: POST failed: ${err.message}`);
            next(DENYSOFT, 'Mail gateway unreachable');
        });

        req.on('timeout', () => {
            req.destroy();
            plugin.logerror('orbital-webhook: POST timed out');
            next(DENYSOFT, 'Mail gateway timed out');
        });

        req.write(body);
        req.end();
    });

    txn.message_stream.on('error', (err) => {
        plugin.logerror(`orbital-webhook: stream read failed: ${err.message}`);
        next(DENYSOFT, 'Mail gateway stream error');
    });
};
