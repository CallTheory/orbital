# Users & Roles

Orbital has two distinct user populations: **platform staff** (operators, supervisors, admins) who work in the system, and **client contacts** (customers) who view their own data in the portal.

## Platform Roles

Platform roles are team-less — they grant access to the admin and operator panels regardless of which client the user is viewing.

| Role | Access | Purpose |
|------|--------|---------|
| `super_admin` | Admin + Operator panels | Full platform control |
| `operator` | Operator panel | Day-to-day call and email handling |
| `supervisor` | Operator panel | Monitoring, queue management, escalation |

## Client Roles

Client roles are scoped to a specific team. They grant access to the customer portal for that client only.

| Role | Access | Purpose |
|------|--------|---------|
| `client_user` | Portal | View messages, calls, recordings for their client |

## Managing Staff

Navigate to **Platform → Staff** to manage platform users. From
there you assign platform roles, set up softphone extensions, and manage
[agent group](agent-groups.md) membership.

A new operator needs three things before they can work a shift:

1. **A platform role** — `operator` at minimum, or they cannot reach the
   operator panel.
2. **An agent group** — without one they see only work assigned directly to
   them, which usually means an empty inbox and a confused first day.
3. **An extension**, if they will take calls on the softphone.

## Platform roles themselves

**Platform → Roles** manages the team-less roles and the permissions composed
into them. Permissions come from a fixed catalog — roles compose them, but new
permissions cannot be created at runtime.

`super_admin` is built in and locked. It appears in the list but cannot be
renamed or deleted, because the platform's own super-admin checks are written
against that name.

## Every account, in one place

**Customers → Users** is the cross-cutting audit view: every login account
anywhere, platform staff and client contacts alike. It answers "who has a login
on this platform" without visiting thirty client records.

Edits here touch the account itself — name, email, password reset. Role and
client-membership changes happen on the surfaces that own them: **Platform →
Staff** for staff, the client's **Users** sub-page for contacts.

## Client contacts

Client contacts are created on the client record rather than under Staff,
because they belong to a client rather than to your organization. Each gets
their own login to that client's [portal](../portal/index.md).

Access is scoped to one client. Somebody who works for two of your clients
needs two logins — one account seeing two businesses' callers is not a
boundary worth blurring.

## The separation between the two

**Platform permissions are never granted from inside a client.** The two
role namespaces are kept apart deliberately: a client contact cannot be
elevated into staff access by changing their client-level role, whatever
that role is set to.

If somebody needs both — a client who also works shifts for you — they need
two accounts. That is the intended answer rather than a limitation.

## Removing access

Removing a role revokes panel access immediately. It does **not** release
work the person has already claimed, and it does not end a call they are on.

When somebody leaves, release their claimed threads and conversations first,
then remove the role.

## Availability

Operators have an availability status that controls whether they receive new work:

- **Available** — receives new calls and sees unclaimed email threads
- **On Break / In Meeting / etc.** — custom statuses configured under **Preferences → Availability Reasons**

Availability is set from the selector in the operator panel's top bar and
takes effect immediately. Reasons can be configured to block new calls or
not — "Break" normally blocks; something like "Paperwork" might not. See
[Availability](../operator/availability.md).

## See also

- [Security](security.md) — two-factor enforcement and access control
- [Agent Groups](agent-groups.md) — organizing operators into teams
- [Client Portal](../portal/index.md) — what a client contact sees
