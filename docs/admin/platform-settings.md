# Platform Settings

Platform Settings is where the installation itself is configured — branding,
provider credentials, and the infrastructure the app talks to. It is
super-admin only.

Most settings can be changed here rather than in configuration files, and
values set here take precedence over the environment. Secrets are stored
encrypted. See [Environment Variables](../reference/environment.md) for what
is deliberately left in `.env` and why.

## Where the settings are

They are not all on one page. The settings that shape a particular kind of work
sit next to the thing they shape.

| Page | Holds |
|---|---|
| **System → Settings** | The main page: application, mail, inbound mail, sessions, security, logging, broadcasting, Icecast, Asterisk, LiveKit, the agent worker, call recording, knowledge, tracing, error reporting, and Telescope |
| **System → Branding** | What your staff see — admin panel and operator workspace |
| **Preferences → Portal Branding** | What your clients see — the portal and the shared login page |
| **Conversational AI → Providers** | API keys for the LLM, speech-to-text, and text-to-speech services |
| **Telephony → Settings** | Unmatched inbound calls and outage handling. See [Telephony Infrastructure](telephony.md) |

## Branding

Two separate identities, because they have different audiences.

**Platform branding** (System → Branding) is what your staff see. Your company
name, logo, and colors.

**Portal branding** (Preferences → Portal Branding) is what your clients see,
on the portal and the shared login page every user hits before being routed to
their panel. This is the one their businesses look at, so it is worth getting
right; a client logging in should see the company they hired.

## AI providers

**Conversational AI → Providers** holds the credentials for the language,
speech, and voice services Orbital can use. It sits in the Conversational AI
group on purpose — next to the personas, voices, and flows whose behavior it
governs.

**Nothing AI works until a model provider key is set here.** If agents are
silent, emails go unanswered, or text auto-replies never fire, check this page
before anything else — an unconfigured key is by far the most common cause, and
the symptom looks like a broken feature rather than a missing setting.

## Mail

Two halves, both on System → Settings.

**Mail** is outbound — notifications, password resets, voicemail deliveries,
and messages sent to clients.

**Inbound Mail** is the path that accepts mail on your clients' behalf: the
SMTP shim's shared token and the domain its MX record points at. Both must
match the shim's own environment, which the helper text says on the field. See
[Email Routing](email-routing.md).

## Telephony and media

**Asterisk** and **LiveKit** carry the connection details for the SIP and media
layer — the interfaces Orbital uses to place and monitor calls and to generate
configuration. **Agent Worker** carries what the Python AI worker needs to
reach the platform.

These are infrastructure settings. Changing them affects live calls, so make
changes in a maintenance window rather than mid-shift.

## Call recording

Platform defaults for recording: the master switch, format, retention, storage
disk, beep behavior, and the disclosure message. Clients override these per
account. See [Call Recording & Voicemail](recording.md).

## Optional integrations

Both are off by default and neither is required.

- **Error reporting** — ships unhandled exceptions to GlitchTip, Sentry, or
  anything speaking the same API
- **Tracing** — distributed traces over OTLP to Grafana Tempo or any
  compatible backend

Read [Observability](observability.md) before enabling either. Both strip
caller data before anything leaves the platform, but pointing them at a hosted
service still exports operational data to a third party.

## Restarting after a change

**Some changes need a restart to take effect everywhere.**

Web requests pick up a new setting immediately. Background workers read
configuration once when they start, so they keep using the old value until
restarted.

Settings that need one carry a **Requires restart** badge naming what to
restart. The page can restart two things itself:

| Target | Covers |
|---|---|
| **Horizon** | The queue workers — and so jobs, AI processing, and mail |
| **Asterisk** | The SIP layer, after a telephony configuration change |

**Two things it cannot restart for you.** The Reverb real-time server, and the
Python agent worker — which reads environment variables only and never sees a
setting made in the admin UI at all. Restart those the way you deploy them.

If a setting appears to have no effect, an unrestarted worker is the usual
reason.

## See also

- [Environment Variables](../reference/environment.md) — which settings live where, and why
- [System Maintenance](system-maintenance.md) — setup, tools, and certificates
- [Security](security.md) — two-factor and access control
- [Observability](observability.md) — the optional integrations in detail
