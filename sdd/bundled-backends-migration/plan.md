# Bundled Backends Migration - Implementation Plan

Based on: [sdd/bundled-backends-migration/spec.md](./spec.md)

## Status: PLANNING

**No implementation tasks are scheduled in this PR.** The plan tracks the review work needed now that ATmosphere is available on WordPress.org and FOSSE can use WordPress-native plugin dependencies across its supported WordPress range.

## Progress

- [x] Refresh SDD assumptions.
- [ ] Approve the public distribution strategy.
- [ ] Choose the bundled-only install upgrade path.
- [ ] Define backend compatibility gates.
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
   - **Status**: Not started
   - **Decision needed**: Pick the ActivityPub minimum version and the ATmosphere minimum version or symbol set FOSSE requires.
   - **Verify**: Runtime checks and tests can assert the chosen versions/symbols without relying on `bundled/` internals.

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

## Sketch of the implementation plan, after review approval

When reviewers approve the migration direction, expand each of the following into a real task with files, dependencies, and verification steps. Until then, this is a sketch.

1. **Add runtime dependency readiness checks.** FOSSE detects missing, inactive, too-old, or API-incompatible ActivityPub and ATmosphere installs and reports provider availability without fatals.
2. **Add dependency UX.** Missing ActivityPub shows clear action; missing ATmosphere shows clear action; installed-but-inactive and incompatible versions are distinguished from absent plugins; setup/status pages remain useful when one backend is unavailable.
3. **Add standalone dependency fixtures.** PHPUnit and e2e install ActivityPub and ATmosphere as explicit standalone plugins from the approved source, and tests stop reading from `bundled/`.
4. **Ship the approved upgrade sequence.** Either ship a warning release first or implement the one-step migration path reviewers approve.
5. **Add the public dependency header.** Update `fosse.php` with `Requires Plugins: activitypub, atmosphere` only after dependency UX and upgrade sequencing are ready.
6. **Define and implement wp.com Simple artifact path.** Coordinate with the platform owner on plugin dependencies, platform assembly, or temporary vendoring; document the load contract and rollback.
7. **Remove checked-in backend source in a follow-up.** Separate PR after replacement behavior ships and has run for the approved release window; remove `bundled/`, `tools/sync-bundled.sh`, and bundle-specific export/linguist/tooling rules.
8. **Keep package-based assembly only if selected.** If reviewers require a one-zip bridge, add a CI job that resolves AP and ATmosphere from versioned package inputs and assembles an installable FOSSE artifact without relying on checked-in `bundled/`.

## What to do right now

Review the updated SDD. Do not remove `bundled/`, add dependency headers, or change loaders until reviewers approve the public dependency strategy, upgrade sequence, backend compatibility gates, and wp.com Simple artifact owner.
