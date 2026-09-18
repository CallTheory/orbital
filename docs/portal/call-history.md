# Call History

Call History shows the client every call Orbital took on their behalf.

It is read-only. The portal is a record of what happened, not a place to
change it.

## What a row shows

| Column | Meaning |
|---|---|
| **When** | Time of the call, in the client's own timezone |
| **Direction** | Inbound, or a callback the service placed |
| **From** | The caller's number, where the carrier provided it |
| **To** | The number they dialled |
| **Status** | How the call ended |
| **Duration** | How long the call lasted |
| **Play** | Opens the recording, where there is one |

Times are shown in the timezone set on the client's account, not the
platform's. A client in another state should not have to do arithmetic to
work out when somebody called.

## Recordings

Where call recording is enabled, **Play** opens the recording in an inline
player on the row. Links are signed and time-limited rather than guessable.

Recording is configured per client and subject to the disclosure rules for the
jurisdictions involved — Orbital can play a spoken disclosure at the start of a
recorded call, and beep periodically during it. Whether recording is on at all
is an administrator decision, made with the client. See
[Call Recording & Voicemail](../admin/recording.md).

## Why a call might not appear

- **It never reached Orbital.** A call that failed at the carrier before
  arriving is not something the platform can see.
- **It is very recent.** Records settle within a minute or so of the call
  ending.

## See also

- [Client Portal](index.md) — the rest of what a client sees
- [Text Conversations](text-conversations.md) — the equivalent for texts
