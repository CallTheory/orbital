# Security

Orbital holds other people's callers — names, phone numbers, and the reasons
they rang. This page covers the controls over who can reach that.

## Two-factor authentication

Two-factor is required for everyone: platform staff and client portal users
alike. Somebody with a client login can read every message taken for that
business, which is worth protecting properly.

### The grace period

Turning enforcement on does not lock anybody out immediately. Each user gets
a grace window, and **the clock starts on that user's first request after
the policy applies** — not when their account was created.

That distinction matters: counting from account creation would lock out
every existing user the moment you enabled it. As it is, everyone gets the
same window from the moment they next log in.

During the window users see a countdown banner that turns urgent in the
final stretch. When it expires they are held at the security page until
enrolled.

### Configuring it

The default window is set platform-wide. It can be overridden per client, on
the Details tab of the client record, so a client with less technical staff can
be given longer without weakening it for everyone.

Users enrol from their own security page. The path is never blocked by
enforcement — a user who has run out of grace can still reach the page they
need to comply.

## Roles and permissions

Access is granted through roles rather than per-user flags.

| Role type | Scope | Managed under |
|---|---|---|
| **Platform roles** | Your staff. Super-admin, operator, supervisor, and any custom roles you compose | **Platform → Roles** |
| **Client roles** | Contacts at a client, scoped to that one account | The client's **Users** sub-page |

Permissions come from a fixed catalog. Roles compose them; new permissions
cannot be created at runtime. `super_admin` is built in and locked — it cannot
be renamed or deleted, because the platform's own super-admin checks are
written against that name.

**Platform permissions are never granted inside a client.** The two
namespaces are kept separate deliberately: a client contact cannot be
elevated into platform access by editing their client-level role, however
that role is configured.

See [Users & Roles](users-roles.md).

### The permission ceiling

Each client record carries a **Permission Ceiling** — the furthest any
client-side user on that account can ever reach, whatever role they are given
inside it.

Default deployments have nothing to set here, because clients are read-only
customer accounts. It exists so that granting a client more capability later is
an explicit, per-account decision with a hard upper bound, rather than
something that can drift outward one role edit at a time.

## Tool access

Administrative tooling — dashboards, queue monitors, storage browsers — is
gated by individual permissions rather than bundled into "admin". Somebody
who needs the queue dashboard does not need the database browser.

Grant these narrowly. They are the surfaces where one person's mistake
reaches every client at once.

## Impersonation

A super-admin can sign in as another user, which is how you see exactly what a
client contact sees when they report that something looks wrong.

The rules it enforces:

- **Super-admin only.** No other role can start it.
- **One level deep.** You cannot impersonate while already impersonating.
- **Not yourself.** The obvious no-op is refused rather than silently allowed.
- **It really switches.** Every permission check sees the impersonated user,
  which is the entire point — a session that kept your own permissions would
  not show you what they see.

Stopping returns you to your own account.

> **There is no button and no on-screen banner yet.** Impersonation is reachable
> by its route, and nothing on the page tells you that the session you are
> looking at is not your own. Treat it as a deliberate, short, know-what-you-are-doing
> operation until both of those exist. See the [Roadmap](../roadmap.md).

## Session and login

Session lifetime and cookie protection are configured under **System →
Settings**, in the Sessions and Security sections. See
[Platform Settings](platform-settings.md).

Logout reasons are configured under **Preferences → Logout Reasons**, and
availability reasons under **Preferences → Availability Reasons**.

Logging out may ask for a reason. That is operational rather than a security
control, but it makes an unexpected drop-off distinguishable from a normal
end of shift when you are looking back at coverage.

## Handling caller data

A few things worth knowing about how the platform treats what it holds:

- **Text attachments** are stored on your own object storage rather than
  left on the carrier, and served through short-lived signed links rather
  than guessable URLs.
- **Backups are encrypted** before they leave the host, and cannot be read
  without the passphrase you hold. See [Backups](backups.md).
- **Optional error reporting and tracing** strip caller names, numbers, and
  message bodies before anything leaves the platform. Both are off by
  default. See [Observability](observability.md).

If you run Orbital under a contract that constrains sub-processors, read the
[Observability](observability.md) page before pointing either integration at
a hosted service.

## See also

- [Users & Roles](users-roles.md) — creating accounts and assigning roles
- [Backups](backups.md) — protecting against loss rather than access
