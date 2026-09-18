# Call Recording & Voicemail

Two things that both turn a call into something durable, and both carry legal
weight. They are configured in two places: a platform default, and a per-client
override.

## Call recording

### The platform default

**System → Settings → Call Recording.**

| Setting | What it does |
|---|---|
| **Call recording enabled** | The master switch. Off means nothing is recorded, whatever a client or extension says |
| **Recording format** | WAV for lossless and larger, MP3 for compressed and smaller |
| **Retention days** | How long recordings are kept before deletion |
| **Storage disk** | Where they are written |
| **Beep when recording starts** | An audible notification to the caller |
| **Beep interval** | Seconds between repeated beeps during an active recording. 0 beeps once at the start |
| **Disclosure message** | The spoken notice played at the start of a recorded call |

### Per-client overrides

**The Recording tab on the client record.** Every field here can be left blank
to inherit the platform default — enabled, format, retention, beep behavior,
and the disclosure message.

This is the level that usually matters, because recording law is decided by
where the caller and the client are, not by where your platform runs. A client
in a two-party-consent jurisdiction needs a disclosure whether or not your
other clients do.

Individual extensions can override again, through their own recording mode:
inherit, always record, or never record.

So the order of precedence is: **platform default, then client, then
extension.** An extension set to "never" is not recorded even if everything
above it says yes.

### Rendering disclosures

The disclosure message is spoken, which means it has to be rendered to audio
before a call can play it. After changing platform or client disclosure text:

```
php artisan orbital:render-disclosures
```

That renders the platform default and every client override. It is also on
**System → Tools**.

### Where recordings go

Recordings are written to object storage and surfaced two ways: on **Monitor →
Call Logs** for staff, and on the client's [Call History](../portal/call-history.md)
page for the client. Links are signed and time-limited rather than guessable.

On a deployment with rtpengine doing the recording, a spool-draining job moves
finished files off the edge nodes:

```
php artisan orbital:upload-recordings
```

> **Recordings are not covered by Orbital's backups.** The database is. Object
> storage content is protected by your storage provider's versioning and
> lifecycle rules instead. See [Backups](backups.md).

## Voicemail

### Greeting

**The Voicemail tab on the client record**, Greeting section.

| Mode | Behavior |
|---|---|
| **Asterisk default** | The stock prompt. No custom greeting |
| **Custom TTS greeting** | Your text, spoken in a voice you choose |

With custom TTS you write the greeting text, pick a provider — OpenAI or
ElevenLabs — and name the voice. The text is rendered to a WAV that the
dialplan plays before the caller records, and **re-rendered automatically when
you save**, so editing the text is all you have to do.

Write it as the client would say it. "You've reached Northgate Dental, we can't
take your call right now — leave your name, number, and a brief message after
the beep" is doing three jobs: identifying the business, explaining the
silence, and telling the caller what to say.

### Transcription

Same tab, Transcription section. Audio is always attached to the notification
email; a transcript goes inline in the body when a provider is selected.

| Provider | Notes |
|---|---|
| **Disabled** | Audio only |
| **Whisper (local)** | Runs in your own stack. Nothing leaves the platform |
| **OpenAI Whisper (cloud)** | Needs an API key |
| **Deepgram** | Needs an API key |
| **ElevenLabs Scribe** | Needs an API key |

Cloud providers take an optional model override; API keys are stored
encrypted.

**Pick Whisper (local) if the client's contract constrains sub-processors.**
A voicemail is a caller saying their name, their number, and their problem out
loud. Sending that to a third party is a decision, not a default — which is
why the local option exists and why "Disabled" is the shipped setting.

### What a voicemail becomes

A voicemail is **emailed out**, with the audio attached and the transcript
inline where one was produced.

> **Voicemails are not currently listed as messages in the client portal.**
> They arrive by email. A client who expects everything in one list will
> notice, so set that expectation during onboarding. See the
> [Roadmap](../roadmap.md).

## See also

- [Telephony Infrastructure](telephony.md) — extensions and their recording modes
- [Call History](../portal/call-history.md) — how a client reaches a recording
- [Backups](backups.md) — and what backups deliberately do not cover
