# Contributing to Orbital

Orbital is AGPL-3.0. Patches, bug reports, and dialplan war stories are all
welcome.

## Before you start

For anything beyond a bug fix, open an issue first and describe what you want
to change. Orbital touches telephony, media, and multi-tenant data isolation —
a well-meaning change in the wrong layer can drop live calls or leak one
client's messages into another's portal. A short conversation up front is
cheaper than a rewritten pull request.

## Contributor licence agreement

Pull requests are accepted under the CLA in [`CLA.md`](CLA.md). You keep
copyright in what you write; you grant Call Theory the rights needed to ship
it under the AGPL and to relicense it if the project's license ever changes.
Sign once (comment `I have read and agree to the CLA` on your first pull
request) and it covers everything you contribute afterwards.

If you cannot sign — many employers restrict this — say so in the issue.
Describing the fix well enough that a maintainer can write it independently
is a real contribution too.

## Development setup

```
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
pnpm install
php artisan migrate --seed
pnpm run dev
```

`composer dev` runs the server, queue worker, log viewer, and Vite together.
See [`README.md`](README.md) for the full environment tour and
[`CLAUDE.md`](CLAUDE.md) for the repo's architectural conventions.

## House rules

These are not style preferences; each one exists because breaking it caused a
real problem.

- **`declare(strict_types=1);` in every new PHP file.**
- **`vendor/bin/pint` before you push.** CI enforces it.
- **Every tenant-scoped model uses the `BelongsToTeam` trait.** A model that
  forgets it is a cross-tenant data leak, not a style violation. Add a test
  that proves the scoping holds.
- **Never hand-edit generated Asterisk config.** It lives in the database and
  is rendered by the Blade templates in `resources/views/asterisk/`. Change
  the template or the model, then run `php artisan orbital:generate-config`.
- **No public CDNs.** Every JS and CSS dependency is bundled locally through
  Vite. Orbital must run on a LAN with no route to the internet.
- **`pnpm`, not `npm`.**
- **Filament components for all in-app UI.** Do not embed Jetstream forms or
  components inside a panel.
- **No emoji in UI copy, notifications, or views.** Use Heroicons or words.
- **Keep controllers thin.** Logic belongs in `app/Services/`.
- **Long-running work goes in a queued Job**, not in a request cycle.
- **New third-party dependencies get a row in
  [`docs/third-party-licenses.md`](docs/third-party-licenses.md)** in the same
  pull request, with the license. A GPLv2-only *library* (as opposed to a
  separate containerized program) cannot be accepted — see [`NOTICE`](NOTICE).

## Tests

```
php artisan test
```

Telephony, auth, and multi-tenancy have the most coverage and the least
tolerance for regressions. New behavior in those areas needs a test. For
everything else, use judgment — a test that would have caught the bug you are
fixing is worth writing.

## Security issues

Do not open a public issue. See [`SECURITY.md`](SECURITY.md).

## Commit messages

Explain why, not what — the diff already says what. If a change is a
workaround for upstream behavior, name the upstream version it works around,
because someone will try to "clean it up" in eighteen months.
