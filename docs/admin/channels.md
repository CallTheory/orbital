# Channels

A channel is a way a caller can reach one of your clients. Orbital handles
four, and they are built to share as much as possible — the same queue
model, the same claim semantics, the same message at the end.

| Channel | Reached by | Provisioned in |
|---|---|---|
| **Voice** | Dialling a phone number | [Call Routing](call-routing.md) |
| **Email** | Emailing the client's account address | [Email Routing](email-routing.md) |
| **Text** | SMS or MMS to a number | [Messaging](messaging.md) |
| **Chat** | A chat link | Chat queues, on the Channels hub |

All four are configured in the same place: the **Channels** hub on the client
record, which exposes call, email, message, and chat queues as tabs on one
page.

## What they have in common

**Queues.** Each channel has its own queue type, and they behave alike: work
arrives, waits unclaimed, and an operator whose [agent group](agent-groups.md)
covers that queue picks it up.

**Claiming.** On the text-based channels, work is claimed exclusively —
one operator, one conversation. Two people answering the same customer is
worse than a slow reply.

**AI or human.** Voice, email, and text can each be answered by an
[AI persona](ai-agents.md) or a person, and a client can mix them: humans
first, an agent on overflow, or the reverse.

**Messages.** They end in the same artifact — a message recorded against the
client's account. That uniformity is the point. The client cares that somebody
wanted a callback about a burst pipe, not which pipe the request came down.

## Where they differ

**Pace.** Text is answered in minutes, email in hours, voice immediately.
The inboxes reflect that: the message inbox refreshes on its own and
surfaces waiting conversations loudly; the email inbox is calmer.

**Consent.** Text is the only channel with legally enforced opt-out. A
customer who replies STOP cannot be texted again by anyone, and Orbital
enforces that centrally rather than trusting each sending path. See
[Messaging](messaging.md).

**Identity.** Voice and text identify a caller by phone number, which is
usually reliable. Email identifies by address and is easier to spoof, so
inbound mail is matched on the client's account number rather than on who
the sender claims to be.

**Maturity.** Chat is behind the other three. A chat link reaches a persona
directly rather than passing through its queue, there is no operator chat
inbox, and session persistence is not finished. Queue configuration exists and
the integration types — embeddable web widget, Slack, Microsoft Teams — are
defined, but treat chat as early rather than level with voice, email, and text.
See the [Roadmap](../roadmap.md).

## Turning a channel on for a client

Each channel is enabled per client, on the client record:

1. Provision the endpoint — a phone number, an email address, a messaging
   number, a chat widget.
2. Create at least one queue for that channel.
3. Assign an [agent group](agent-groups.md) to the queue, or an
   [AI persona](ai-agents.md), or both.
4. Add routing so incoming work reaches the right queue.

A channel with an endpoint but no queue collects work nobody can see, which
is the most common setup mistake. If a client reports that messages are
disappearing, check that first.

## See also

- [Clients](clients.md) — where channels are configured
- [How Orbital Works](../concepts.md) — the model these fit into
