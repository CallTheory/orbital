# Security policy

Orbital handles live phone calls, call recordings, and messages taken on
behalf of other people's customers. A vulnerability here is not an
inconvenience — it is somebody's medical answering service leaking patient
callbacks. We treat reports accordingly.

## Reporting a vulnerability

**Do not open a public issue.**

Email <security@calltheory.com>. If you want to encrypt, our PGP key is
published at <https://calltheory.com/.well-known/security.txt>.

Useful things to include, in rough order of value:

- What an attacker can do with it, concretely
- Steps to reproduce, ideally against a fresh `sail up` install
- The version or commit you tested (the About page in the admin panel, or
  `php artisan orbital:about`)
- Whether it needs authentication, and at what role
- Whether it crosses a tenant boundary — those are our highest severity by
  default

## What to expect

| | Target |
| --- | --- |
| Acknowledgement | 2 business days |
| Initial assessment | 5 business days |
| Fix for critical issues | 14 days, or a documented mitigation |
| Public advisory | After a fix ships, or 90 days, whichever comes first |

We will keep you updated as we work, credit you in the advisory unless you
prefer otherwise, and tell you plainly if we decide something is not a
vulnerability — along with the reasoning, so you can push back if we are
wrong.

## Scope

**In scope:** the Orbital application, its container images, the Helm chart,
generated telephony configuration, and default configuration shipped in this
repository. Cross-tenant data access, authentication and authorisation
bypasses, toll fraud paths, SIP or media abuse, and remote code execution are
all in scope and all taken seriously.

**Out of scope:**

- Vulnerabilities in unmodified upstream components (Asterisk, Kamailio,
  LiveKit, PostgreSQL, and so on) — report those upstream. If Orbital's
  *configuration* of one of them is what makes it exploitable, that is in
  scope and we want to hear about it.
- Findings against a deployment you do not operate or have permission to test.
  Do not test against `calltheory.com` or any Orbital Cloud tenant without
  written authorisation.
- Missing hardening headers, weak TLS ciphers, or similar findings with no
  demonstrated impact.
- Anything requiring an already-compromised host or a malicious super-admin.
  A super-admin can already regenerate the dialplan; that is the job.

## Supported versions

Security fixes land on the current release line. Support subscribers get
backports to the previous release line for the length of their subscription.
Self-hosters running older releases can always cherry-pick the fix — the
source is right here, and that is the point.

## Hardening a deployment

Two obligations that fall on whoever runs Orbital, not on this repository:

- **Rotate every default credential before you take real calls.** The
  bootstrap process generates development defaults so `sail up` works out of
  the box. `php artisan orbital:status` will tell you which ones are still at
  their defaults.
- **Never expose Asterisk's AMI/ARI ports, the Prometheus or Pushgateway
  endpoints, the Patroni REST API, or the HAProxy stats socket to an untrusted
  network.** The compose files bind them to the internal network for a reason.
  The HA overlays assume a private network between nodes.

See [`docs/admin/high-availability.md`](docs/admin/high-availability.md) for
the network topology these assumptions rest on.
