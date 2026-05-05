# Bundled Backends Migration Implementation Plan

Based on: [sdd/bundled-backends-migration/spec.md](./spec.md)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## Progress

- [ ] Task 1: Publish migration decision record
- [ ] Task 2: Add bridge policy and provenance for checked-in bundles
- [ ] Task 3: Prove package-based artifact assembly
- [ ] Task 4: Add CI checks for bundle and package artifacts
- [ ] Task 5: Design and implement external-backend dependency UX
- [ ] Task 6: Define wp.com Simple artifact ownership
- [ ] Task 7: Choose migration cutover and update release docs
- [ ] Task 8: Open checked-in bundle removal follow-up after replacement ships

## Tasks

### Task 1: Publish migration decision record

- **Status**: Not started
- **Files**:
  - Modify: `sdd/bundled-backends-migration/spec.md`
  - Create or modify: release/project tracking surface for DOTCOM-16826
- **Do**:
  1. Confirm the recommendation with project owners:
     - Near-term: keep artifact vendoring as a bridge.
     - Long-term: split FOSSE UI/orchestration from backend plugin distribution.
     - Transition proof: package-based artifact assembly.
  2. Record the accepted decision in the project tracking surface for DOTCOM-16826.
  3. If the accepted decision differs from this SDD, update `spec.md` before implementation begins.
- **Verify**:
  - DOTCOM-16826 links to the accepted decision.
  - `spec.md` has one clear chosen direction, not competing unranked options.
- **Depends on**: none

### Task 2: Add bridge policy and provenance for checked-in bundles

- **Status**: Not started
- **Files**:
  - Modify: `AGENTS.md`
  - Modify: `.gitattributes`
  - Modify: `tools/sync-bundled.sh`
  - Create: `bundled/manifest.json`
- **Do**:
  1. Add a `bundled/manifest.json` file recording:
     - backend slug (`activitypub`, `atmosphere`)
     - upstream repository
     - upstream commit SHA
     - upstream version constant or release tag
     - sync timestamp or release date
     - sync command inputs
  2. Update `tools/sync-bundled.sh` so every sync rewrites the manifest after copying both backends.
  3. Update `AGENTS.md` to state that checked-in `bundled/` is bridge-only and must not receive feature patches.
  4. Keep `.gitattributes` marking `bundled/**` as vendored/generated so reviews continue to focus on FOSSE-owned code.
- **Verify**:
  - Running `./tools/sync-bundled.sh` updates `bundled/manifest.json`.
  - Manifest SHAs match `git -C "$FOSSE_AP_SOURCE" rev-parse HEAD` and `git -C "$FOSSE_ATMO_SOURCE" rev-parse HEAD`.
  - No files under `bundled/` are changed by hand outside a sync.
- **Depends on**: Task 1

### Task 3: Prove package-based artifact assembly

- **Status**: Not started
- **Files**:
  - Create: `tools/build-backend-artifacts.sh` or equivalent packaging script
  - Create: backend package manifest or Composer package configuration
  - Modify: `bin/build-zip.sh` only if the proof becomes part of the official build
  - Modify: `composer.json` and `composer.lock` only if Composer is selected as the proof mechanism
- **Do**:
  1. Pick the proof input source:
     - Preferred: Automattic package registry with release artifacts.
     - Acceptable bridge: Composer VCS dependencies pinned by lockfile.
     - Fallback: GitHub release zips with checksums.
  2. Build an artifact from a clean checkout without reading checked-in `bundled/activitypub/` or `bundled/atmosphere/`.
  3. Assemble backend files into a temporary staging directory that mirrors the current runtime paths.
  4. Run the same zip sanity checks currently enforced by `bin/build-zip.sh`.
  5. Document whether the proof still embeds backend files in the final zip or moves to external plugin dependencies.
- **Verify**:
  - A clean checkout can produce an installable zip without relying on checked-in backend source.
  - The generated artifact contains `activitypub.php` and `atmosphere.php` when running in transitional embedded-backend mode.
  - The generated artifact boots in WordPress Playground.
- **Depends on**: Task 1

### Task 4: Add CI checks for bundle and package artifacts

- **Status**: Not started
- **Files**:
  - Modify: `.github/workflows/build-zip.yml`
  - Modify: `.github/workflows/e2e.yml`
  - Create or modify: artifact verification script under `tools/`
- **Do**:
  1. Keep the existing build-zip job green for the checked-in bundle.
  2. Add a non-release proof job that builds the package-based artifact from Task 3.
  3. Add smoke checks for both artifact shapes:
     - zip includes required FOSSE files
     - backend entrypoints are present when expected
     - `vendor/autoload_packages.php` is present
     - Playground boots wp-admin without fatal errors
  4. Fail CI if package pins drift from the recorded manifest or lockfile.
