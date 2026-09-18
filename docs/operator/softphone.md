# Softphone

Orbital includes a phone in the browser. There is no handset to configure
and no separate application to keep running — if you are logged in to the
operator panel, you can take calls.

It uses WebRTC to connect to the platform's telephony layer over an
encrypted connection.

## It follows you around

The softphone sits at the bottom of the operator panel and stays connected
as you move between Workspace, the inboxes, and anywhere else.

This is deliberate and it matters: **navigating the operator panel never
drops your call.** You can look up a client's directory or read an old
message mid-conversation without losing the caller.

The one thing that does drop a call is reloading the browser tab. Avoid
refreshing while you are on a call.

## Handling a call

When a call arrives you get an audible ring and an on-screen prompt.

| Control | Does |
|---|---|
| **Answer** | Picks up |
| **Reject** | Declines. The call goes back to the queue for another operator |
| **Hang up** | Ends the call you are on |
| **Mute** | Stops your microphone. The caller hears nothing from your side |
| **Keypad** | Sends touch tones — for navigating an automated system when you dial out |
| **Transfer** | Sends the call elsewhere |

When a call connects, the [Workspace](workspace.md) loads that client's
account automatically, so the greeting and the script are on screen before
you speak.

## Transferring

Enter the destination — an extension, or a full number — and transfer. The
call is handed over and leaves your phone.

Tell the caller they are being transferred before you do it. Once the
transfer completes the call is no longer yours, and if it fails to connect
you are not in the conversation to explain what happened.

## Making a call

Type a number into the dial field and dial. Use this for callbacks and for
reaching a client on their own line.

## Microphone permission

The first time you use the softphone your browser asks for microphone
access. You have to allow it — without it you can hear callers and they
cannot hear you, which is a confusing failure because the call otherwise
appears to work normally.

If you denied it by accident, clear the permission in your browser's site
settings and reload once, before your shift rather than during a call.

## When something is wrong

| Symptom | Try |
|---|---|
| No ring on incoming calls | Check your [availability](availability.md) — a blocking reason stops calls reaching you |
| Caller cannot hear you | Microphone permission, then check you are not muted |
| You cannot hear the caller | Browser tab is not muted; correct output device selected in your OS |
| Softphone shows disconnected | Reload the page — but only when not on a call |

If calls are not arriving for anyone, that is a platform problem rather than
a browser one; tell your administrator.

## See also

- [Workspace](workspace.md) — what to do once the call connects
- [Availability](availability.md) — controlling whether calls reach you
