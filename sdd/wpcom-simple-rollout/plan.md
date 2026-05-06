# Implementation Plan: wp.com Simple Rollout

Based on: `sdd/wpcom-simple-rollout/spec.md`

> **Distribution note:** This SDD is **not** committed to the public `Automattic/fosse` repo. Once this plan is settled it converts into a **Linear epic with sub-issues** — each task below becomes one sub-issue, and the parent epic carries the spec.md content. From that point Linear is the source of truth and this file becomes a frozen local snapshot. Do not `git add` `sdd/wpcom-simple-rollout/`.

## Progress

- [ ] Task 1 — Phase 0 confirmations
- [ ] Task 2 — Build & vendor initial FOSSE artifact onto wp.com
- [ ] Task 3 — Implement `wp-content/mu-plugins/fosse-loader.php`
- [ ] Task 4 — Add wp.com load-contract comment to `fosse.php` (public FOSSE repo)
- [ ] Task 5 — Sandbox coexistence smoke tests
- [ ] Task 6 — Write deploy + rollback runbook
- [ ] Task 7 — Verify rollback paths on sandbox
- [ ] Task 8 — Roll out to beta cohort

## Tasks

### Task 1: Phase 0 confirmations

- **Status**: Not started
- **Files**: none (research / coordination)
- **Do**:
    1. Confirm `enable-fosse` blog sticker name has no collision in the existing wp.com sticker namespace. Cross-check against existing stickers in `wp-content/mu-plugins/wpcom-activitypub-load.php` (e.g. `enable-activitypub`, `activitypub-edge`, `enable-mastodon-apps`, `enable-activitypub-user-mode`, `activitypub_schema_update_queued`) and any wpcom-internal sticker registries / lookup tools.
    2. Confirm artifact destination `WP_PLUGIN_DIR/fosse/<version>/` is acceptable to wp.com plugin sourcing today (parallels `WP_PLUGIN_DIR/activitypub/<version>/`). Fallback: `wp-content/mu-plugins/fosse/`. Pick one.
    3. Verify the `jetpack_sync_remote_action` handlers registered at `wpcom-activitypub-load.php:313/339/365/394` never run with `get_current_blog_id()` referring to a FOSSE-flagged Simple blog. Read each handler + its `WPCom_Activitypub::get_instance()` callsites; confirm the sync events only originate from Jetpack-mirror blogs (Atomic / self-hosted), not Simple blogs. If they CAN fire on Simple/FOSSE-flagged contexts, scope a guard sub-issue (gate each handler on `! wpcom_fosse_is_active( $blog_id )`).
    4. Pin runbook ownership: identify FOSSE deploy approvers and the wp.com systems-team coordination path. Capture names / roles for the runbook in Task 6.
- **Verify**:
    - Sticker name is confirmed (or a new name is picked).
    - Artifact destination is confirmed (or alternate path picked).
    - Jetpack-sync handler safety has a written conclusion: "safe as-is" or "needs guard, scoped as follow-up".
    - Approver list is captured.
- **Depends on**: none.

### Task 2: Build & vendor initial FOSSE artifact onto wp.com

- **Status**: Not started
- **Files**:
    - FOSSE repo: `build/fosse.zip` (generated, gitignored)
    - wp.com repo: vendored tree at the path picked in Task 1 (e.g. `WP_PLUGIN_DIR/fosse/0.0.1/`)
- **Do**:
    1. From FOSSE trunk: `FOSSE_VERSION=0.0.1 composer build-zip` (per `bin/build-zip.sh` and `AGENTS.md` "Build plugin zip"). Confirm `build/fosse.zip` contains `fosse.php`, `src/`, `bundled/`, and a production `vendor/`.
    2. Unzip into the chosen wp.com path. Per Candidate A, `WP_PLUGIN_DIR/fosse/0.0.1/`. Verify the `fosse.php` entry point is at `WP_PLUGIN_DIR/fosse/0.0.1/fosse.php`.
    3. Commit to the wp.com source tree per wpcom commit conventions. Note in the commit message that the artifact is inert until the loader from Task 3 is in place.
