# System Maintenance

The **System** group holds the surfaces for the installation itself — bringing
external services up, running maintenance commands, certificates, and what
version you are on.

All of it is super-admin only, except About.

## Setup

**System → Setup** is the web counterpart to `php artisan orbital:bootstrap`.
It shows every external service Orbital needs initialized as a card with its
current status and an **Install / Re-run** button.

Bootstrappers cover:

| Bootstrapper | Does |
|---|---|
| **S3 buckets** | Creates the object storage buckets for recordings, media, and attachments |
| **pgvector** | Ensures the `vector` extension exists — required by knowledge bases |
| **Asterisk** | Writes the initial generated configuration |
| **LiveKit** | Provisions the SIP trunk and dispatch rules for AI agent extensions |
| **Icecast** | Sets up the hold-music stream |
| **Ollama** | Pulls local models, where you run them |
| **SSO secrets** | Populates and rotates the internal service credentials |

Re-running a bootstrapper is safe — they check before they act. This is the
first page to visit after a fresh install, and the page to come back to when a
component you did not deploy at first becomes one you now want.

## Tools

**System → Tools** runs a curated list of artisan commands from the browser.

| Tool | Command |
|---|---|
| Run scheduled tasks now | `schedule:run` |
| Installation identity | `orbital:about` |
| Collect metrics now | `orbital:collect-metrics` |
| Full system status | `orbital:status` |
| Clear application cache | `cache:clear` |
| Clear compiled config | `config:clear` |
| Clear compiled views | `view:clear` |
| Horizon status | `horizon:status` |
| Retry failed jobs | `queue:retry all` |
| Regenerate and push dialplan | `orbital:generate-config --push` |
| Re-sync realtime tables | `orbital:resync-realtime` |
| Roll up queue metrics | `orbital:roll-up-queue-metrics` |

**The list is an explicit allowlist, not the command registry.** This is a
remote execution surface on a web page, so what it can fire is written out in
one file where it can be read in full.

**Destructive commands are deliberately absent.** Anything that would wipe
data — `migrate:fresh`, `queue:flush`, and their relatives — stays CLI-only.
The blast radius of an accidental click outweighs the convenience of not
opening a shell. [Restoring a backup](backups.md) is CLI-only for the same
reason, plus a better one: the moment you need a restore is the moment the
admin UI is most likely to be the broken thing.

## Certificates

**System → Certificates** monitors the platform's wildcard TLS certificate:
its domain, issuer, expiry with color-coded urgency, and which services consume
it. Actions issue a new certificate or force a renewal.

It is mostly a monitoring surface. **Renewal happens on its own** through the
ACME client's own schedule and a deploy hook — this page is where you look to
confirm that it did, not the thing that makes it happen.

The page hides itself when ACME is not configured, so installs terminating TLS
somewhere else do not see a dead nav item.

## About

**System → About** names the exact version, commit, release channel, and
license of this installation, and links to the corresponding source.

It is the first thing to grab for a bug report — "what am I running" answered
without shelling into a container.

**It is deliberately not super-admin gated.** Orbital is AGPL, and section 13
requires the source offer to reach all users interacting with the software over
a network, not just the one account that already has shell access. Any
authenticated platform user can read this page, and portal users get the same
offer in the panel footer. See [Licensing](../getting-started/installation.md).

## Settings

**System → Settings** is the main configuration page — mail, sessions,
security, logging, telephony credentials, recording defaults, and the optional
observability integrations. See [Platform Settings](platform-settings.md).

## Branding

**System → Branding** is what your staff see. Portal branding — what your
clients see — is separate, under **Preferences → Portal Branding**. See
[Platform Settings](platform-settings.md).

## Failover

**System → Failover** is the HA incident console: per-tier live state and the
response actions an on-call operator would otherwise run by hand against
Patroni, Sentinel, and HAProxy. Every action is written to an audit log with
actor, target, and the raw control-plane output. See
[High Availability](high-availability.md).

## See also

- [Platform Settings](platform-settings.md) — what is configurable and where
- [Monitoring](monitoring.md) — system status and call logs
- [Backups](backups.md) — the one thing that is deliberately CLI-only
