# Backups

Nightly encrypted PostgreSQL backups to an off-box destination, plus the
CLI restore path.

> **This is not the same thing as high availability.** HA keeps you
> serving when a node dies. Replication keeps your data when a disk
> dies. Neither helps when somebody drops a table, a migration goes
> wrong, or an account is compromised — those changes replicate to every
> copy instantly and faithfully. Backups are the only one of the three
> that recovers from a mistake.

---

## What is and isn't covered

```
BACKED UP     PostgreSQL — every client, message, call log, recording
              pointer, routing rule, flow, persona, and setting
NOT BACKED UP Object storage content — recording audio, MMS media,
              mail attachments
NOT BACKED UP The encryption key itself, or your .env
```

**The database is the part that cannot be reconstructed from anything
else.** Asterisk dialplan is regenerated from it (`orbital:generate-config`),
and object storage content is large, immutable once written, and better
protected by the storage layer's own versioning and lifecycle rules than
by a nightly copy.

That is a real gap and worth naming rather than discovering: **if you
delete the recordings bucket, these backups do not bring it back.** Turn
on object versioning and a delete-protection policy at your storage
provider. On Vultr Object Storage, AWS S3, or Backblaze B2 that is a
setting, not a project.

---

## Setting it up

```
php artisan orbital:backup --generate-key
```

Put the printed key in `BACKUP_ENCRYPTION_KEY`, then:

```
BACKUP_ENABLED=true
BACKUP_KEEP_DAYS=30
BACKUP_AWS_BUCKET=orbital-backups
BACKUP_AWS_ACCESS_KEY_ID=...
BACKUP_AWS_SECRET_ACCESS_KEY=...
BACKUP_AWS_ENDPOINT=https://ewr1.vultrobjects.com
```

Once enabled the scheduler runs it nightly at 03:00 — after the
queue-metrics rollup and its 90-day prune, so archives don't carry rows
that are about to be deleted anyway.

### Two things that are deliberate

**The destination credentials are separate from the application's.**
`BACKUP_AWS_*`, not `AWS_*`. Backups kept in the same bucket under the
same key the app writes recordings with are destroyed by the same
compromised credential or buggy delete that destroys the recordings —
the exact failure a backup exists to survive. Give the backup key write
and list on its own bucket and nothing else.

**Losing `BACKUP_ENCRYPTION_KEY` means losing every archive taken with
it.** There is no recovery path and that is not an oversight: a backup
system with a way in for us is a backup system with a way in for
somebody else. Store the key in a password manager or your
infrastructure secret store — somewhere that is *not* this platform,
because "the platform is gone" is the scenario you are buying insurance
against.

---

## How the archive is protected

Archives are encrypted with libsodium's secretstream
(XChaCha20-Poly1305), streamed a megabyte at a time so a 40 GB dump
never sits in memory. The passphrase is stretched with Argon2id against
a random per-archive salt.

The encryption is **authenticated**, which matters more at restore time
than at rest:

| Failure | What happens |
|---------|--------------|
| Wrong passphrase | Refused before anything touches the database |
| Corrupted download | Refused — a single flipped bit fails the tag check |
| Truncated upload | Refused — the stream has an explicit end marker, so a cut-short archive is detectably incomplete rather than decrypting cleanly up to the cut |

That last one is why there is no separate checksum file. Tampering and
truncation are caught by construction, and a checksum an attacker can
rewrite was never protection.

---

## Restoring

Restore is CLI-only, deliberately: the moment you need it is the moment
the admin UI is most likely to be the broken thing.

```
php artisan orbital:restore --list
php artisan orbital:restore orbital-2026-09-04-030000.dump.enc --clean
```

It runs in stages — download, decrypt, verify, then restore — and
`--decrypt-only` stops after the third so you can inspect or relocate
the dump before anything writes to a database. `--clean` drops existing
objects first and is required to overwrite a populated database; the
confirmation prompt is not skippable without `--force`.

Afterwards, restart Horizon and the agent worker so they pick up
restored configuration, and run `php artisan orbital:generate-config` to
rebuild the Asterisk dialplan from the restored rows.

### The APP_KEY trap

Encrypted columns — platform settings, two-factor secrets, API tokens —
are encrypted with `APP_KEY`, **not** with the backup passphrase.
Restoring a dump into an install with a different `APP_KEY` leaves those
columns as undecryptable noise while everything else looks perfect.

Every archive's manifest records a fingerprint of the `APP_KEY` that was
in force when it was taken, so a mismatch can be spotted before it
becomes a mystery. When you rebuild a host, restore `APP_KEY` alongside
the database.

---

## Knowing it still works

A backup system that stops working has no symptom other than silence:
the job fails, the log line scrolls past, and everything looks healthy
until somebody needs a restore. Three alerts cover that
(`docker/prometheus/rules/orbital.yml`):

| Alert | Fires when |
|-------|-----------|
| `OrbitalBackupStale` | No success in 48h — at least two nightly runs have failed |
| `OrbitalBackupNeverSucceeded` | Enabled for 6h and not one run has ever worked |
| `OrbitalBackupShrankSharply` | Latest archive is under half its fortnight high-water mark, which usually means a dump that failed partway and uploaded anyway |

See [Observability](observability.md) for getting those alerts delivered
somewhere.

**Test a restore before you need one.** `--decrypt-only` against a
recent archive costs nothing and proves the passphrase you have on file
is the one the archives were written with. An untested backup is a
belief, not a plan.

---

## See also

- [Observability](observability.md) — alert delivery
- [High Availability](high-availability.md) — the failures HA covers, which are different ones
- `config/backup.php` — every setting, with the reasoning
