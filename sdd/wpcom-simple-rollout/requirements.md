# wp.com Simple Rollout — Requirements

## Goal

Define how FOSSE gets deployed onto wp.com Simple on a manual, maintainer-triggered cadence, and how it coexists with the ActivityPub plugin already loaded platform-wide on wp.com — without breaking AP for non-FOSSE blogs. Initial cohort is an allowlisted beta (internal blogs + a small external tester set) while FOSSE is under active development.

## Requirements

1. **Greenfield deploy mechanism.** Specify how a FOSSE build artifact (zip / source-tree commit / equivalent) lands on wp.com Simple. Triggered manually by a maintainer; no auto-deploy in v1. The mechanism must be documented as a repeatable runbook so any maintainer can ship a new version.
2. **Per-blog FOSSE flag.** A site option or wp.com blog sticker (TBD in spec) that opts a blog into using FOSSE. Must be settable by ops / maintainers since the launch cohort is allowlisted beta — no self-serve toggle in v1.
3. **Selective ActivityPub load on wp.com Simple.**
    - On flagged blogs: FOSSE's bundled ActivityPub loads; wp.com's platform AP is suppressed for that blog.
    - On non-flagged blogs: platform AP loads as usual; FOSSE is inert (or not loaded at all).
    - Single source of AP per blog at all times — no class-redeclaration, no double-load, no leaking FOSSE behavior into non-flagged blogs.
4. **Atmosphere coexistence on wp.com Simple.** Define what happens with FOSSE's bundled Atmosphere. Working assumption: Atmosphere is not platform-wide on wp.com today, so it loads from the FOSSE bundle on flagged blogs only. Spec must confirm.
5. **Allowlisted beta cohort.** Provide a mechanism for ops to add/remove blogs from the cohort (mechanically tied to requirement 2).
6. **Rollback / kill switch.** A way for a maintainer to disable FOSSE on a flagged blog (or globally) without waiting for a redeploy — for cases where a freshly-shipped build breaks federation.
7. **Local-environment behavior unchanged.** The existing `bundled-backends` skip-when-standalone logic in `fosse.php:42-58` continues to work off wp.com (constant-defined and disk-path checks for both AP and Atmosphere). Selective-load logic for wp.com Simple is additive, not a replacement.
8. **Linear deliverable shape.** Output of the spec/plan phases is a parent Linear issue describing the rollout, with sub-issues for the distinct work streams (pipeline, flag mechanism, selective-load mechanics, runbook + rollback).

## Constraints

- FOSSE is under active development; bundling stays. The unbundle path (single shared AP on wp.com, FOSSE drops the bundle) is the long-term north star, not this rollout.
- Must not regress ActivityPub for the rest of wp.com (every non-flagged blog).
- Manual trigger means a documented, reproducible runbook is part of the deliverable — not a side artifact.
- wp.com Simple only. No Atomic / Jetpack-connected rollout in this scope.
- Bundled-backends conventions still apply: never hand-edit `bundled/`; refresh via `tools/sync-bundled.sh`; bundled source stays excluded from FOSSE's tooling (PHPCS, PHPUnit, ESLint, Prettier, Jest, classmap).

## Out of Scope

- Auto-deploy / CI-driven continuous deployment (manual only in v1).
- Self-serve user-facing opt-in (no settings toggle for blog owners).
- Long-term single-AP-on-wp.com / FOSSE-drops-bundling path. Forward-looking note only.
- wp.com Atomic and Jetpack-connected sites.
- Reader-side / inbound consumption behavior on wp.com.
- UI changes to FOSSE's admin surface specific to wp.com (FOSSE's admin UI is shared across environments).
- Migration of existing wp.com AP user data when a blog flips its FOSSE flag (option keys/schema match upstream by construction; explicit migration is future work if it becomes needed).

## Open Questions

- **Flag mechanism:** site option vs wp.com blog sticker? Sticker is the typical ops-controlled wp.com pattern and is the straw-man recommendation; spec confirms after looking at how similar wp.com gating is done today (Jetpack feature stickers, `wpcom_*` options, etc.).
- **Platform AP load path:** how exactly is wp.com's platform ActivityPub loaded today, and what's the cleanest suppression hook for flagged blogs? Spec must investigate before committing to selective-load mechanics. Likely lives in a wp.com mu-plugin / Jetpack-mu-wpcom-style tree.
- **Artifact destination:** where does the FOSSE artifact live on wp.com Simple — vendored into a wp.com source tree, or synced from a GitHub release / `latest-trunk` prerelease? Spec picks based on what wp.com Simple supports for plugin sourcing today.
- **Atmosphere on wp.com Simple today:** present in any form, or fully greenfield? If present, same selective-load story applies; if not, the bundled copy just loads on flagged blogs.
- **Rollback granularity:** per-blog flag flip, global FOSSE kill switch, or both? Spec should pick based on the failure modes that matter most (one bad blog vs. broken-everywhere builds).
- **Runbook ownership and access:** who can trigger a deploy (FOSSE maintainers only? wp.com systems team?), and how is access enforced? Spec captures the required roles/permissions.

## Related Code / Patterns Found

- `fosse.php:23-122` — current bundled-backends bootstrap. Skips bundled load when standalone is present (constant-defined or disk-path) and runs first-load activation shim on `init` priority 20. wp.com Simple selective-load logic should compose with this, not replace it.
- `sdd/bundled-backends/requirements.md` — prior art for the bundling decision and skip-when-standalone behavior. Tracked under DOTCOM-16799 ("Distribution, infrastructure, and external partnerships"). The wp.com rollout is the natural follow-on under that same distribution umbrella.
- `bin/build-zip.sh` and `.github/workflows/build-zip.yml` — current artifact pipeline. Produces `build/fosse.zip` on every release and refreshes a rolling `latest-trunk` prerelease on each push to `trunk`. Whichever artifact destination the spec picks for wp.com, this is the upstream source of truth.
- `tools/sync-bundled.sh` — refreshes `bundled/activitypub/` and `bundled/atmosphere/` from upstream checkouts. Worth reviewing when reasoning about how often bundled AP drifts from whatever AP wp.com is running platform-wide.
- `AGENTS.md` "Upstream contribution policy" section — post-type-agnostic correctness goes upstream. Any suppression hook for platform AP that would benefit other consumers should land in `wordpress-activitypub` upstream, not as a FOSSE-only shim.
- `sdd/<feature>/plan.md` Status convention (AGENTS.md "SDD plan status tracking") — the plan from this rollout SDD must use the new `Status` field convention since it's a fresh SDD.
