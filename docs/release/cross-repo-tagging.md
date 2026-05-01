# Cross-repo release tagging

Two repos cooperate at release time:

- **orbital** (private) — app source, Dockerfiles, Helm chart source, all CI workflows. Tags are created here on `git tag v<X> && git push --tags`.
- **orbital-setup** (public) — IaC (Terraform, Ansible, cloud-init, install scripts). No CI builds happen here.

Both repos end up with `v*` tags pointing at distinct commits, by design.

## How orbital-setup gets v* tags

When a `v<X>` tag is pushed to **orbital**, three workflows fire in parallel:

| Workflow                           | What it produces                          | Lands on                |
|------------------------------------|-------------------------------------------|-------------------------|
| `publish-images.yml`               | 3 signed container images                 | Harbor                  |
| `publish-helm.yml`                 | 1 signed Helm chart (OCI)                 | Harbor                  |
| `publish-release-archives.yml`     | OCI image archives + chart `.tgz` + sigs  | orbital release (private) |
| `publish-rtpengine-debs.yml`       | 4 rtpengine `.deb`s + sigs                | **orbital-setup release (public)** |

The last workflow uses Forgejo's API to GET-or-create a release at the same `v<X>` tag on `calltheory/orbital-setup`. If the tag doesn't exist there yet, the release POST is sent with `target_commitish=main` — Forgejo creates a **lightweight tag** in orbital-setup pointing at its main HEAD as a side effect.

Result: orbital-setup accumulates `v*` lightweight tags it never created locally. They mark "the orbital-setup HEAD that was current when orbital `v<X>` released," which is useful as a snapshot anchor (operators can check out `v<X>` in orbital-setup to get the IaC layout that paired with that orbital release).

## Why this works

- The two repos move at different cadences. orbital-setup might commit twice a week; orbital might tag a release every two weeks. Forcing matched semver between them creates pointless ceremony.
- Customers always look at the orbital-setup release page (it's public and machine-fetchable). The orbital release page is for internal audit + customer handoff, not provisioning.
- Cosign signatures are bound to file content, not git tags. The `.deb`s on orbital-setup's `v<X>` release are byte-identical to what was built in orbital's CI; the signature proves provenance regardless of which repo's tag it landed under.

## Bumping the rtpengine version

Edit `RTPENGINE_TAG_DEFAULT` in `.forgejo/workflows/publish-rtpengine-debs.yml` (this repo). That env var is the **single source of truth** for which rtpengine release ships in the next orbital tag. The recipe document at `orbital-setup/ansible/roles/rtpengine/SOURCE-BUILD.md` describes the build process; the workflow encodes it; the `RTPENGINE_TAG_DEFAULT` env var pins the version.

Don't pin rtpengine in orbital-setup's ansible role — the role consumes whatever .debs landed on the matching orbital-setup release, no version logic needed.

## Required secrets on orbital app repo

| Secret                            | Used by                          | Scope                                       |
|-----------------------------------|----------------------------------|---------------------------------------------|
| `HARBOR_REGISTRY` / `HARBOR_USERNAME` / `HARBOR_PASSWORD` | publish-images, publish-helm, publish-release-archives | Harbor robot account, push to `orbital/*` and `orbital/charts/*` |
| `COSIGN_KEY` / `COSIGN_PASSWORD`  | all four workflows               | Same key signs images, chart, release archives, .debs |
| `ORBITAL_SETUP_RELEASE_TOKEN`     | publish-rtpengine-debs           | Forgejo PAT, write scope on `calltheory/orbital-setup` releases |

The `ORBITAL_SETUP_RELEASE_TOKEN` is the only secret unique to the rtpengine flow. If it's missing or revoked, that workflow fails but the other three still publish to orbital + Harbor.
