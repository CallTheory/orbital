# Orbital

Orbital is a multi-tenant answering service platform. It combines AI voice
agents, live human operators, email, and text messaging into one workspace,
so a call-center business can take calls and messages on behalf of many
customers at once.

If you are new here, read [How Orbital Works](concepts.md) first. It
explains the handful of ideas — clients, channels, queues, operators,
agents — that the rest of the documentation assumes.

## Who this documentation is for

Orbital has three kinds of user, and they never see each other's screens.

| You are | You use | Start here |
|---|---|---|
| **An operator** taking calls and messages all day | The operator workspace | [Operator Guide](operator/workspace.md) |
| **An administrator** configuring the platform and its customers | The admin panel | [Admin Guide](admin/clients.md) |
| **A client** — a customer of the answering service | The client portal | [Client Portal](portal/index.md) |

There is a fourth audience: whoever installs and runs the software. That is
[Getting Started](getting-started/installation.md).

Where the project is going is on the [Roadmap](roadmap.md).

## For operators

Working a shift: taking messages, answering calls, and handling the two
text-based channels.

- [Workspace](operator/workspace.md) — the main screen: pulling up a client, taking a message
- [Softphone](operator/softphone.md) — answering, transferring, and holding calls in the browser
- [Email Inbox](operator/email-inbox.md) — claiming and replying to email threads
- [Message Inbox](operator/message-inbox.md) — SMS and MMS conversations
- [Availability](operator/availability.md) — going available, on break, or unavailable

## For administrators

Setting the platform up and keeping it running.

**Customers and routing**

- [Clients](admin/clients.md) — creating and configuring customer accounts
- [Channels](admin/channels.md) — how voice, email, text, and chat fit together
- [Call Routing](admin/call-routing.md) — numbers, queues, and who answers
- [Telephony Infrastructure](admin/telephony.md) — trunks, extensions, and the SIP edge
- [Email Routing](admin/email-routing.md) — inbound mail from SMTP to operator inbox
- [Messaging](admin/messaging.md) — SMS/MMS provisioning, threading, and consent
- [Call Recording & Voicemail](admin/recording.md) — recording, disclosure, and transcription
- [Directories](admin/directory.md) — the phone books agents and operators look up

**AI and scripting**

- [AI Agents](admin/ai-agents.md) — personas, voices, and how agents answer
- [Orchestrations](admin/orchestrations.md) — the flows that drive both AI and human intake
- [Knowledge Bases](admin/knowledge-bases.md) — giving an agent your customer's documents

**People and platform**

- [Agent Groups](admin/agent-groups.md) — organizing operators into teams
- [Users & Roles](admin/users-roles.md) — staff, client contacts, and permissions
- [Security](admin/security.md) — two-factor enforcement and access control
- [Platform Settings](admin/platform-settings.md) — branding, providers, and system configuration

**Operations**

- [Monitoring](admin/monitoring.md) — system status, call logs, and failed inbound mail
- [Observability](admin/observability.md) — metrics, logs, alerting, and optional tracing
- [System Maintenance](admin/system-maintenance.md) — setup, tools, certificates, and version
- [High Availability](admin/high-availability.md) — per-tier maintenance and failover
- [Backups](admin/backups.md) — encrypted database backups and the restore path
- [Deploying to Kubernetes](getting-started/vultr-vke.md) — a worked example on Vultr

**Reference**

- [Admin Panel Map](reference/admin-navigation.md) — every page, and which guide covers it
- [Environment Variables](reference/environment.md) — every setting, and which live in the admin UI
- [Third-Party Licenses](reference/third-party-licenses.md) — what Orbital bundles

## For clients

What your customers see when they log in to check their messages.

- [Client Portal](portal/index.md) — the overview
- [Call History](portal/call-history.md) — calls taken on their behalf
- [Text Conversations](portal/text-conversations.md) — texts handled on their behalf

The portal is deliberately narrow, and one thing it does not carry yet is a
message list. See the [Roadmap](roadmap.md).

## Licensing

Orbital is [AGPL-3.0](https://www.gnu.org/licenses/agpl-3.0.html). You can
self-host it, for unlimited clients, seats, calls, and channels, forever, at
no cost. Nothing is feature-gated: there is no paid tier of the software and
no capability is withheld from a self-hoster.

What you can buy is hosting, support, or managed services. Those are people
and infrastructure, not code paths.
