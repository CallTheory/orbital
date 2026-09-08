# Messaging (SMS / MMS)

Orbital's messaging channel takes inbound text traffic on numbers you
provision for a client, routes it to a queue, and puts it in front of an
operator — the same shape as the email channel, with a different transport.

## How it works

```
Carrier → Webhook → Signature check → Router → Thread → Operator Inbox
```

1. **Carrier delivers** an inbound message to
   `POST /api/messaging/inbound/{provider}`.
2. **Signature verification** runs before anything is read out of the payload.
   This endpoint is public — it has to be, the carrier needs to reach it — so
   verification is what stops anyone on the internet putting words in a
   caller's mouth. It fails closed: an unconfigured provider rejects every
   request.
3. **Client resolution** — the carrier's **sender pool** identifies the
   client, falling back to the number that was texted. Email uses the account
   number in the address; messaging uses the destination. Either way it's the
   one thing the sender can't spoof into somebody else's account.
4. **Queue assignment** — the first active message queue whose match rules
   admit the message.
5. **Threading** — the conversation is found or created, and the operator
   inbox picks it up.
6. **Consent keywords** — `STOP`, `START`, and `HELP` are applied to the
   client's do-not-text list before anything else acts on the message.
7. **Media** — any MMS attachments are pulled off the carrier into Orbital's
   own object store.

## Sender pools are required

For carriers that have the concept, an endpoint is identified by its **sender
pool** — Twilio calls it a *Messaging Service*, Telnyx a *Messaging Profile*,
Bandwidth an *Application* — and Orbital will not send without one. There is no
fallback to naming a bare `From` number.

This is the compliance story for the whole channel:

- **The carrier enforces STOP/HELP/START** on every number in the pool. A bare
  `From` gets none of that, and the failure mode isn't a bounced message — it's
  the carriers quietly de-registering the number weeks later.
- **Sticky sender** keeps one customer talking to one number for the length of
  a conversation instead of a different number each time.
- **A2P 10DLC campaign registration** attaches to the pool. A US long code
  without it delivers erratically at best.

It also removes a whole class of drift. The client adds numbers to their pool
in the carrier's console; Orbital routes them correctly the same afternoon,
because inbound matching is on the pool id (`MessagingServiceSid` on every
Twilio webhook) rather than on a list of numbers somebody has to remember to
mirror here.

If an endpoint somehow has no pool, replies fail with a message saying so
rather than going out non-compliantly. A visible failure an operator can
escalate beats an invisible one that surfaces as a carrier ban.

## Provisioning a number

**Clients → (client) → Messaging Numbers → Create.**

| Field | Purpose |
|-------|---------|
| **Provider** | Which transport carries it — determines the webhook path |
| **Messaging Service SID** | The carrier's sender pool. **Required** for Twilio and any other provider that has the concept. Every number in the pool routes here without being listed. |
| **Display number** | Optional label only — nothing routes on it when a pool is set |
| **Protocol** | `sms`, `mms`, `rcs`, `smpp`, `wctp`, or `paging` |
| **Provider settings** | Optional per-endpoint overrides (credentials, status callback). Stored encrypted. Leave empty to use the platform-wide config. The sender pool has its own field and does not belong here. |

For transports with no pool concept — an SMPP bind, a WCTP pager gateway, the
development `log` driver — the number field is required instead and routing
falls back to address matching.

Messaging numbers are deliberately **separate from voice DIDs**. The same
number is routinely voice with one carrier and SMS with another, they're
provisioned through different vendor relationships, and sharing one record
would mean a voice DID edit silently repointing text traffic.

Address matching, where it's used, tolerates carrier formatting differences —
`+15551234567`, `15551234567`, and `5551234567` all match the same endpoint.
The same Twilio account will send you all three.

Then point the **Messaging Service's** inbound webhook at:

```
https://<your-host>/api/messaging/inbound/twilio
```

Set it on the Messaging Service, not on the individual numbers. That's what
makes a number added later work without anyone touching Orbital.

## Opt-out (STOP / HELP / START)

Every client has a **do-not-text list** at
**Clients → (client) → Do Not Text.**

A customer who texts `STOP` (or `UNSUBSCRIBE`, `CANCEL`, `END`, `QUIT`,
`OPTOUT`, `REVOKE`) is added to it immediately, and their conversation is
closed. `START`, `UNSTOP`, or `YES` lifts it. Matching is on the **whole
message**, case- and punctuation-insensitive — "can you stop by at four?" is an
appointment, not a revocation.

Once somebody is on the list:

- **Operator replies are blocked** with a banner on the conversation and the
  reply box closed, not with a silent failure after the fact.
- **The AI stands down** before the model is called, so a suppressed thread
  doesn't cost a token or produce a failed reply for somebody to interpret.
- **Any future send is refused** and recorded on the thread with the reason.

Scope is the **whole client**, not the one number they happened to text. The
FCC's 2024 revocation rules read that way, and it is also the reading a
customer expects: they told a business to stop, not one of its phone lines.

Carrier-side opt-out through the sender pool still applies on top of this and
is the layer that catches a `STOP` we never see. Orbital's own list exists so
the refusal is *visible* — and because transports like SMPP and paging have no
carrier doing it for them.

The `STOP` message itself is kept in the conversation. Swallowing it would
leave a thread that simply goes quiet with nothing explaining why.

An operator can add a number by hand (someone says it on a call). Opting
somebody back *in* is deliberately one-at-a-time, confirmed, and recorded
against the operator's name — "we opted them back in" is the sentence at the
centre of every messaging complaint.

## MMS media

