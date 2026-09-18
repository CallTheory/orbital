# Agent Groups

An agent group is a team of operators, assigned to the queues they cover. It is how you say "these six people answer the dental accounts overnight."

Groups are platform-level: operators belong to a group, not to a client, and one group can cover queues across many clients. A single group can be assigned to call, email, message, and chat queues alike, so the same team handles every channel for a client without being enrolled four times.

## Creating a Group

Navigate to **Platform → Groups** and click **New Agent Group**.

| Field | Purpose |
|-------|---------|
| **Name** | Internal identifier (e.g. `all-operators`, `night-shift`) |
| **Label** | Display name shown in queue assignment dropdowns |
| **Description** | Optional note about the group's purpose |
| **Ring strategy** | The Queue Strategy Template this group uses — the Asterisk strategy, ring timeout, and retry interval applied wherever the group is used |
| **Active** | Inactive groups are excluded from queue assignments |

## Managing Members

Click into a group to manage its members. Members can be:

- **Staff users** — operators and supervisors with platform roles
- **Phone extensions** — hardware SIP phones or ATAs (for call queues only)

Each member has:

- **Priority** — the order they are tried, where the queue rings people in sequence rather than all at once
- **Penalty** — a tie-breaker; members with a lower penalty are tried sooner. Use it to make a group's regulars ring before its overflow cover

## How Groups Connect to Queues

### Call queues

A call queue names one agent group. Its members — people and any hardware
phones — are what the queue rings.

**The ring strategy belongs to the group, not the queue.** It comes from a
Queue Strategy Template (**Workflow → Queue Strategies**), which carries the
strategy, the ring timeout, and the retry interval. Lifting it up to the group
means Asterisk sees one strategy per pool of agents rather than a different one
per client pointing at the same people.

Wrap-up time is the exception and stays on the individual queue, so the same
pool can have different post-call recovery for different work.

### Email, message, and chat queues

These queues can optionally name a group. When one is set, only that group's
members see unclaimed work from the queue in their inbox. When it is left
unset the queue is **open**: every operator sees it.

Open queues are fine for a small team and become unmanageable at scale —
every operator sees every client's work. Assign groups once you have more
than a handful of people.

## Common Patterns

### Single group for everything
Create one group (e.g. "All Operators"), add every operator, and assign it to all queues. Simple and appropriate for small teams.

### Shift-based groups
Create groups per shift (e.g. "Day Shift", "Night Shift"). Assign different email queues to different groups so operators only see work for their shift.

### Skill-based groups
Create groups by expertise (e.g. "Billing Team", "Technical Support"). Assign client queues to the appropriate group so specialized work goes to qualified operators.

## Things that catch people out

**An operator in no group sees only work assigned directly to them.** If
somebody reports an empty inbox, check their group membership first.

**Removing somebody from a group does not release what they have already
claimed.** Claimed threads and conversations stay theirs. Release them
explicitly when somebody leaves a shift or the company.

**Deactivating a group removes it from queue assignment**, but queues
already pointing at it keep the reference. Re-point those queues before you
deactivate, or work will arrive somewhere nobody is looking.

## See also

- [Users & Roles](users-roles.md) — creating the operators who go in groups
- [Call Routing](call-routing.md) — assigning a group to a call queue
- [Availability](../operator/availability.md) — how an operator controls what reaches them
