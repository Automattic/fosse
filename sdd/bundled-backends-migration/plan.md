# Bundled Backends Migration — Implementation Plan

Based on: [sdd/bundled-backends-migration/spec.md](./spec.md)

## Status: DEFERRED

**No implementation tasks are scheduled.** The spec captures the future migration design; this plan tracks the trigger conditions that would unblock implementation, plus a sketch of what the plan would expand into when those triggers fire.

## Trigger checklist

Activate this plan (move tasks from "Sketch" below into a numbered checklist, set status to "In Progress") when ANY trigger fires:

- [ ] **Atmosphere ships a stable public release** — versioned tag, published on a reachable distribution channel (wordpress.org, an Automattic Composer registry, or a stable GitHub release artifact).
- [ ] **Bundling actively breaks** — `tools/sync-bundled.sh` produces unrecoverable conflicts, an upstream security advisory requires faster turnaround than sync allows, or the bundled zip exceeds a distribution channel's size limit.
- [ ] **WordPress.org plugin dependency tooling matures** to the point where declarative cross-plugin dependencies work for the standalone install case (currently in-development in WP core).
- [ ] **wp.com platform asks for separated artifacts** as part of its own deployment evolution.
- [ ] **Three or more sync conflicts in a single quarter** that require manual resolution beyond the standard `sync-bundled.sh` flow.

## Standing constraints (apply continuously while deferred)

These are not tasks; they're rules contributors should respect today so deferring this work doesn't grow the eventual migration delta. Verified by reviewers, not by this plan's task list.

- [ ] Don't add new `require_once bundled/...` calls outside `fosse.php`'s existing bootstrap.
- [ ] Don't add filters/hooks whose contracts depend on bundled code being at a specific filesystem path.
- [ ] Don't add tests that reach into `bundled/` directly (use the public API of the bundled plugins, or stub them).
- [ ] Treat `bundled/` as read-only; `tools/sync-bundled.sh` is the only writer.
- [ ] Land protocol-agnostic functionality in the upstream repos first (existing upstream-first policy).
- [ ] Document the load contract at every coupling point — match the pattern set by `fosse.php:25-38` (the wp.com Simple load contract comment).

## Sketch of the implementation plan, when activated

When a trigger fires, expand each of the following into a real task with files / dependencies / verification steps. Until then, this is a sketch.

1. **Publish migration decision record.** Confirm with project owners (likely kraft + RCowles + whoever owns wp.com Simple deployment at the time) that the spec's chosen direction (two-phase bridge → migration) still reflects the right call given the trigger context.
2. **Add bridge policy and provenance for checked-in bundles.** Backend-version manifest or package-lock equivalent so vendored source has traceable provenance during the bridge phase.
3. **Prove package-based artifact assembly.** CI job that resolves AP and Atmosphere from versioned package inputs (Composer VCS, GitHub release zips, or registry — pick at activation time) and assembles an installable FOSSE zip without `bundled/` source.
4. **Add CI checks for bundle and package artifacts.** Compare the generated artifact byte-for-byte (or structurally) with the current bundled output. Both pipelines run until cutover.
5. **Design and implement external-backend dependency UX.** Missing AP shows clear action; missing Atmosphere shows clear action; installed-but-inactive distinguished from absent; FOSSE setup/status pages remain useful when one backend is unavailable.
6. **Define wp.com Simple artifact ownership.** Coordinate with wp.com platform team on whether the platform assembles FOSSE + AP + Atmosphere from package inputs or continues vendoring. Document the decision and the new load contract.
7. **Choose migration cutover and update release docs.** Pick the release where checked-in `bundled/` stops being the source of truth. Update AGENTS.md, CONTRIBUTING.md, and any release engineering docs.
8. **Open checked-in bundle removal follow-up after replacement ships.** Separate PR; not part of cutover. Removes `bundled/`, `tools/sync-bundled.sh`, and bundle-specific export/linguist/tooling rules after the replacement has shipped and run in production for at least one release cycle.

## What to do right now

Nothing in this plan. If a trigger fires, the right next step is to re-read `spec.md`, expand this sketch into real tasks, and push a new commit that flips the status from DEFERRED to IN PROGRESS. Until then, leave the SDD alone — it's reference material, not implementation guidance.
