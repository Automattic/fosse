# Bundled Backends Migration - Requirements

Tracked under: [DOTCOM-16826](https://linear.app/a8c/issue/DOTCOM-16826) "Plan migration off bundled-backends bootstrap".

## Status: PLANNING

This SDD is **open for review, not active for implementation**. The original deferred design captured useful caution, but the underlying dependency landscape has changed: Atmosphere is now published on WordPress.org alongside ActivityPub, so FOSSE can finally talk about a future where it doesn't carry checked-in backend source.

This refresh remains docs-only until reviewers approve the migration approach.

## Direction change (2026-05-22)

Earlier drafts of this SDD positioned WordPress' `Requires Plugins` header (`Requires Plugins: activitypub, atmosphere`) as the preferred public distribution model. That direction is wrong, and we're pivoting away from it.

`Requires Plugins` is a hard activation gate: if ActivityPub or Atmosphere aren't installed and active when a user activates FOSSE, WordPress refuses to activate FOSSE at all. That's a bad first-run experience — the user can't even see what FOSSE does or get guidance toward the missing dependencies. The two backend plugins also aren't interchangeable; a site that only cares about ActivityPub federation shouldn't be blocked because Atmosphere isn't installed.

The new direction is **install-if-missing**: users install and activate FOSSE first, and FOSSE then detects which backends are missing or inactive at runtime and guides the user through installing or activating them via admin notices with one-click "Install ActivityPub" / "Install Atmosphere" CTAs. FOSSE remains functional with whichever subset of backends is present and surfaces clear, recoverable UX for the rest.

For audit trail: DOTCOM-17184 "Add Requires Plugins header to fosse.php" was cancelled. DOTCOM-17181 "Add backend dependency UX" expands in scope to own the install-if-missing guided flow, not just status display.

## 2026-05 Refresh

- Atmosphere is now available from WordPress.org at <https://wordpress.org/plugins/atmosphere/>.
- ActivityPub is available from WordPress.org at <https://wordpress.org/plugins/activitypub/>.
- Both plugins can therefore be installed from inside wp-admin via the standard Plugin Install API, which makes a guided install-if-missing flow practical.
- FOSSE's public plugin direction should be **install-if-missing**: FOSSE activates standalone, detects backend presence/version/API readiness at runtime, and guides the user through installing or activating each missing backend.
- The wp.com Simple artifact strategy remains a separate coordination item. It should not, by itself, decide the public repository source layout.

## Goal

Plan a safe migration from checked-in bundled backend source to a guided install-if-missing experience that resolves ActivityPub and Atmosphere as standalone WordPress.org plugins, while preserving the original migration caution for existing bundled-only installs and wp.com Simple rollout constraints.

The updated SDD should give reviewers a clear public distribution proposal, preserve the package-artifact bridge analysis as a fallback or platform-specific option, and identify the decisions needed before implementation begins.

## What this planning design must capture

1. **The preferred public distribution model**: FOSSE ships as a standalone plugin that activates without hard dependencies, then detects and guides the user through installing/activating missing standalone ActivityPub and Atmosphere plugins.

2. **Runtime readiness requirements**: FOSSE checks for backend presence, minimum versions, and required symbols/APIs at runtime, and reports per-backend status without fataling when a backend is absent or incompatible.

3. **Upgrade strategy for bundled-only installs**: removing `bundled/` changes behavior for sites that currently rely on FOSSE's nested copies of ActivityPub and Atmosphere.

4. **wp.com Simple separation**: public WordPress.org distribution and wp.com Simple artifact ownership are related but separate decisions.

5. **Alternative bridge options**: package-based artifact assembly remains available if reviewers decide wp.com Simple, release engineering, or one-zip distribution needs a transitional bundle.

6. **Constraints on today's architecture** so planning this work does not create new migration debt before implementation starts.

## Constraints (apply now, even while implementation is pending review)

- FOSSE must remain activatable even when ActivityPub or Atmosphere is missing or inactive — no hard activation gate. Backend absence is a runtime UX problem, not a refuse-to-activate condition.
- The guided install-if-missing flow must use WordPress core's Plugin Install API and respect the current user's `install_plugins` / `activate_plugins` capabilities; FOSSE should degrade to a manual-install message when capabilities are missing.
- The current standalone zip is a drop-in plugin bundle; existing bundled-only installs need an explicit upgrade story before `bundled/` is removed.
- FOSSE's root `composer.json` excludes `bundled/` from classmap autoload; bundled plugins own their own bootstrap/autoload paths until migration happens.
- `bin/build-zip.sh` validates `composer.lock` drift and installs production `vendor/`; any transitional package-based backend strategy must fit that build model or replace it deliberately.
- wp.com Simple rollout currently relies on artifact vendoring and load ordering documented in `fosse.php`.
- Backend plugins may continue to need separate release cadence, review, and ownership from FOSSE UI work.
- `bundled/` is treated as read-only. `tools/sync-bundled.sh` is the only legitimate writer until reviewers approve removal.
- New FOSSE code MUST NOT add load-bearing assumptions about `bundled/` beyond the existing surface area (`fosse.php` bootstrap + the documented class/constant references). See the spec's "What to be aware of during planning" section.

## Out of Scope (until reviewers approve implementation)

- Removing `bundled/`.
- Adding a `Requires Plugins` header to `fosse.php` (cancelled in DOTCOM-17184; install-if-missing replaces it).
- Changing the current backend loader, activation bootstrap, or wp.com Simple load contract.
- Building the install-if-missing guided UX (tracked under DOTCOM-17181; this SDD only commits to the direction, not the implementation).
- Changing ActivityPub or Atmosphere upstream code beyond the existing upstream-first policy that already governs all such decisions.
- Designing a full updater for third-party plugin dependencies.

## Success Criteria (for the updated SDD)

- The SDD acknowledges the WordPress.org Atmosphere release.
- The SDD cites install-if-missing (FOSSE activates standalone, then guides the user through installing/activating standalone ActivityPub and Atmosphere) as the preferred public distribution model.
- The SDD explains why `Requires Plugins` was rejected as a hard activation gate.
- The SDD separates public WordPress.org distribution from wp.com Simple artifact ownership.
- The SDD keeps a clear upgrade discussion for existing bundled-only installs.
