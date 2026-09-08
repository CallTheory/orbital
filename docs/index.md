# Orbital Documentation

Orbital is a multi-tenant answering-service platform that combines AI voice agents, live human operators, and multi-channel inbound handling — voice, email, web chat, and SMS/MMS — into a unified workspace. A call-center company (the "platform operator") runs Orbital to take calls and handle messages on behalf of their customers ("clients").

Orbital is free software under the [AGPL-3.0](https://www.gnu.org/licenses/agpl-3.0.html). Self-hosting is free forever with no feature gates and no seat counting.

## For Administrators

- [Clients](admin/clients.md) — creating and configuring customer accounts
- [Email Routing](admin/email-routing.md) — how inbound email flows from SMTP to operator inbox
- [Messaging](admin/messaging.md) — SMS/MMS: provisioning numbers, routing, threading, AI auto-reply
- [Agent Groups](admin/agent-groups.md) — organizing operators into teams for queue assignment
- [Users & Roles](admin/users-roles.md) — platform staff, client contacts, and permissions
- [High Availability & Maintenance](admin/high-availability.md) — per-tier maintenance, Failover Central, SIP Proxy, backups, troubleshooting
- [Observability](admin/observability.md) — metrics, logs, and alerting out of the box; optional tracing (Tempo/OTLP) and error reporting (GlitchTip/Sentry)
- [Backups](admin/backups.md) — encrypted nightly database backups, the restore path, and why HA is not a backup

## For Operators

- [Email Inbox](operator/email-inbox.md) — working email threads: claim, reply, forward, close
- [Softphone](operator/softphone.md) — browser-based SIP phone for handling calls
