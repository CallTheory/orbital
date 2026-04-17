# Tenants

Tenants are your customers — the businesses whose calls and messages Orbital handles. Each tenant gets their own account number, routing rules, queues, and AI personas.

## Creating a Tenant

Navigate to **Admin > Platform > Tenants** and click **New Tenant**.

| Field | Purpose |
|-------|---------|
| **Name** | The business name |
| **Account Number** | Unique numeric identifier used in SIP routing and email addressing |
| **Owner** | The primary contact user for this tenant |
| **Timezone** | The tenant's operating timezone, shown alongside operator timezone in email threads |

## Email Addressing

Each tenant receives inbound email at:

```
{account_number}@{INBOUND_MAIL_DOMAIN}
```

For example, tenant with account number `100001` receives email at `100001@inbound.orbital.test`. Function suffixes are supported for multi-queue routing — see [Email Routing](email-routing.md).

## Sub-Pages

The tenant detail view has several sub-pages for configuring tenant-specific resources:

- **Email Queues** — named buckets for inbound email threads
- **Email Rules** — routing rules that determine which queue (or other destination) email lands in
- **Call Queues** — call routing queues with ring strategies
- **Routing Rules** — inbound call routing (DID patterns, time conditions)
- **Contacts** — people associated with the tenant account
- **Directory** — the tenant's phone book for use during calls
