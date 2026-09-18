# AI Agents

An AI agent answers calls, emails, and texts on a client's behalf. What it
sounds like is an **agent persona**; what it actually does is an
[orchestration](orchestrations.md).

Keeping those separate is the point. The persona is the voice and manner;
the orchestration is the job. You can change the voice without rewriting the
script, and reuse a script across clients that sound nothing alike.

## Personas

A persona is created per client and carries:

| Setting | What it does |
|---|---|
| **Name** | What the agent calls itself |
| **Voice** | The speaking voice used on calls |
| **Language model** | Which model drives the conversation |
| **Instructions** | How it should behave — tone, boundaries, what to do when it cannot help |

### Writing instructions

The instructions are a system prompt. A few things consistently matter:

- **Say who it is and who it works for.** "You answer the phone for
  Northgate Dental" grounds everything else.
- **Set the boundaries explicitly.** What may it promise? Can it quote
  prices? Book appointments? An agent with no stated limits will invent one.
- **Say what to do when it cannot help.** Without this, models improvise —
  usually by apologising in a loop. "If you cannot answer, say a member of
  the team will call back, and collect a number" is worth more than a page
  of tone guidance.
- **Do not put the intake questions here.** Those belong in the
  orchestration, where the human-operator script comes from the same source.
  Duplicating them means the AI and the operator drift apart.

### Where personas live

A client's personas are on that client's **Personas** sub-page.

### Persona templates

**Conversational AI → Personalities** holds the platform-wide templates — a
general receptionist, a medical intake, an after-hours service. Create a
client's persona from a template and adjust, rather than starting from an empty
box each time.

Templates stay linked. **Editing a template propagates to every client persona
created from it, on the fields that client has not overridden.** Overriding a
field on a client's persona breaks the link for that field only, so a client
with a custom greeting still picks up an improved fallback instruction.

That is worth knowing before you edit a template on a busy platform: it is live
on every unmodified persona that descends from it.

## Voices

Voices are catalogued platform-wide under **Conversational AI → Voices** and
selected per persona. Each row is one provider-specific voice; the entry the
platform sends upstream at call time is the provider's own voice id, and the
name is there for humans browsing the list. Preview one
before assigning it: a voice that reads well in a list can be wrong for a
medical practice, and the client will notice before you do.

The voice is part of the client's brand. It is worth asking them.

## Where an agent gets used

A persona can be attached to:

- **A call queue** — as the answerer, or as overflow when nobody picks up
- **An email queue** — drafting and sending replies
- **A message queue** — answering texts
- **A chat queue** — the web widget

The same persona can cover several channels for one client, which is usually
what you want: one identity, whichever way somebody gets in touch.

## What an agent will not do

**It will not text somebody who has opted out.** The suppression list is
enforced centrally. An agent asked to reply to an opted-out number stands
down rather than sending.

**It will not talk over an operator.** If a person has claimed a
conversation, the agent stops replying to it. Whichever the customer is
talking to, they should not suddenly be talking to both.

**It will not answer without a configured model provider.** If AI features
do nothing, check **Conversational AI → Providers** first — an unconfigured key
is by far the most common cause, and the symptom looks like a broken feature
rather than a missing setting.

## Handing over to a person

Escalation is configured on the queue, not the persona. When a call escalates
the operator picks up with the collected fields already on screen, because
both sides run the same orchestration.

Tell clients this happens. "Our AI answers first and a person takes over if
needed" is a feature; being unable to tell is not.

## See also

- [Orchestrations](orchestrations.md) — what the agent actually collects
- [Knowledge Bases](knowledge-bases.md) — giving an agent the client's documents
- [Call Routing](call-routing.md) — where personas attach
