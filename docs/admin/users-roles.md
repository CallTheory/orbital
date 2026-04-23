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

Navigate to **Admin > Platform > Staff** to manage platform users. You can assign platform roles, set up softphone extensions, and manage agent group memberships.

## Availability

Operators have an availability status that controls whether they receive new work:

- **Available** — receives new calls and sees unclaimed email threads
- **On Break / In Meeting / etc.** — custom statuses configured under **Admin > Platform > Availability Reasons**

Availability is toggled via the selector in the operator panel's top bar. The email inbox shows shimmer placeholders when unavailable, with the real thread count visible in the navigation badge.
