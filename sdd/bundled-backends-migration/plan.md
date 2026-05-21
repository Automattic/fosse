# Bundled Backends Migration - Implementation Plan

Based on: [sdd/bundled-backends-migration/spec.md](./spec.md)

## Status: IN PROGRESS

Implementation step 1 (runtime readiness checks) is in flight under [DOTCOM-17180](https://linear.app/a8c/issue/DOTCOM-17180). The remaining review tasks still gate the steps that change the public load model (header cutover, bundled removal, wp.com Simple ownership).

## Progress

- [x] Refresh SDD assumptions.
- [ ] Approve the public distribution strategy.
- [ ] Choose the bundled-only install upgrade path.
- [x] Define backend compatibility gates. (Anchored in [DOTCOM-17180](https://linear.app/a8c/issue/DOTCOM-17180); see `audits/2026-05-20-backend-dependency-delta.md` for the delta against released versions.)
- [ ] Define wp.com Simple artifact ownership.
- [ ] Choose dependency fixtures for CI and e2e.

## Review tasks

1. **Refresh SDD assumptions.**
   - **Status**: ✅ Done (#93)
   - **Verify**: The requirements, spec, and plan acknowledge <https://wordpress.org/plugins/atmosphere/> and cite `Requires Plugins: activitypub, atmosphere` as the preferred public dependency direction.

2. **Approve the public distribution strategy.**
   - **Status**: Not started
   - **Decision needed**: Confirm whether the public FOSSE plugin should declare `Requires Plugins: activitypub, atmosphere`, or whether one-zip distribution remains a hard public requirement.
   - **Verify**: Review comments explicitly accept or reject the native dependency-header direction.

3. **Choose the bundled-only install upgrade path.**
   - **Status**: Not started
   - **Decision needed**: Choose one migration PR versus a transitional warning release before removing checked-in backend source.
   - **Verify**: The implementation plan names the release sequence and the user-visible behavior for sites that rely on the current bundled copies.

4. **Define backend compatibility gates.**
   - **Status**: ✅ Done (anchored in [DOTCOM-17180](https://linear.app/a8c/issue/DOTCOM-17180); see `audits/2026-05-20-backend-dependency-delta.md`)
   - **Decision recorded**: FOSSE tracks two minimum-version anchors in `Backend_Readiness::MIN_ACTIVITYPUB_VERSION` and `MIN_ATMOSPHERE_VERSION`. They point at the first upstream releases that contain the surface FOSSE relies on. Atmosphere `1.1.0` is a real released version containing the `atmosphere_post_embed` filter ([PR 72](https://github.com/Automattic/wordpress-atmosphere/pull/72)). ActivityPub `8.4.0` is a forward-pointer placeholder until upstream cuts a release containing the `toot:blurhash` JSON-LD context term ([PR 3327](https://github.com/Automattic/wordpress-activitypub/pull/3327)) — the released `8.3.0` predates it.
   - **Verify**: `tests/php/Backend_ReadinessTest.php` covers the version comparison + source-detection logic without relying on `bundled/` internals.

5. **Define wp.com Simple artifact ownership.**
   - **Status**: Not started
   - **Decision needed**: Choose plugin dependencies, a platform-assembled bundle, or temporary continuation of vendoring for wp.com Simple.
   - **Verify**: The chosen owner, artifact shape, load-order contract, and rollback behavior are documented before code changes start.

6. **Choose dependency fixtures for CI and e2e.**
   - **Status**: Not started
   - **Decision needed**: Decide whether CI fetches dependencies from WordPress.org zips, SVN tags, GitHub release artifacts, or local source overrides.
   - **Verify**: The future implementation tasks can install ActivityPub and ATmosphere standalone in PHPUnit/e2e without reading from `bundled/`.

## Standing constraints (apply continuously while planning)

These are not implementation tasks; they're rules contributors should respect today so planning this work doesn't grow the eventual migration delta. Verified by reviewers, not by this plan's task list.

- [ ] Don't add new `require_once bundled/...` calls outside `fosse.php`'s existing bootstrap.
- [ ] Don't add filters/hooks whose contracts depend on bundled code being at a specific filesystem path.
- [ ] Don't add tests that reach into `bundled/` directly (use the public API of the backend plugins, or stub them).
- [ ] Treat `bundled/` as read-only; `tools/sync-bundled.sh` is the only writer until removal is approved.
- [ ] Land protocol-agnostic functionality in the upstream repos first (existing upstream-first policy).
- [ ] Document the load contract at every coupling point - match the pattern set by the wp.com Simple load-order contract comment near the top of `fosse.php`.

## Implementation sub-issues

Each step is tracked as a sub-issue under the umbrella [DOTCOM-16826](https://linear.app/a8c/issue/DOTCOM-16826).

1. **Add runtime dependency readiness checks.** FOSSE detects missing, inactive, too-old, or API-incompatible ActivityPub and ATmosphere installs and reports provider availability without fatals. — [DOTCOM-17180](https://linear.app/a8c/issue/DOTCOM-17180) (in flight).
2. **Add dependency UX.** Missing ActivityPub shows clear action; missing ATmosphere shows clear action; installed-but-inactive and incompatible versions are distinguished from absent plugins; setup/status pages remain useful when one backend is unavailable. — [DOTCOM-17181](https://linear.app/a8c/issue/DOTCOM-17181).
3. **Add standalone dependency fixtures.** PHPUnit and e2e install ActivityPub and ATmosphere as explicit standalone plugins from the approved source, and tests stop reading from `bundled/`. — [DOTCOM-17182](https://linear.app/a8c/issue/DOTCOM-17182).
4. **Ship the approved upgrade sequence.** Either ship a warning release first or implement the one-step migration path reviewers approve. — [DOTCOM-17183](https://linear.app/a8c/issue/DOTCOM-17183).
5. **Add the public dependency header.** Update `fosse.php` with `Requires Plugins: activitypub, atmosphere` only after dependency UX and upgrade sequencing are ready, and only once upstream cuts releases that contain the surface FOSSE relies on. — [DOTCOM-17184](https://linear.app/a8c/issue/DOTCOM-17184).
6. **Define and implement wp.com Simple artifact path.** Coordinate with the platform owner on plugin dependencies, platform assembly, or temporary vendoring; document the load contract and rollback. — [DOTCOM-17185](https://linear.app/a8c/issue/DOTCOM-17185).
7. **Remove checked-in backend source in a follow-up.** Separate PR after replacement behavior ships and has run for the approved release window; remove `bundled/`, `tools/sync-bundled.sh`, and bundle-specific export/linguist/tooling rules. — [DOTCOM-17186](https://linear.app/a8c/issue/DOTCOM-17186).
8. **Keep package-based assembly only if selected.** If reviewers require a one-zip bridge, add a CI job that resolves AP and ATmosphere from versioned package inputs and assembles an installable FOSSE artifact without relying on checked-in `bundled/`. — [DOTCOM-17187](https://linear.app/a8c/issue/DOTCOM-17187) (conditional).

## What to do right now

Review the updated SDD. Land the readiness layer ([DOTCOM-17180](https://linear.app/a8c/issue/DOTCOM-17180)) — it touches no loader behavior and ships safely. Do not remove `bundled/`, add the dependency header, or change loaders until reviewers approve the public dependency strategy, upgrade sequence, and wp.com Simple artifact owner, and until ActivityPub cuts the release the AP readiness anchor points at. (Atmosphere 1.1.0 already covers what FOSSE needs.)
