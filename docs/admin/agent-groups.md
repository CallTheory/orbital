# Agent Groups

Agent groups are platform-level pools of operators (and optionally hardware phone extensions) that work queues. A single group can be assigned to both call queues and email queues, so the same team of operators handles all channels for a client.

## Creating a Group

Navigate to **Admin > Platform > Agent Groups** and click **New Agent Group**.

| Field | Purpose |
|-------|---------|
| **Name** | Internal identifier (e.g. `all-operators`, `night-shift`) |
| **Label** | Display name shown in queue assignment dropdowns |
| **Description** | Optional note about the group's purpose |
| **Active** | Inactive groups are excluded from queue assignments |

## Managing Members

Click into a group to manage its members. Members can be:

- **Staff users** — operators and supervisors with platform roles
- **Phone extensions** — hardware SIP phones or ATAs (for call queues only)

Each member has:

- **Priority** — order for sequential ring strategies (call queues)
- **Penalty** — Asterisk queue penalty value; lower penalty = rings sooner

## How Groups Connect to Queues

### Call Queues
Each call queue references one agent group via the "Agent Group" field. The QueueMemberSyncer expands group members into Asterisk's realtime queue_members table.

### Email Queues
Each email queue can optionally reference an agent group via the "Operator Group" field. When set, only group members see unclaimed threads in that queue in their operator inbox. When unset, the queue is "open" to all operators.

## Common Patterns

### Single group for everything
Create one group (e.g. "All Operators"), add every operator, and assign it to all queues. Simple and appropriate for small teams.

### Shift-based groups
Create groups per shift (e.g. "Day Shift", "Night Shift"). Assign different email queues to different groups so operators only see work for their shift.

### Skill-based groups
Create groups by expertise (e.g. "Billing Team", "Technical Support"). Assign client queues to the appropriate group so specialized work goes to qualified operators.
