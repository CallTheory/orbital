# Roadmap

What is built, what is being worked on, and what is deliberately not
planned. No dates — this is a statement of intent, not a schedule.

If something you need is missing, that is worth telling us. The order below
reflects our judgement about what matters most, and judgement is the part
most improved by being argued with.

## Shipped

**Channels.** Voice, email, and SMS/MMS, each with queues, agent group
assignment, and AI or human handling, configured from one Channels hub per
client. Web chat is partly built — see below. See
[Channels](admin/channels.md).

**AI agents.** Configurable personas — voice, model, instructions — driven by
shared intake scripts, so a call that escalates from an agent to a person
continues rather than restarting. See [AI Agents](admin/ai-agents.md).

**Orchestrations.** A visual flow editor for intake scripts, per-client or
shared platform-wide, driving both the AI and the operator's on-screen
prompts from one definition. See [Orchestrations](admin/orchestrations.md).

**Knowledge bases.** Per-client document retrieval so agents answer from the
client's own material, with hard isolation between clients. See
[Knowledge Bases](admin/knowledge-bases.md).

**Messaging compliance.** Carrier sender pools required rather than optional,
STOP/START/HELP handling, a platform-wide suppression list enforced on every
sending path, and MMS media copied off the carrier into your own storage. See
[Messaging](admin/messaging.md).

**Operations.** Metrics and dashboards out of the box, alerting with SMTP and
webhook delivery, encrypted database backups with a tested restore path, and
optional tracing and error reporting that strip caller data before anything
leaves the platform. See [Observability](admin/observability.md) and
[Backups](admin/backups.md).

**High availability.** Single-site active/active across every
customer-facing tier. See [High Availability](admin/high-availability.md).

**Security.** Two-factor enforcement with per-client grace windows, role
separation between platform staff and client contacts, and permission-gated
administrative tooling. See [Security](admin/security.md).

## Being worked on

**A message list in the client portal.** Messages are taken and reviewed on the
operator side, and voicemail reaches clients by email, but the portal itself
currently shows call history and text conversations rather than the message
list. That list is the artifact a client is really paying for, so it is the
most significant piece of portal work outstanding. See
[Client Portal](portal/index.md).

**Routing rule editors.** Both routers work and both evaluate rules on every
inbound item — but neither has an editor in the admin panel. Calls reach a
queue by a direct DID binding, which means no time-of-day routing from the UI;
email gets a provisioned catch-all rule plus per-queue address matching. The
engines are there; the editing surfaces are what is missing. See
[Call Routing](admin/call-routing.md) and
[Email Routing](admin/email-routing.md).

**Web chat.** Chat queues, integration types, and a working AI chat surface
exist. What is not there: chat traffic passing through its queue rather than
straight to a persona, an operator chat inbox, and session persistence. Until
those land, chat is an AI-only channel. See [Channels](admin/channels.md).

**Passkeys and WebAuthn.** Two-factor currently means an authenticator app.
Passkeys are a better answer for both staff and portal users — as a first
factor as well as a second.

**Email one-time codes.** A fallback second factor for users who cannot use
an authenticator app, which in practice is a real fraction of client
contacts.

**More messaging carriers.** Twilio works today. Telnyx and Bandwidth are
next, and SMPP and paging protocols matter specifically for the
answering-service market.

**Outbound-initiated conversations.** Every text conversation currently
starts with an inbound message. Letting an operator text a customer first
needs a compose surface and careful consent handling.

**Object storage in backups.** Backups cover the database, which is the part
that cannot be reconstructed. Recording audio and message media are
currently protected by your storage provider's versioning rather than by
Orbital. Bringing them into the backup properly is a distinct piece of work.

**Translation.** The interface is structured for translation with several
locales scaffolded, but the English copy is still moving too much to be
worth translating yet.

## Planned

**Disaster recovery site.** High availability covers losing a node. A second
site that stays synced and can be promoted covers losing a location.

**Reporting and analytics.** Dashboards exist for platform health. Reporting
that a *client* would want — volumes, response times, outcomes over a
period — is thinner than it should be.

**Per-queue hold music.** Music-on-hold classes are managed platform-wide and
the data model carries a per-queue selection, but choosing it per queue is not
exposed. Queues use the default class. See
[Telephony Infrastructure](admin/telephony.md).

**Billing and metering.** Orbital records the calls, minutes, and messages an
answering service bills on, but does not yet total them per client or export
them for invoicing. Per-client call minutes split by AI and human, message
volume, and recording storage are the numbers that matter, and month-end is
currently somebody reading reports by hand.

**Impersonation UI.** Super-admins can already impersonate a user, but only by
route, and nothing on screen says a session is impersonated. A button and a
persistent banner are both needed before it is something to reach for casually.
See [Security](admin/security.md).

**Skill-based routing.** Operators can hold skills and queues can require them
— the data model and a seeded skill catalog are both there — but nothing
assigns or edits them, so routing is by [agent group](admin/agent-groups.md)
today.

**Template clients on production installs.** The three template tenants are
seeded in local installs only. Shipping them everywhere, so onboarding starts
from a working configuration rather than an empty client, is a small piece of
work with a disproportionate effect on how long a new account takes. See
[Clients](admin/clients.md).

**Client self-service.** The portal is deliberately read-only. There is a
reasonable case for letting clients maintain some of their own
material — an on-call rota, an FAQ — without giving them routing controls.

## Deliberately not planned

Worth stating, because their absence is a decision rather than an oversight.

**Feature gating.** There will never be a paid tier of the software, a seat
count, or a capability withheld from a self-hoster. Hosting, support, and
services are things you can buy; features are not. See
[Installation](getting-started/installation.md).

**Mid-call failover.** Losing the node handling a live call drops that call.
Protecting new calls is the goal; making an in-progress call portable
between nodes costs far more than it returns.

**Clients taking their own calls.** Orbital is an answering service platform.
The client portal is a view of work done on their behalf, not a soft phone
for them. A product where clients answer their own calls is a different
product.

## See also

- [How Orbital Works](concepts.md) — the model behind all of it
- [Installation](getting-started/installation.md) — trying it yourself
