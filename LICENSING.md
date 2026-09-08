# Orbital Licensing

**Orbital is free software under the GNU Affero General Public License,
version 3.** The full text is in [`LICENSE`](LICENSE).

There is no "community edition." There is no "enterprise edition." There is
one Orbital, and you can run all of it.

## What self-hosting costs

Nothing, forever.

- Unlimited clients, users, operators, and concurrent calls
- Every channel — voice, email, web chat, messaging
- Every module — AI voice agents, orchestrations, knowledge base, call
  recording, voicemail, reporting, the tenant portal, high availability
- No license key required to run anything
- No seat counting, no call metering, no phone-home, no expiry

If you find a feature in this repository that refuses to run without a paid
key, that is a bug. Open an issue.

## What we sell

Three things, none of which are code we withheld from you:

### Orbital Cloud

We run Orbital for you — provisioning, upgrades, backups, monitoring, carrier
relationships, and the on-call rotation. You get a tenant instead of a
datacenter. Best fit if telephony infrastructure is not the business you want
to be in.

### Support subscriptions for self-hosters

You run it, we back you up: response-time commitments, upgrade assistance,
architecture review, direct access to the people who wrote the code, and a
signed update channel with pre-built container images so you are not building
from source on every release.

Buying support does not change what the software does. The subscription key
you paste into Platform Settings enables in-app support ticket submission and
the signed update channel — nothing else. Let it expire and Orbital keeps
running exactly as it did the day before.

### Managed services

We staff and operate the answering service itself — flow authoring, persona
tuning, queue design, and ongoing optimization — on top of either deployment
model.

## What the AGPL asks of you

The short version, which is not legal advice:

- **Running Orbital, for yourself or for paying customers, requires nothing.**
  Use it commercially. Run an answering service on it. Charge whatever you
  want.
- **Modifying Orbital and letting other people use it over a network means
  publishing your modifications** under the AGPL, and offering the
  corresponding source to those users. This is section 13, and it is the
  reason we chose this license.
- **Redistributing Orbital** — images, VMs, appliances — means passing along
  the same freedoms and the same license to whoever receives it.

Orbital satisfies its own section 13 obligation: every panel footer links to
the running version's source, `/source` redirects to the repository, and the
About page names the exact commit you are running. If you fork and modify
Orbital, point `ORBITAL_SOURCE_URL` at *your* source. Leaving it pointed at
ours while shipping changed code does not satisfy your obligation, and is
exactly the situation section 13 exists to prevent.

## Bundled components

Orbital orchestrates several independently licensed systems — Asterisk,
Kamailio, rtpengine, LiveKit, PostgreSQL, Valkey, SeaweedFS, and others. They
run as separate processes in separate containers and are not linked into
Orbital. See [`NOTICE`](NOTICE) and
[`docs/third-party-licenses.md`](docs/third-party-licenses.md).

## The name

The code is free. The name is not. See [`TRADEMARK.md`](TRADEMARK.md) — fork
freely, but ship it under your own name.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Contributions are accepted under a
contributor licence agreement ([`CLA.md`](CLA.md)).

## Questions

Licensing questions that this document does not answer:
<licensing@calltheory.com>.
