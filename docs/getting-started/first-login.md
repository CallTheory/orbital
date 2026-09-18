# First Login

What to do the first time you open a fresh Orbital installation.

## Creating the first administrator

**A new installation has no login.** Seeding creates roles, permissions, and
reference data, but it does not invent an administrator — a password shipped
in configuration is a password that ends up somewhere it should not.

Create the first account from the command line on the server:

```
php artisan orbital:make-admin
```

It asks for a name, email, and password. On a Kubernetes install, run it
inside the application pod, and pass `--name`, `--email` and `--password` as
flags — without them the command prompts, and a non-interactive shell gives
it nowhere to prompt.

That account is a super-admin: it can see and change everything, including
creating other administrators.

## The three panels

Orbital has three separate front doors. Everyone signs in at the same place
and lands in the one their role allows.

| Panel | Path | For |
|---|---|---|
| **Admin** | `/admin` | Platform staff configuring the system |
| **Operator** | `/operator` | Staff taking calls and messages |
| **Portal** | `/portal` | Your clients, reading what was taken for them |

Someone who reaches the wrong panel is redirected to theirs rather than
shown an error.

## Set up in this order

Each step depends on the ones before it.

**1. Branding.** System → Branding, and Preferences → Portal Branding. Your name and logo, on both
the staff panels and the client portal. Doing this first means every
screenshot you take afterwards is already right.

**2. AI provider credentials.** Conversational AI → Providers. Nothing
AI does anything until a model key is set. See
[Platform Settings](../admin/platform-settings.md).

**3. Check system status.** Monitor → System Status. Components you did not deploy
read "not deployed" rather than red. Anything genuinely red is worth
resolving before you build on it.

**4. Run setup.** System → Setup initializes the external services — object
storage buckets, the `vector` extension, Asterisk and LiveKit configuration.
Re-running a bootstrapper is safe. See
[System Maintenance](../admin/system-maintenance.md).

**5. Turn on backups.** Not glamorous and easy to postpone, but the platform
is about to start holding other people's callers. See
[Backups](../admin/backups.md).

**6. Create your first client.** See [Clients](../admin/clients.md). Use a
real one rather than a "test" account — test accounts have a way of becoming
permanent and confusing.

**7. Create operators and an agent group.** See
[Users & Roles](../admin/users-roles.md) and
[Agent Groups](../admin/agent-groups.md).

**8. Provision a channel.** A phone number, an email address, or a messaging
number, with a queue behind it. See [Channels](../admin/channels.md).

**9. Place a test call.** The only test that covers the whole path.

## Two-factor

Two-factor is required for everyone, with a grace window that starts on each
user's first login. Enrol your own account early — being locked out of a
platform you just installed is a poor first day. See
[Security](../admin/security.md).

## See also

- [How Orbital Works](../concepts.md) — the model behind all of the above
- [Clients](../admin/clients.md) — configuring your first customer
