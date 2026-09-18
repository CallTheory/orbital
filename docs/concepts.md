# How Orbital Works

Orbital has a small number of ideas that everything else is built from. If
you understand these six, the rest of the documentation reads easily.

## The three parties

The thing that confuses people first is that Orbital involves three
different organizations, and the word "customer" means something different
to each.

```
PLATFORM OPERATOR   The call-center business running Orbital. Your company.
      |
      |  takes calls and messages on behalf of...
      v
CLIENT              A business that has hired the answering service.
      |             Also called a "tenant" or "account".
      |
      |  whose customers are...
      v
CALLER              The person who actually dials the number or sends
                    the text. Never logs in to anything.
```

**Platform operators** run Orbital. Their staff — administrators and
operators — work in the admin panel and the operator workspace.

**Clients** are the businesses being answered for: a dental practice, a
plumbing company, a law firm. They get a read-only portal where they can see
the messages taken for them. They never configure telephony and never take
calls themselves.

**Callers** are the public. They dial a number or send a text and reach
either an AI agent or a human operator, depending on how that client is
configured.

## Channels

A channel is a way a caller can reach a client. Orbital handles four, and
they share as much machinery as possible on purpose — an operator working
three of them in one shift should not have to learn three interaction
models.

| Channel | Caller does | Handled by |
|---|---|---|
| **Voice** | Dials a phone number | AI agent or human operator |
| **Email** | Emails the client's account address | AI agent or human operator |
| **Text** | Sends SMS or MMS to a number | AI agent or human operator |
| **Chat** | Opens a chat link | AI agent only, today |

Every channel ends in the same place: a **message** recorded against the
client's account. That is the artifact the client is actually paying for —
"someone called about X, here is their number." How it was collected matters
less than that it arrived.

Chat is the one that is not yet level with the others: a chat link reaches a
persona directly, and there is no operator chat inbox. See the
[Roadmap](roadmap.md).

See [Channels](admin/channels.md) for how each one is provisioned.

## Queues

A queue is a named bucket that incoming work lands in, and the thing agent
groups are assigned to. There is one queue type per channel — call queues,
email queues, message queues, chat queues — and they behave alike: work
arrives, sits unclaimed, and an operator whose group covers that queue picks
it up.

Queues are per-client, and all four types are managed on one page: the
**Channels** hub on the client record. Two clients can both have a queue called
"After Hours" and they are unrelated.

## Operators and agent groups

An **operator** is a member of platform staff who takes calls and messages.
Operators do not belong to a client; they work across many.

An **agent group** is a team of operators, assigned to the queues they
cover. This is how you say "these six people answer the dental accounts
overnight." An operator sees work from queues their group covers, plus
anything assigned directly to them.

See [Agent Groups](admin/agent-groups.md).

## AI agents

An **agent persona** is a configured AI answerer: a name, a voice, a
language model, and instructions. A persona can answer a call, an email, or
a text.

Personas do not improvise the whole interaction. What they collect is
defined by an **orchestration** — a flow of steps built from **intake
goals** ("get the caller's name", "find out why they're calling", "offer a
callback window"). The same flow drives the AI and the on-screen script a
human operator follows, so a call that starts with an agent and escalates to
a person does not start over.

See [AI Agents](admin/ai-agents.md) and
[Orchestrations](admin/orchestrations.md).

## Messages

A **message** is the record of "somebody got in touch and here is what they
wanted." It carries a caller name, a callback number, a reason, and an urgency,
and it is stored against the client's account.

Operators take and review messages in the [Workspace](operator/workspace.md).
A client-facing message list in the portal is the next significant piece of
portal work — today a client reviews activity through call history and text
conversations, and receives voicemail by email. See the [Roadmap](roadmap.md).

Messages can be **complete** or **partial**. A partial message is one where
the caller dropped off before the script finished — the name and number
arrived, the reason never did. Whether partials are kept is a per-client
decision and off by default, because for some businesses a name and a number is
a lead worth chasing and for others it is inbox noise.

## Putting it together

A caller dials a number. That number is a **DID** belonging to a **client**
and bound to a **queue**. The queue rings an **agent group** of human
operators, and hands the call to an **AI persona** running an
**orchestration** when nobody answers. Either way the interaction produces a
**message** against that **client's** account.

Everything in the admin guide is configuring one of those nouns.

## Where to go next

- Configuring your first customer: [Clients](admin/clients.md)
- Working a shift: [Operator Workspace](operator/workspace.md)
- What your customer sees: [Client Portal](portal/index.md)
