# Email Routing

Orbital's inbound email pipeline accepts messages via SMTP (Haraka), resolves the client, and routes each thread to the right queue based on configurable rules.

## How It Works

```
Sender → SMTP (Haraka) → Webhook → Laravel Router → Queue → Operator Inbox
```

1. **Client resolution**: The recipient address encodes the client's account number as the local part. For example, `100001@inbound.orbital.test` resolves to the client with account number `100001`.

2. **Thread grouping**: Messages are grouped into threads using RFC822 `Message-ID`, `In-Reply-To`, and `References` headers. Replies to existing threads land on the same thread regardless of routing rules.

3. **Rule evaluation**: For new threads, the router evaluates the client's email routing rules in priority order (lowest number = highest priority). The first matching rule determines the destination.

4. **Queue assignment**: The thread is stamped with the destination queue. Once set, the queue assignment persists — subsequent messages on the thread inherit the existing queue.

## Email Queues

Each client can have one or more email queues. A queue is a named bucket that threads land in, worked by a specific group of operators.

| Field | Purpose |
|-------|---------|
| **Name** | Display name shown to operators (e.g. "Support", "VIP", "Alarms") |
| **Operator Group** | Which agent group works this queue. Leave empty for "open" — all operators see it. |
| **Overflow AI** | Optional AI persona that handles threads when no human is available |
| **Strategy** | How operators pick up threads (manual is the default) |

### Operator Group Assignment

Queues reference platform-level **Agent Groups**. An agent group is a pool of operators that can be shared across both call queues and email queues. When a queue has an operator group assigned, only members of that group see unclaimed threads in the queue.

If no operator group is assigned, the queue is "open" — all operators can see its threads.

!!! tip "Simple setup"
    Most clients only need one queue with a catch-all rule. Create a queue called "General Inbox", assign your operator group, and add a default routing rule pointing to it.

## Routing Rules

Rules determine which queue (or other destination) an inbound email lands in. They're evaluated per-client in priority order.

### Match Types

| Type | What it matches | Example |
|------|----------------|---------|
| **Function suffix** | The `.function` part of the recipient address | `100001.alarms@...` matches function `alarms` |
| **From address regex** | Regex against the sender's email address | `@vip-client\.com$` |
| **Subject regex** | Regex against the subject line | `(urgent\|critical\|emergency)` |
| **Default** | Catch-all — matches everything not caught by other rules | Always put at lowest priority |

### Destinations

| Type | Behavior |
|------|----------|
| **Email Queue** | Thread lands in the selected queue for operators to claim |
| **Direct to operator** | Thread is immediately assigned to a specific operator |
| **AI agent persona** | Thread is handed off to an AI persona for autonomous handling |
| **Discard** | Message is dropped silently (spam, known-bad senders) |

### Priority

Rules are evaluated in ascending priority order — lower numbers fire first. If multiple rules could match the same message, the first one wins.

Recommended priority ranges:

- **1-10**: Function suffix rules (most specific)
- **11-50**: From/subject regex rules
- **100**: Default catch-all (always last)

## Example: Simple Setup (Demo Customer)

One queue, one rule — every email goes to the same place.

| Rule | Match | Priority | Destination |
|------|-------|----------|-------------|
| Default catch-all | Default | 100 | General Inbox queue |

## Example: Advanced Setup (Acme Corp)

Three queues with tiered routing:

| Rule | Match | Priority | Destination |
|------|-------|----------|-------------|
| VIP function suffix | Function: `vip` | 10 | VIP queue |
| Urgent subject keywords | Subject: `(urgent\|critical\|emergency)` | 20 | Urgent queue |
| Default catch-all | Default | 100 | General queue |

Emails sent to `100002.vip@...` route to VIP. Emails with "urgent" in the subject route to Urgent. Everything else falls to General.

## Function Suffix Addressing

Clients can receive email at multiple "sub-addresses" using function suffixes:

```
{account_number}.{function}@{inbound_domain}
```

Examples for account 100002:

- `100002@inbound.orbital.test` — standard address (no function)
- `100002.alarms@inbound.orbital.test` — alarm notifications
- `100002.vip@inbound.orbital.test` — VIP client correspondence
- `100002.billing@inbound.orbital.test` — billing inquiries

The function suffix is extracted by the router and matched against function-type routing rules. This lets clients give different email addresses to different contacts and have them automatically sorted into the right queue.

## Troubleshooting

### Emails not appearing in the operator inbox

1. Check **Admin > Monitor > Unrouted Mail** for messages that failed routing
2. Verify the client has at least one email routing rule (a default catch-all)
3. Verify the destination queue exists and is active
4. If using operator groups, verify the operator is a member of the queue's agent group

### Emails landing in the wrong queue

1. Check rule priorities — lower numbers evaluate first
2. Subject regex rules are case-insensitive by default
3. Function suffix rules are exact matches (no regex)
