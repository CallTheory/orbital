# Clients

Clients are your customers — the businesses whose calls and messages Orbital handles. Each client gets their own account number, routing rules, queues, and AI personas.

## Creating a Client

Navigate to **Admin > Platform > Clients** and click **New Client**.

| Field | Purpose |
|-------|---------|
| **Name** | The business name |
| **Account Number** | Unique numeric identifier used in SIP routing and email addressing |
| **Owner** | The primary contact user for this client |
| **Timezone** | The client's operating timezone, shown alongside operator timezone in email threads |

## Email Addressing

Each client receives inbound email at:

```
{account_number}@{INBOUND_MAIL_DOMAIN}
```

For example, client with account number `100001` receives email at `100001@inbound.orbital.test`. Function suffixes are supported for multi-queue routing — see [Email Routing](email-routing.md).

## Sub-Pages

The client detail view has several sub-pages for configuring client-specific resources:

- **Email Queues** — named buckets for inbound email threads
- **Email Rules** — routing rules that determine which queue (or other destination) email lands in
- **Call Queues** — call routing queues with ring strategies
- **Routing Rules** — inbound call routing (DID patterns, time conditions)
- **Contacts** — people associated with the client account
- **Directory** — the client's phone book for use during calls
