# Bundled Backends Migration - Implementation Plan

Based on: [sdd/bundled-backends-migration/spec.md](./spec.md)

## Status: IN PROGRESS

The SDD doc itself merged in PR 93 and the 2026-05-22 direction change is in PR 176. **No implementation tasks have started yet** — the plan tracks the review work needed now that Atmosphere is available on WordPress.org and FOSSE can resolve backends as standalone WordPress.org plugins.

See [requirements.md](./requirements.md#direction-change-2026-05-22) for the 2026-05-22 direction change: `Requires Plugins` was rejected in favor of an install-if-missing guided UX. DOTCOM-17184 was cancelled; DOTCOM-17181 expands in scope to own the install flow.

## Progress

- [x] Refresh SDD assumptions.
- [x] Pivot from `Requires Plugins` to install-if-missing.
- [ ] Approve install-if-missing as the public distribution model.
- [ ] Choose the bundled-only install upgrade path.
- [ ] Define backend compatibility gates.
- [ ] Define wp.com Simple artifact ownership.
- [ ] Choose dependency fixtures for CI and e2e.

## Review tasks

1. **Refresh SDD assumptions.**
   - **Status**: ✅ Done (PR 93, PR 176)
   - **Verify**: The requirements, spec, and plan acknowledge <https://wordpress.org/plugins/atmosphere/> (landed in PR 93) and describe install-if-missing as the public distribution direction (landed in PR 176).

2. **Approve install-if-missing as the public distribution model.**
   - **Status**: Not started
   - **Decision needed**: Confirm that FOSSE should ship without a `Requires Plugins` header and own the install/activate/update UX for missing standalone backends, or whether one-zip distribution remains a hard public requirement.
   - **Verify**: Review comments explicitly accept or reject the install-if-missing direction. (Audit trail: DOTCOM-17184 cancelled; DOTCOM-17181 expands to own the install UX.)

3. **Choose the bundled-only install upgrade path.**
   - **Status**: Not started
   - **Decision needed**: Choose one migration PR versus a transitional release that runs the install-if-missing UX against `bundled/` copies before removing checked-in backend source.
   - **Verify**: The implementation plan names the release sequence and the user-visible behavior for sites that rely on the current bundled copies.

4. **Define backend compatibility gates.**
   - **Status**: Not started
   - **Decision needed**: Pick the ActivityPub minimum version and the Atmosphere minimum version or symbol set FOSSE requires — the install-if-missing flow needs to know what counts as "compatible" versus "needs update."
   - **Verify**: Runtime checks and tests can assert the chosen versions/symbols without relying on `bundled/` internals.

5. **Define wp.com Simple artifact ownership.**
   - **Status**: Not started
   - **Decision needed**: Choose platform-installed dependencies, a platform-assembled bundle, or temporary continuation of vendoring for wp.com Simple.
   - **Verify**: The chosen owner, artifact shape, load-order contract, and rollback behavior are documented before code changes start.

6. **Choose dependency fixtures for CI and e2e.**
   - **Status**: Not started
   - **Decision needed**: Decide whether CI fetches dependencies from WordPress.org zips, SVN tags, GitHub release artifacts, or local source overrides.
   - **Verify**: The future implementation tasks can install ActivityPub and Atmosphere standalone in PHPUnit/e2e without reading from `bundled/`.

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

1. **Add runtime dependency readiness checks.** FOSSE detects missing, inactive, too-old, or API-incompatible ActivityPub and Atmosphere installs and reports provider availability without fatals.
2. **Build install-if-missing admin UX (DOTCOM-17181).** Missing ActivityPub shows a one-click "Install ActivityPub" CTA; missing Atmosphere shows a one-click "Install Atmosphere" CTA; installed-but-inactive backends get "Activate"; incompatible versions get "Update"; users without `install_plugins` / `activate_plugins` see a manual-install message; notices distinguish absent vs. inactive vs. incompatible; FOSSE setup/status pages remain useful when one backend is unavailable.
3. **Add standalone dependency fixtures.** PHPUnit and e2e install ActivityPub and Atmosphere as explicit standalone plugins from the approved source, and tests stop reading from `bundled/`.
4. **Ship the approved upgrade sequence.** Either ship a transitional release that surfaces the install-if-missing prompt against `bundled/` copies first, or implement the one-step migration path reviewers approve.
5. **Verify FOSSE activates cleanly with no backends present.** Confirm the public zip's `fosse.php` has no hard activation gate and that activation on a fresh site with no ActivityPub/Atmosphere installed lands on the install-if-missing prompt rather than a fatal or a refuse-to-activate state. (Replaces the cancelled DOTCOM-17184 task of adding a `Requires Plugins` header.)
6. **Define and implement wp.com Simple artifact path.** Coordinate with the platform owner on platform-installed dependencies, platform assembly, or temporary vendoring; document the load contract and rollback.
7. **Remove checked-in backend source in a follow-up.** Separate PR after replacement behavior ships and has run for the approved release window; remove `bundled/`, `tools/sync-bundled.sh`, and bundle-specific export/linguist/tooling rules.
8. **Keep package-based assembly only if selected.** If reviewers require a one-zip bridge, add a CI job that resolves AP and Atmosphere from versioned package inputs and assembles an installable FOSSE artifact without relying on checked-in `bundled/`.

## What to do right now

Review the updated SDD. Do not remove `bundled/`, add dependency headers, or change loaders until reviewers approve install-if-missing as the public distribution model, the upgrade sequence, backend compatibility gates, and wp.com Simple artifact owner.
