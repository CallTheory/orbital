# Admin panel map

Every page in the admin panel, what it does, and which documentation page
covers it. Useful when you know what you want to change and not where it
lives.

The panel groups pages by the kind of work they belong to rather than by the
model behind them, so a few things are not where a database schema would put
them — ring strategy under Workflow, portal branding under Preferences.

## Monitor

| Page | Does | Documented in |
|---|---|---|
| **System Status** | Health cards for every component. The panel home page | [Monitoring](../admin/monitoring.md) |
| **Call Logs** | Every call across every client, with recordings | [Monitoring](../admin/monitoring.md) |
| **Failed Inbound Mail** | Email the router could not place on a client | [Monitoring](../admin/monitoring.md) |

## Customers

| Page | Does | Documented in |
|---|---|---|
| **Clients** | Customer accounts and everything scoped to one | [Clients](../admin/clients.md) |
| **Users** | Cross-cutting view of every login account anywhere | [Users & Roles](../admin/users-roles.md) |

### Inside a client

| Sub-page | Does | Documented in |
|---|---|---|
| **Edit** | The client record — details, quotas, permission ceiling, recording, voicemail | [Clients](../admin/clients.md) |
| **Numbers** | Inbound phone numbers (DIDs) | [Call Routing](../admin/call-routing.md) |
| **Messaging Numbers** | Carrier sender pools for text | [Messaging](../admin/messaging.md) |
| **Do Not Text** | The client's opt-out list | [Messaging](../admin/messaging.md) |
| **Extensions** | This client's SIP extensions — AI agents and virtual numbers | [Telephony Infrastructure](../admin/telephony.md) |
| **Channels** | Call, email, message, and chat queues, as tabs | [Channels](../admin/channels.md) |
| **Orchestrations** | This client's intake scripts | [Orchestrations](../admin/orchestrations.md) |
| **Personas** | This client's AI agents | [AI Agents](../admin/ai-agents.md) |
| **Users** | Client contacts with portal access | [Users & Roles](../admin/users-roles.md) |
| **Directory** | The client's phone book | [Directories](../admin/directory.md) |
| **Directory Fields** | The schema behind that phone book | [Directories](../admin/directory.md) |

## Conversational AI

| Page | Does | Documented in |
|---|---|---|
| **Providers** | API keys for LLM, speech-to-text, and text-to-speech | [Platform Settings](../admin/platform-settings.md) |
| **Personalities** | Platform-wide persona templates | [AI Agents](../admin/ai-agents.md) |
| **Voices** | The catalog of TTS voices personas can use | [AI Agents](../admin/ai-agents.md) |
| **Knowledge Stores** | Per-client document retrieval, all clients in one list | [Knowledge Bases](../admin/knowledge-bases.md) |

## Workflow

| Page | Does | Documented in |
|---|---|---|
| **Orchestrations** | Platform-shared intake scripts | [Orchestrations](../admin/orchestrations.md) |
| **Intake Goals** | The shared vocabulary orchestrations are built from | [Orchestrations](../admin/orchestrations.md) |
| **Queue Strategies** | Ring strategy, timeout, and retry templates for agent groups | [Agent Groups](../admin/agent-groups.md) |
| **Dispatch Rules** | A stub. Cross-client routing logic, not yet built | [Roadmap](../roadmap.md) |

## Telephony

| Page | Does | Documented in |
|---|---|---|
| **SIP Trunks** | Carrier connections | [Telephony Infrastructure](../admin/telephony.md) |
| **Phone Extensions** | Hardware phones, ATAs, and standalone SIP clients | [Telephony Infrastructure](../admin/telephony.md) |
| **Hold Music** | Asterisk music-on-hold classes | [Telephony Infrastructure](../admin/telephony.md) |
| **Asterisk Backends** | The node registry Kamailio dispatches to | [Telephony Infrastructure](../admin/telephony.md) |
| **rtpengine Nodes** | The media relay registry | [Telephony Infrastructure](../admin/telephony.md) |
| **SIP Proxy** | Live Kamailio state, and draining a backend | [Telephony Infrastructure](../admin/telephony.md) |
| **Settings** | Unmatched inbound calls, and outage handling | [Telephony Infrastructure](../admin/telephony.md) |

## Platform

| Page | Does | Documented in |
|---|---|---|
| **Staff** | Platform users, their roles, and their softphone extensions | [Users & Roles](../admin/users-roles.md) |
| **Groups** | Agent groups — the operators a queue rings | [Agent Groups](../admin/agent-groups.md) |
| **Roles** | Team-less platform roles and their permissions | [Users & Roles](../admin/users-roles.md) |

## Preferences

| Page | Does | Documented in |
|---|---|---|
| **Portal Branding** | What your clients see, on the portal and login page | [Platform Settings](../admin/platform-settings.md) |
| **Availability Reasons** | The states an operator can be in | [Availability](../operator/availability.md) |
| **Logout Reasons** | The vocabulary of the sign-out prompt | [Security](../admin/security.md) |
| **Shared Directories** | Phone books curated by you and attached to clients | [Directories](../admin/directory.md) |

## System

| Page | Does | Documented in |
|---|---|---|
| **Settings** | The main configuration page | [Platform Settings](../admin/platform-settings.md) |
| **Branding** | What your staff see | [Platform Settings](../admin/platform-settings.md) |
| **Setup** | Initialize external services. The web form of `orbital:bootstrap` | [System Maintenance](../admin/system-maintenance.md) |
| **Tools** | An allowlist of artisan commands, runnable from the browser | [System Maintenance](../admin/system-maintenance.md) |
| **Certificates** | TLS certificate status and renewal | [System Maintenance](../admin/system-maintenance.md) |
| **Failover** | The HA incident console | [High Availability](../admin/high-availability.md) |
| **About** | Version, commit, license, and source. Not super-admin gated | [System Maintenance](../admin/system-maintenance.md) |

## Operator panel

| Page | Does | Documented in |
|---|---|---|
| **Workspace** | Load a client, take a message, follow a script | [Workspace](../operator/workspace.md) |
| **Email Inbox** | Claim and reply to email threads | [Email Inbox](../operator/email-inbox.md) |
| **Messages** | SMS and MMS conversations | [Message Inbox](../operator/message-inbox.md) |

The softphone is not a page. It sits at the bottom of the operator panel and
follows you between them. See [Softphone](../operator/softphone.md).

## Client portal

| Page | Does | Documented in |
|---|---|---|
| **Overview** | Recent activity at a glance | [Client Portal](../portal/index.md) |
| **Call History** | Calls taken on their behalf | [Call History](../portal/call-history.md) |
| **Text Conversations** | Texts handled on their behalf | [Text Conversations](../portal/text-conversations.md) |
| **Roles** | Client-side role management, where the permission ceiling allows it | [Client Portal](../portal/index.md) |

## Pages every panel shares

**Security** — the user's own two-factor, password, and sessions. **About** —
the version and source offer, reachable by any authenticated user on any panel,
because the AGPL source offer runs to everyone rather than to administrators.

## See also

- [Environment Variables](environment.md) — settings that are not in the panel at all
- [How Orbital Works](../concepts.md) — the model these pages configure
