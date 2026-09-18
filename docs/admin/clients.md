# Clients

Clients are your customers — the businesses whose calls and messages Orbital
handles. Each client gets their own account number, queues, personas, and
portal logins.

## Creating a client

Navigate to **Customers → Clients** and click **New Client**.

| Field | Purpose |
|-------|---------|
| **Name** | The business name |
| **Account Number** | Unique numeric identifier used in SIP routing and email addressing |
| **Owner** | The staff user who is the primary contact for this account |
| **Timezone** | The client's operating timezone, shown alongside operator timezone in email threads |

## The client record

The client's own edit page is organized into tabs.

| Tab | Holds |
|---|---|
| **Details** | Name, account number, owner, timezone, suspension, two-factor grace window, and whether partial messages are kept |
| **Quotas** | Maximum simultaneous calls. Blank means unlimited. Clients are read-only customer accounts, so this is the only quota exposed — the platform owns their trunks and extensions |
| **Permission Ceiling** | The furthest a client-side user's permissions can ever reach. Default deployments have nothing to set here |
| **Recording** | Per-client call recording overrides. See [Call Recording & Voicemail](recording.md) |
| **Voicemail** | Greeting and transcription settings. See [Call Recording & Voicemail](recording.md) |

## Email addressing

Each client receives inbound email at:

```
{account_number}@{INBOUND_MAIL_DOMAIN}
```

For example, a client with account number `100001` receives email at
`100001@inbound.orbital.test`. Function suffixes are supported for multi-queue
routing — see [Email Routing](email-routing.md).

## Sub-pages

Selecting a client opens a sidebar of pages scoped to that account.

| Sub-page | Configures |
|---|---|
| **Edit** | The client record itself, as above |
| **Numbers** | The inbound phone numbers (DIDs) assigned to this client |
| **Messaging Numbers** | Carrier sender pools used for text. See [Messaging](messaging.md) |
| **Do Not Text** | Customers who have opted out of texts |
| **Extensions** | SIP extensions belonging to this client — AI agent and virtual extensions |
| **Channels** | The hub for all four channel types. Call, email, message, and chat queues are tabs on this one page |
| **Orchestrations** | The intake scripts agents and operators follow |
| **Personas** | This client's AI agents |
| **Users** | Client contacts with portal access |
| **Directory** | The client's phone book, used during calls |
| **Directory Fields** | The schema behind that phone book. See [Directories](directory.md) |

The four per-channel queue pages are still reachable by direct URL, but the
**Channels** hub is where you work with them — one page, four tabs, rather than
four sidebar entries that all do the same kind of thing.

## Setting up a new client

The order matters, because each step depends on the ones before it.

1. **Create the client record** — name, account number, owner, timezone.
2. **Decide the channels** they are buying. See [Channels](channels.md).
3. **Create a queue** for each channel, on the Channels hub. Work with no queue
   behind it lands somewhere nobody can see.
4. **Assign an [agent group](agent-groups.md)** to each queue, or an
   [AI persona](ai-agents.md), or both.
5. **Provision the endpoints** — a phone number, an email address, a
   messaging number.
6. **Point the endpoints at the queues.** See [Call Routing](call-routing.md).
7. **Build the intake script.** See [Orchestrations](orchestrations.md).
8. **Add client contacts** so they can see their activity. See
   [Users & Roles](users-roles.md).
9. **Test each channel end to end** — call the number, send the email, send
   the text.

## Template tenants

A local install seeds three template clients — **Voicemail Only**, **Live
Operators**, and **Live Operators with AI Overflow** — each a complete working
configuration with its own queues, routing, and personas.

They are worth opening before you build your first real client. Reading a
finished arrangement is faster than assembling one from the documentation, and
the three cover most of what an answering service actually sells.

> **They are seeded in local installs only.** A production install has no
> template tenants to copy from. See the [Roadmap](../roadmap.md).

## Partial messages

Each client decides whether half-finished messages are kept. The toggle is
**Keep partial messages** on the Details tab, and it is **off by default**.

A caller who gives a name and a number and hangs up before saying why has left
something behind. For some businesses that is a lead worth chasing; for others
it is inbox noise. The setting on the client record is the default, and
individual intake goals can override it.

Discuss this when you onboard a client rather than choosing for them. It
changes what lands in the inbox they judge you by.

## Two-factor grace

The Details tab carries a per-client two-factor grace window, overriding the
platform default. A client with less technical staff can be given longer
without weakening enforcement for everyone. See [Security](security.md).

## Timezone

The client's timezone is used throughout their portal, so times read correctly
to them rather than to you.

It is also shown alongside the operator's own timezone in the workspace, so an
operator does not tell somebody in another state that a return call is coming
"this afternoon" when it is already evening where they are.

## Suspending a client

Suspending stops new work reaching the account without deleting its history.
Their calls and conversations remain visible.

Re-point or remove their inbound numbers as well. A number still routing to a
suspended client leaves callers reaching a service that is no longer answering
— one of the harder failures to notice from the inside.

## See also

- [Channels](channels.md) — what each channel needs
- [Call Routing](call-routing.md) — numbers, queues, and who answers
- [Client Portal](../portal/index.md) — what the client sees at the end of it
