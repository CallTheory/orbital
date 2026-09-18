# Message Inbox

The Message Inbox is where text conversations — SMS and MMS — are worked. It is
**Messages** in the Inbox group of the operator panel.

It is deliberately the same screen as the [Email Inbox](email-inbox.md) with
the channel swapped: the same claim model, the same close and reopen, the
same visibility rules. Working three channels in one shift should not mean
learning three interaction models.

The one real difference is pace. Texts are answered in minutes, not hours,
so the inbox refreshes on its own and conversations waiting on a reply are
surfaced more loudly.

## What you see

Conversations appear in your inbox when they are either:

- **assigned to you**, or
- **unclaimed in a queue your agent group covers**.

Each row shows who is texting, which client they reached, the last message,
and how long it has been waiting.

## Claiming

**Claim** a conversation before replying. Claiming is exclusive — if two
operators open the same conversation, only the first to claim gets it, and
the second is told it was already taken.

This is stricter than it sounds and it is worth understanding why: two
operators independently answering the same customer is worse than a slow
reply, because the customer receives both replies and neither operator knows
what the other said.

**Release** a conversation to put it back in the queue. Do this at the end of
your shift rather than leaving claimed work stranded.

## Replying

The reply box is inline — no dialog to open. Type and send.

Texts are short by nature and billed per segment. Long replies arrive on a
handset split across several notifications and read badly, so keep them to a
couple of sentences and ask one question at a time.

If a send fails, you are told immediately and loudly. Do not assume a
message was delivered because it left your screen — a failed reply is a
customer still waiting who does not know it.

## Attachments

Photographs and other media sent by the customer appear inline in the
conversation. Orbital keeps its own copy, so they remain viewable long after
the carrier would have expired the original.

## When someone opts out

If a customer replies STOP, the conversation closes and the reply box
disappears. You will see a notice saying they have opted out.

**You cannot text them again, and neither can anyone else.** This is a legal
requirement, not a preference, and there is no override in the interface.
The customer can restart the conversation themselves by texting START.

If a customer tells you verbally that they want texts to stop, ask your
administrator to add them to the client's Do Not Text list.

## Taking a message from a conversation

**Take Message** writes the conversation up as a message against the client's
account — the same artifact a phone call produces.

The callback number is filled in for you, since it is the number they texted
from. Fill in the name and what they wanted.

As on the [Workspace](workspace.md), if the customer stopped replying before
you got their reason, you may be able to save what you have — depending on
whether that client keeps partial messages.

## Closing

**Close** ends a conversation. It is not permanent: a new text from that
number within a few days reopens the same thread rather than starting a new
one, so the history stays together.

## See also

- [Email Inbox](email-inbox.md) — the same model, for mail
- [Messaging](../admin/messaging.md) — how administrators provision numbers
