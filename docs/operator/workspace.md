# Workspace

The Workspace is your main screen. It is where you pull up a client, read
what the platform knows about them, and take a message.

It has two states. With no client loaded it shows a search prompt. Once you
load one, it becomes that client's workspace: their greeting, their
instructions, their recent messages, and the form for taking a new one.

## Loading a client

Two ways a client gets loaded:

- **Automatically**, when a call arrives. The platform knows which number
  was dialled and pulls up the matching account before you say hello.
- **Manually**, with **Fetch Account** — search by business name or account
  number. Use this when a caller reaches you another way, or when you need
  to look something up between calls.

Recently used accounts are listed for one-click access, which is faster than
searching when you are working a handful of accounts on a shift.

## Taking a message

With a client loaded, **New Message** opens the intake form:

| Field | Notes |
|---|---|
| **Caller name** | Required |
| **Callback number** | How the client reaches them back. Optional |
| **Reason** | Required — what they actually want |
| **Urgency** | Normal or Urgent |

**Save Message** files it against the account. **Cancel** discards the form
without saving.

### When the caller hangs up mid-intake

This happens constantly and Orbital treats it as a normal case rather than
an error.

If you have a name and a number but never got the reason, use **Save
Partial**. The button appears under the form with a prompt reading "Caller
dropped off? Save what you have — it will be marked incomplete."

The message is stored and clearly marked incomplete, with a note saying what
was never given — so it reads "caller hung up before giving a reason" rather
than an unexplained blank.

Two things gate this:

- **The client has to want partials.** Some businesses want every scrap;
  others consider a half-message noise. If the button is not there, this
  client has partial messages turned off, and the right move is to save
  nothing.
- **There has to be enough to act on.** A callback number alone is enough. A
  name with no number and no reason is not, and will be refused — nobody can
  follow that up, and it would just clutter the client's inbox.

## Following a script

When a client has an intake script configured, it appears in the workspace
as you work — the questions to ask, in order, with the fields they fill.

This is the same script an AI agent would follow for that client. If a call
started with an agent and was escalated to you, the questions already
answered are already filled in. You are continuing the conversation, not
restarting it.

## The message list

Below the intake form is what has already been taken for this account. Each row
shows when it was taken, who called, the message itself, who took it, and a
**completeness** marker — partials are visually distinct, because a half-message
that looks like a whole one is worse than no message.

Two actions on each row: **Mark Read** and **Archive**. Archiving clears a
message out of the working list without deleting it.

You can filter the list to partial or complete messages, which is the quick way
to see whether a client's partials are earning their place.

## Releasing a client

**Close Account** clears the workspace and returns you to the search
prompt. Do this between unrelated calls so you do not accidentally file a
message against the previous caller's account — which is the single most
common workspace mistake and an awkward one to explain to a client.

## See also

- [Softphone](softphone.md) — handling the call itself
- [Availability](availability.md) — controlling whether calls reach you
