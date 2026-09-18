# Orchestrations

An orchestration is the script — what gets collected, in what order, and
what happens next. It drives both the AI agent and the on-screen prompts a
human operator follows.

That shared source is the whole design. When a call starts with an agent and
escalates to a person, the operator continues from where the agent stopped
rather than starting the conversation again.

## The pieces

```
ORCHESTRATION        A named bundle, assigned to queues
      |
      +-- FLOW       An ordered sequence of steps
            |
            +-- STEP      One intake goal, with this client's settings
                  |
                  +-- INTAKE GOAL   A reusable objective from the catalog
```

**Intake goals** are the platform-wide vocabulary of things an agent can do:
get a name, get a callback number, find out the reason, offer a callback
window, check whether it is an emergency. They are shared across all clients and
edited under **Workflow → Intake Goals**.

Clients compose flows from that library but cannot create goals themselves —
the vocabulary is yours to curate, so that a phrase means the same thing on
every account.

A goal carries its talking points, the data fields it collects, what counts as
completion, any tools it can call, and the knowledge stores it may draw on.
Those five things are what every surface reads: the voice agent, the operator's
on-screen script, and chat all render the same goal their own way.

**Steps** place a goal in a flow and carry this client's specifics — the
exact wording, whether it is required, what counts as an answer.

**Orchestrations** bundle flows and get assigned to queues.

## Building one

1. Create an orchestration — on the client's **Orchestrations** sub-page for
   one client, or under **Workflow → Orchestrations** for a shared one.
2. Add the steps a call should walk through, in order.
3. Assign it to the queues that should use it, on the client's **Channels**
   hub.

An orchestration is live once assigned to a queue. There is no separate
publish step — the assignment *is* the publish, so an orchestration attached
to a live queue is answering calls.

## Shared orchestrations

Orchestrations can be **per-client** or **shared platform-wide**.

**Workflow → Orchestrations** lists the shared ones only — per-client
orchestrations deliberately stay on their client, because the platform-level
question is "what shared workflows exist", not "what has everyone built".

Whether a shared orchestration is active is derived live from the queues
pointing at it, so the list also shows you which ones are wired up and which
are sitting idle as drafts.

A shared orchestration is authored once and assigned to many clients — a
standard after-hours intake, say, used across thirty accounts. Each client
that uses it keeps its own bindings, so references to "the overflow persona"
or "the urgent queue" resolve to *that* client's resources.

This is what makes a shared script safe. Editing it updates every client
using it, without any of them borrowing another's queues or personas.

Change one carefully for exactly that reason: a shared orchestration is live
on every account that has it assigned.

## Partial captures

A caller who hangs up mid-script leaves an incomplete record. What happens
then is a per-client decision:

- **Keep partials** — a name and a number is a lead worth chasing
- **Discard them** — a half-message is noise in the client's inbox

Set the default on the client, and override it per intake goal where a
particular question changes the answer. Some goals are worth keeping a
fragment of; some are not.

Orbital will not save an empty fragment even when partials are on. There has
to be enough to act on — a callback number, or a name plus something they
said.

## Testing

Call the number and walk the script as a caller would. Reading a flow in the
editor tells you what you intended; calling it tells you what a caller
actually experiences, including the parts where an agent asks something that
made sense written down and is strange out loud.

Test the hang-up case too. Drop the call midway and confirm what gets recorded
against the account matches what you told the client to expect.

## Bindings

A shared orchestration refers to resources by handle rather than by id — "the
overflow persona", "the urgent queue". Each client that uses it maps those
handles to its own resources through **Bindings**, reachable from the queue row
on the Channels hub.

An unbound handle is the usual reason a shared orchestration behaves correctly
on one account and stalls on another. Check bindings first.

## See also

- [AI Agents](ai-agents.md) — the persona that delivers the script
- [Operator Workspace](../operator/workspace.md) — how a script looks to a person
