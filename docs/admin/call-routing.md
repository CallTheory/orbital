# Call Routing

Call routing decides what happens between a caller dialling a number and a
phone ringing — which client they reached, whether an agent or a person
answers, and what happens when nobody does.

## The path a call takes

```
SIP TRUNK             The carrier connection the call arrives on
      |
      v
INBOUND NUMBER (DID)  Assigned to a client, bound to a call queue
      |
      v
CALL QUEUE            Orchestration, wrap-up, overflow
      |
      +--> AGENT GROUP     Human operators, ringing on the group's strategy
      |
      +--> OVERFLOW AI     A persona answers when nobody picks up
      |
      v
NO ANSWER             Overflow persona, or the caller waits
```

## Trunks

A SIP trunk is the connection to your carrier. Trunks are configured
platform-wide under **Telephony → SIP Trunks** and shared across clients;
individual numbers are what get assigned per client. See
[Telephony Infrastructure](telephony.md).

## Numbers

Inbound numbers are assigned to a client on the client's **Numbers** sub-page.
A client can have many — a main line, a department line, an after-hours line.
Each number carries its trunk, a label, and an active flag.

## Binding a number to a queue

A DID reaches a queue by being listed on that queue. Open the client's
**Channels** hub, edit a call queue, and set **DIDs routed to this queue**.

**A DID can belong to only one queue at a time.** Adding it to a second queue
takes it out of the first, which is worth knowing before you wonder why the
old queue went quiet.

> **Time-of-day and pattern routing are not yet editable in the admin panel.**
> The data model and the dialplan generator support matching on DID patterns,
> SIP header fields, and time conditions, and the generator reads those rows —
> but there is no editor for them, so a number reaches exactly one queue
> around the clock. An after-hours arrangement today is built with an overflow
> persona on the queue rather than with a second time-based rule. See the
> [Roadmap](../roadmap.md).

## Call queues

A queue holds callers while it looks for somebody to answer. Queues are
created per client on the **Channels** hub.

| Setting | What it controls |
|---|---|
| **Name** | What the queue is called |
| **Agent group** | The pool of operators and hardware phones that rings, and — through that group — the ring strategy |
| **Orchestration** | The intake script this queue runs. Blank means the queue runs without flow logic |
| **Wrap-up time** | Seconds an operator gets after a call before the queue offers them another. 0 means immediately ready |
| **Overflow AI Agent** | The persona that picks up when no human in the group answers. Blank means callers keep waiting |
| **DIDs routed to this queue** | The inbound numbers that ring it |

**Ring strategy and ring timeout are not on the queue.** They live on the
[agent group](agent-groups.md), through a Queue Strategy Template
(**Workflow → Queue Strategies**). Lifting them up to the group means Asterisk
sees one strategy per pool of agents rather than a different one per client
pointing at the same people.

Wrap-up time stays on the queue, so the same pool can have different post-call
recovery for different work.

**Overflow is the setting most often left unconfigured.** A queue with no
overflow persona leaves a caller waiting for something that may never happen.

## Hold music

Music-on-hold classes are managed platform-wide under **Telephony → Hold
Music**, where each row maps to one Asterisk MOH class. Queues carry a
music-on-hold column in the data model, but selecting it per queue is not
currently exposed in the admin panel — queues use the default class. See
[Telephony Infrastructure](telephony.md).

## AI and human on the same number

The common arrangement is human operators first with an AI agent picking up
what they miss: assign an agent group for people and an overflow persona for
the AI. The group's strategy template decides how long the humans get before
the hand-off.

Because both sides follow the same [orchestration](orchestrations.md), a call
that reaches the persona and then escalates to a person does not start over —
the operator sees what has already been collected.

## Calls that match nothing

**Telephony → Settings** decides what happens to a call whose dialled number
matches no client DID, and what happens during an outage when the normal path
cannot be reached at all. Both are worth setting before you take real traffic.
See [Telephony Infrastructure](telephony.md).

## Voicemail

Voicemail greeting and transcription are configured per client, on the
Voicemail tab of the client record. See
[Call Recording & Voicemail](recording.md).

## Testing a route

After changing routing, call the number. It is the only test that covers the
whole path — carrier, trunk, DID, queue, and whoever answers. A binding that
looks right in the admin panel and fails at the carrier is not a rare outcome.

## See also

- [Channels](channels.md) — how voice fits with the other three
- [Telephony Infrastructure](telephony.md) — trunks, extensions, and the SIP edge
- [AI Agents](ai-agents.md) — configuring the agent that answers
- [Agent Groups](agent-groups.md) — who a queue rings, and on what strategy