Attachments arrive as **provider URLs, which are not a copy of anything**: they
expire on the carrier's schedule, need the carrier's credentials to read, and
disappear with the account. `FetchMessageMediaJob` pulls the bytes into
Orbital's object store as soon as the message lands, the same way inbound email
attachments are handled.

| Setting | Default | Notes |
|---------|---------|-------|
| `MESSAGING_MEDIA_DISK` | `s3` | SeaweedFS presents an S3 API |
| `MESSAGING_MEDIA_MAX_BYTES` | 16 MB | Larger stays with the provider |
| `MESSAGING_MEDIA_LINK_TTL` | 15 min | Signed link lifetime |
| `MESSAGING_MEDIA_DELETE_FROM_PROVIDER` | `false` | Delete the carrier's copy once ours is written |

Links in the operator UI are **signed and short-lived**. An MMS to an answering
service is somebody's insurance photograph or their prescription label, and a
guessable permanent URL is not an access control.

Attachments that aren't plain image, video, or audio are stored with their
bytes intact but relabelled `application/octet-stream`, so they download
instead of rendering. An SVG or HTML attachment served inline from a host we
control is a script written by whoever texted the client, running in our
origin.

Turning on `MESSAGING_MEDIA_DELETE_FROM_PROVIDER` deletes the carrier's copy
after ours is safely written — good for cost and for not leaving customers'
photographs on a third party's storage indefinitely. It's off by default
because it's irreversible.

## Message queues

**Clients → (client) → Message Queues.** One queue can carry several
protocols, so a "Support" queue takes every text inbound regardless of
transport.

Matching precedence, most specific first:

1. A queue that names this endpoint's address **and** accepts this protocol
2. A queue with no address list that explicitly accepts this protocol
3. A catch-all queue (no address list, no protocol list)
4. If the client has exactly **one** queue, it gets the traffic — requiring a
   match rule to express "my only queue" is a trap that ends with messages
   sitting unrouted while a queue sits empty

A message to a number no client owns is **dropped with a log line**, not
stored. Unlike email — where an unrouted message is held for an admin to
reassign — an SMS to a number you don't serve is almost always a wrong number
or a spam blast, and persisting it means holding a stranger's content with no
client to own it.

## Working conversations

Operators use **Messages** in the Inbox nav group. It behaves like the email
inbox: threads assigned to you, plus unclaimed threads in queues your agent
group works.

- **Claiming is required before replying.** Two operators answering the same
  customer independently is worse than a slow reply — the customer sees both,
  and neither operator knows what the other said.
- **Delivery state is shown on every outbound message**, not just failures.
  "Sent" and "delivered" are different facts. SMS can be accepted by the
  carrier and then fail at the handset minutes later; an operator who doesn't
  know that will assume the customer was told something they never received.
- **Segment count** is shown next to the reply box. 160 characters is one
  segment; 161 is two, and the client pays for both.

## Threading and reopening

There's no `Message-ID` chain to walk. A thread is identified by
(endpoint, remote address):

- An **open** thread always continues, however old.
- A **closed** thread reopens if it was closed within
  `MESSAGING_THREAD_REOPEN_HOURS` (default 72). "Sorry, one more thing" the
  next morning belongs to the same conversation.
- Otherwise a new thread starts. A text six weeks after a resolved complaint
  is new business, and stapling it onto the old thread buries it under history
  the operator has to scroll past.

A reopened thread goes back to `new` and is **unassigned** — whoever handled
it last has moved on, and leaving it claimed would hide a live customer
message in an off-shift operator's list.

## What the client sees

Clients get a read-only **Text Messages** page in their portal listing the
conversations handled on their behalf. Read-only on purpose: replying is what
they're paying the answering service to do, and a client texting from the same
number mid-conversation would collide with the operator working it.

The nav item is hidden entirely for clients with no text traffic — an empty
page for a product they don't use reads as something broken.

## AI auto-reply

Off by default (`MESSAGING_AUTO_REPLY_ENABLED=false`), and deliberately so: an
AI that starts texting a client's customers without the client having asked
for it is a much worse failure than a slow human reply.

When enabled, a queue with an overflow persona will have inbound messages
answered by `ProcessMessageWithAgentJob`, which reuses the same
`AgentFlowCompiler` that drives voice and email. The agent **stands down the
moment an operator claims the conversation** — the customer should never be
talking to both at once.

Replies are capped at 480 characters (three segments) both by prompt and by a
hard truncation, because a model asked to be brief is not the same as a model
that will be.

## Adding a transport

Implement `App\Services\Messaging\Contracts\MessagingProvider` — `verify()`,
`parse()`, `parseDeliveryReceipts()`, `send()` — and add a line to
`config/messaging.php`. Nothing in the routing pipeline changes.

Drivers are listed explicitly rather than auto-discovered: this map decides
which code handles a public, unauthenticated request, so the set of reachable
classes should be greppable in one file.

Shipped today:

| Driver | Notes |
|--------|-------|
| `twilio` | SMS + MMS, signature-verified, delivery receipts |
| `log` | Development. Accepts a JSON body against a shared secret and logs outbound instead of sending, so the whole pipeline works offline with no carrier account. Still requires a secret — a dev transport that fails open is what ends up filling a staging box with garbage. |

## Metrics

The messaging channel reports into Prometheus alongside the other channels:

- `orbital_messages_exchanged_recent` — texts in/out per client, 24h
- `orbital_message_threads` — conversations by status
- `orbital_messages_undelivered_recent` — the one to alert on
- `orbital_messaging_endpoints_total`

They appear on the **Orbital — Application** Grafana dashboard.