- **Verify**:
    - FOSSE plugin tree is present at the chosen wp.com path.
    - No production blogs are affected — `fosse-loader.php` does not exist yet, so nothing includes the artifact.
    - `bundled/activitypub/` and `bundled/atmosphere/` are present inside the vendored tree (verify the build-zip output didn't accidentally drop them — `bin/build-zip.sh` should preserve them).
- **Depends on**: Task 1.

### Task 3: Implement `wp-content/mu-plugins/fosse-loader.php`

- **Status**: Not started
- **Files**: wp.com repo: `wp-content/mu-plugins/fosse-loader.php` (new)
- **Do**:
    1. Top-level `define()`s: `FOSSE_BLOG_STICKER` (e.g. `'enable-fosse'`), `FOSSE_DISABLED_BLOG_STICKER` (e.g. `'disable-fosse'`), and optionally `FOSSE_PLUGIN_DIR_BASE` pointing to the vendored tree's parent (matching wpcom-activitypub-load's `ACTIVITYPUB_MUPLUGIN_DIR` pattern at `wpcom-activitypub-load.php:15`).
    2. Implement `wpcom_fosse_get_blog_id()` mirroring `wpcom_activitypub_get_blog_id()` from `wpcom-activitypub-load.php:109-121` (REST-aware, falls back to `get_current_blog_id()`). If the AP version is generic enough, share it.
    3. Implement `wpcom_fosse_is_active( $blog_id = null )`:
        - Default `$blog_id` to `wpcom_fosse_get_blog_id()`.
        - Check `SIMPLE_SITE === get_site_type( $blog_id )` (mirroring `wpcom_activitypub_is_active()` at `wpcom-activitypub-load.php:133-135`); FOSSE rollout is Simple-only in v1, so non-Simple sites return false.
        - If `disable-fosse` sticker is present, return false (per-blog kill).
        - Return `has_blog_sticker( FOSSE_BLOG_STICKER, $blog_id )`.
    4. Implement `wpcom_fosse_is_loaded()` — return `defined( 'FOSSE_VERSION' )` (or whatever sentinel constant FOSSE itself defines; if FOSSE doesn't have one yet, file a follow-up to add it; for now `class_exists( '\\Automattic\\Fosse\\Bundled\\Bootstrap', false )` works).
    5. Implement `wpcom_fosse_maybe_load()` callback for `plugins_loaded` priority 8:
        - Bail immediately if `defined( 'FOSSE_DISABLED' ) && FOSSE_DISABLED` (global kill).
        - Bail if `wpcom_fosse_is_loaded()` (defense in depth).
        - Resolve current blog id via `wpcom_fosse_get_blog_id()`.
        - Bail if `! wpcom_fosse_is_active( $blog_id )`.
        - Resolve the FOSSE entry point: `WP_PLUGIN_DIR/fosse/<version>/fosse.php`. Version selection: production constant for now (e.g. `'0.0.1'`); leave a comment that an `enable-fosse-edge` sticker → edge version selection can be added later mirroring `activitypub-edge` (`wpcom-activitypub-load.php:60-63`).
        - `require_once` the entry point.
    6. `add_action( 'plugins_loaded', 'wpcom_fosse_maybe_load', 8 )`. Comment the priority choice (one earlier than `wpcom-activitypub-load.php`'s priority 9, so bundled AP boots and defines `ACTIVITYPUB_PLUGIN_DIR` before the wpcom AP loader runs).
    7. Add a header comment block describing the load contract: how the load-order trick works, why constants must NOT be defined manually here (would defeat `fosse.php:42-46`'s skip-when-standalone check), and which Phase 0 assumptions this depends on.
- **Verify**:
    - `php -l wp-content/mu-plugins/fosse-loader.php` clean.
    - On a sandbox blog with `enable-fosse` set: the include fires and `wpcom_fosse_is_loaded()` returns true after `plugins_loaded` priority 8.
    - On a sandbox blog without the sticker: callback returns early; FOSSE is not loaded.
    - Defining `FOSSE_DISABLED = true` short-circuits the loader globally.
    - Setting `disable-fosse` on a flagged blog short-circuits per-blog.
    - Note: full coexistence verification (bundled-AP suppression of platform AP, federation smoke test) lives in Task 5 — this task only verifies the loader's own behavior.
- **Depends on**: Tasks 1, 2.

### Task 4: Add wp.com load-contract comment to `fosse.php`

- **Status**: Not started
- **Files**: FOSSE repo: `fosse.php`
- **Do**:
    1. Add a brief comment block above the bundled-backends bootstrap (around `fosse.php:23-58`) explaining: on wp.com Simple, FOSSE is included by `wp-content/mu-plugins/fosse-loader.php` at `plugins_loaded` priority 8. The existing skip-when-standalone checks at `fosse.php:42-46` MUST stay intact: if the wp.com shim were to define `ACTIVITYPUB_PLUGIN_VERSION` or `ACTIVITYPUB_PLUGIN_DIR` itself, FOSSE would correctly skip its own bundle and the rollout would silently no-op. Bundled AP defines those constants during its own boot — that's the lever.
    2. Keep the comment small (<15 lines). Reference the wp.com SDD only by name ("wpcom-simple-rollout SDD"), not by path, so the reference doesn't rot if Linear becomes the source of truth.
    3. Open a PR on `Automattic/fosse` per the project's standard PR conventions (CLAUDE.md / AGENTS.md). This is the ONLY change that goes to the public repo from this rollout.
- **Verify**:
    - `composer run-script lint-php` clean.
    - PR is reviewed and merged.
    - `git log --oneline fosse.php` shows the comment-only commit.
- **Depends on**: none — can ship in parallel with anything else.

### Task 5: Sandbox coexistence smoke tests

- **Status**: Not started
- **Files**: none (sandbox testing + written report)
- **Do**:
    1. Stand up sandbox wp.com Simple blog A with `enable-fosse` sticker:
        - Verify on request that `wpcom_fosse_is_loaded()` becomes true at `plugins_loaded` priority 8.
        - Verify `defined( 'ACTIVITYPUB_PLUGIN_DIR' )` is true after FOSSE loads.
        - Verify `wpcom_load_the_activitypub_plugin()` early-bails at `wpcom-activitypub-load.php:52-54`. Easiest signal: `WPCom_Activitypub` class is NOT loaded (since its include happens at line 81 only after the `wpcom_activitypub_is_loaded()` check).
        - Verify constant inheritance: `ACTIVITYPUB_REST_NAMESPACE === 'wpcom/activitypub-1.0'`, `ACTIVITYPUB_SINGLE_USER_MODE === true`, `ACTIVITYPUB_DISABLE_SIDELOADING === true`, `ACTIVITYPUB_DISABLE_REMOTE_CACHE === true`. Bundled AP should run with these wp.com defaults.
        - Verify bundled Atmosphere loads cleanly: `defined( 'ATMOSPHERE_VERSION' )` is true. No platform Atmosphere to conflict with.
        - End-to-end federation: publish a post from blog A, observe it federate to a Mastodon test account followed by the blog actor. Capture screenshots / federation logs.
    2. Stand up sibling sandbox blog B with NO `enable-fosse`:
        - Verify `wpcom_fosse_is_loaded()` returns false.
        - Verify platform AP loads as today: `WPCom_Activitypub` class is loaded, AP REST routes respond at `/wp-json/wpcom/activitypub-1.0/...`.
        - Verify no fatals / warnings in error logs.
    3. Stand up sibling sandbox blog C with NO `enable-activitypub` AND NO `enable-fosse`:
        - Verify the AP opt-in admin page (`Settings > ActivityPub`) renders as today.
        - Verify FOSSE is not loaded.
    4. Capture results in a smoke-test report (paste into Linear sub-issue / runbook).
- **Verify**:
    - Blog A: bundled AP loads, platform AP self-skips, federation works end-to-end.
    - Blog B: platform AP unaffected.
    - Blog C: opt-in flow unaffected.
    - No fatals / warnings on any of the three.
- **Depends on**: Tasks 2, 3.

### Task 6: Write deploy + rollback runbook

- **Status**: Not started
- **Files**: `sdd/wpcom-simple-rollout/runbook.md` (new, local only — mirrored into the Linear epic body)
- **Do**:
    1. **Deploy section.** Step-by-step: produce artifact (`FOSSE_VERSION=<v> composer build-zip`), unzip into `WP_PLUGIN_DIR/fosse/<v>/` on the wp.com source tree, commit per wpcom conventions, get approval, ship.
    2. **Sticker ops section.** How to set `enable-fosse` on a blog (`add_blog_sticker( 'enable-fosse', '<reason>' )`), how to remove it (`remove_blog_sticker(...)`), how to apply `disable-fosse` for per-blog kill, how to set the global `FOSSE_DISABLED` constant via wp.com config.
    3. **Rollback section.** Three escalating tiers:
        - Tier 1 — per-blog: remove `enable-fosse` sticker (or set `disable-fosse`). Blog reverts to platform AP on next request.
        - Tier 2 — global: set `FOSSE_DISABLED = true` in wp.com config. All FOSSE-flagged blogs revert immediately.
        - Tier 3 — full re-vendor: roll the wp.com source-tree path back to the previous artifact version. Update the version selection constant in `fosse-loader.php` if pinned there.
    4. **Approvers section.** Names / roles from Task 1.
    5. **First-deploy checklist.** A pre-flight checklist for the very first wp.com deploy: confirm sticker name unchanged, confirm artifact path, confirm internal-cohort allowlist staged but stickers not yet applied, confirm rollback steps practiced on sandbox.
- **Verify**:
    - `runbook.md` exists at `sdd/wpcom-simple-rollout/runbook.md`.
    - Each rollback tier has a copy-pasteable command sequence.
    - Approver list is filled in (no "TBD").
- **Depends on**: Tasks 1, 3.

### Task 7: Verify rollback paths on sandbox

- **Status**: Not started
- **Files**: none (sandbox testing + report)
- **Do**:
    1. **Tier 1a — sticker removal.** On flagged sandbox blog A (from Task 5): `remove_blog_sticker( 'enable-fosse' )`. Reload. Verify `wpcom_fosse_is_loaded()` is false, platform AP loads normally (`WPCom_Activitypub` is loaded), no broken state from the previous FOSSE run.
    2. **Tier 1b — disable sticker override.** Re-flag blog A with `enable-fosse`. Add `disable-fosse`. Reload. Verify `wpcom_fosse_is_active()` returns false, FOSSE is not loaded, platform AP loads.
    3. **Tier 2 — global kill.** Define `FOSSE_DISABLED = true` in sandbox config. On a freshly-flagged blog with `enable-fosse` (no `disable-fosse`): verify `wpcom_fosse_maybe_load()` short-circuits, FOSSE is not loaded.
    4. **Tier 3 — re-vendor.** Vendor a "previous" version (e.g. duplicate the current tree to `WP_PLUGIN_DIR/fosse/0.0.0/`). Update the version constant in `fosse-loader.php` to `0.0.0`. Reload a flagged blog. Verify the previous version loads cleanly.
    5. Document any rough edges discovered (e.g. cached opcode of bundled AP after rollback, follower state after sticker flip, etc.). Surface as follow-up Linear issues if material.
- **Verify**:
    - Each tier produces the expected revert behavior on the sandbox.
    - No data corruption between flagged → unflagged → re-flagged transitions (option keys / schema match by construction; spot-check a few).
- **Depends on**: Tasks 5, 6.

### Task 8: Roll out to beta cohort

- **Status**: Not started
- **Files**: cohort list captured in Linear epic body
- **Do**:
    1. Curate initial allowlist: internal blogs first (FOSSE team / a8c testers), external testers second (a small handful of fediverse-savvy users you trust).
    2. Apply `enable-fosse` sticker to the internal cohort. Soak for at least 48-72 hours. Watch for: federation working, no platform-AP regressions on neighboring non-flagged blogs, no error-log spikes.
    3. If internal soak is clean, expand to the external cohort. Coordinate with each external tester (let them know they're flagged so they can spot oddities).
    4. Document the cohort + soak window outcomes in the Linear epic body.
    5. Establish a rhythm: each manual deploy from Task 2 onward gets at least a one-cycle internal soak before re-extending to external. Capture this in the runbook (Task 6) as part of the deploy checklist.
- **Verify**:
    - Internal cohort live on FOSSE for ≥48 hours with no rollback.
    - External cohort live with no rollback for ≥1 week before considering "stable v1".
    - No regressions on platform AP for non-flagged blogs (spot-checked via cohort-adjacent test blog).
- **Depends on**: Tasks 5, 7.

## Linear conversion (post-plan-approval, one-time)

Once this plan is approved (✅ Looks good — write plan.md as proposed):

1. Create parent Linear issue: "FOSSE — wp.com Simple rollout" (epic). Body = condensed spec.md.
2. Create 8 sub-issues, one per task above. Each sub-issue body = the task's `Do` + `Verify` sections.
3. Mirror the runbook (Task 6 output) into the epic body once written.
4. Mark this SDD as "tracked in Linear" (no further plan.md updates; Status fields here become a frozen snapshot).