- **Verify**:
  - CI publishes or stores both artifacts for inspection.
  - CI clearly distinguishes "current release artifact" from "migration proof artifact".
  - Failed backend resolution blocks the proof job without blocking emergency releases until the migration is accepted.
- **Depends on**: Task 3

### Task 5: Design and implement external-backend dependency UX

- **Status**: Not started
- **Files**:
  - Modify: `src/Admin/*Provider*.php`
  - Modify: `src/Admin/templates/*.php`
  - Modify: `fosse.php`
  - Modify or create: PHPUnit tests under `tests/php/Admin/`
  - Modify or create: Playwright specs under `tests/e2e/`
- **Do**:
  1. Reuse `Automattic\Fosse\Bundled\Standalone_Backend_Status` from `sdd/deactivation-lifecycle/` if that SDD has shipped; otherwise keep any local state helper compatible with that planned contract.
  2. Define provider states for:
     - bundled backend loaded
     - standalone backend active
     - standalone backend installed but inactive
     - backend missing
  3. Render clear setup/status UI for missing or inactive dependencies.
  4. Ensure FOSSE does not fatal when either backend is absent.
  5. Preserve current one-click behavior while bundled bridge mode remains active.
  6. Add tests covering each provider state.
- **Verify**:
  - PHPUnit covers provider status/state mapping.
  - Playwright covers admin setup/status rendering when one backend is missing.
  - Manual Playground smoke confirms no class collisions with standalone ActivityPub or Atmosphere installed.
- **Depends on**: Task 1

### Task 6: Define wp.com Simple artifact ownership

- **Status**: Not started
- **Files**:
  - Modify: deployment or release documentation surface selected by the project
  - Modify: `fosse.php` only if the load contract changes
  - Modify: wp.com-specific artifact build configuration, if owned in this repo
- **Do**:
  1. Decide whether wp.com Simple consumes:
     - the public FOSSE zip,
     - a platform-specific FOSSE-plus-backends artifact, or
     - separately deployed FOSSE, ActivityPub, and Atmosphere plugins.
  2. Record who owns backend version pins for wp.com Simple.
  3. Preserve the load-order contract documented in `fosse.php`, or replace it with a new documented contract.
  4. Define rollback steps for a bad backend version.
- **Verify**:
  - wp.com Simple has a named artifact owner and version source of truth.
  - Rollout and rollback do not depend on editing checked-in `bundled/` by hand.
  - FOSSE's public build path and wp.com artifact path are both documented.
- **Depends on**: Task 3

### Task 7: Choose migration cutover and update release docs

- **Status**: Not started
- **Files**:
  - Modify: `AGENTS.md`
  - Modify: release checklist or changelog surface
  - Modify: `sdd/bundled-backends-migration/plan.md`
- **Do**:
  1. Choose the release where checked-in `bundled/` stops being the source of truth.
  2. Update release docs with the selected model:
     - build-time embedded backends, or
     - external backend dependencies.
  3. Record the final cutover criteria in this plan.
  4. Mark bridge-only tasks done with PR references.
- **Verify**:
  - Release docs explain how backend versions are selected.
  - The plan has no ambiguous "temporary" state without an owner or date.
  - Project owners agree the replacement is ready.
- **Depends on**: Task 4, Task 5, Task 6

### Task 8: Open checked-in bundle removal follow-up after replacement ships

- **Status**: Not started
- **Files**:
  - Create or modify: follow-up Linear issue or SDD for checked-in bundle removal
  - Modify: `sdd/bundled-backends-migration/plan.md`
  - Modify: release/project tracking surface for DOTCOM-16826
- **Do**:
  1. Confirm Tasks 3-7 are accepted and the replacement path is green in CI.
  2. Open a focused removal follow-up that lists the deletion scope:
     - `bundled/activitypub/**`
     - `bundled/atmosphere/**`
     - `tools/sync-bundled.sh`
     - `tools/bundled-excludes.txt`
     - bundle-specific loader, build, lint, test, and export-ignore rules
  3. Record the selected final artifact model and migration notes for sites moving from bundled bridge mode to external/plugin-artifact mode.
  4. Mark this SDD complete only after the follow-up has an owner, acceptance criteria, and rollback notes.
- **Verify**:
  - Follow-up tracking exists and links back to DOTCOM-16826.
  - This SDD still states that it does not remove `bundled/`.
  - The follow-up has explicit verification for lint, tests, e2e, and installable artifact checks.
- **Depends on**: Task 7
