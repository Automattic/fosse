# Bundled Backends Migration - Requirements

Tracked under: [DOTCOM-16826](https://linear.app/a8c/issue/DOTCOM-16826) "Plan migration off bundled-backends bootstrap".

## Status: PLANNING

This SDD is **open for review, not active for implementation**. The original deferred design captured useful caution, but two assumptions have materially changed: ATmosphere is now published on WordPress.org, and FOSSE's WordPress >=6.9 floor means WordPress' native dependency header is available across the whole supported range.

This refresh remains docs-only until reviewers approve the migration approach.

## 2026-05 Refresh

- ATmosphere is now available from WordPress.org at <https://wordpress.org/plugins/atmosphere/>.
- ActivityPub is available from WordPress.org at <https://wordpress.org/plugins/activitypub/>.
- WordPress supports a `Requires Plugins` header, documented as a comma-separated list of WordPress.org plugin slugs.
- FOSSE's public plugin direction should therefore prefer `Requires Plugins: activitypub, atmosphere` over keeping checked-in backend source indefinitely.
- The wp.com Simple artifact strategy remains a separate coordination item. It should not, by itself, decide the public repository source layout.

## Goal

Plan a safe migration from checked-in bundled backend source to explicit upstream plugin dependencies, while preserving the original migration caution for existing bundled-only installs and wp.com Simple rollout constraints.

The updated SDD should give reviewers a clear public distribution proposal, preserve the package-artifact bridge analysis as a fallback or platform-specific option, and identify the decisions needed before implementation begins.

## What this planning design must capture

1. **The preferred public distribution model**: FOSSE declares native WordPress plugin dependencies with `Requires Plugins: activitypub, atmosphere`.

2. **Runtime readiness requirements**: FOSSE still checks for the required backend versions, symbols, and APIs because WordPress dependency headers do not encode minimum versions or symbol-level capability.

3. **Upgrade strategy for bundled-only installs**: removing `bundled/` changes behavior for sites that currently rely on FOSSE's nested copies of ActivityPub and ATmosphere.

4. **wp.com Simple separation**: public WordPress.org distribution and wp.com Simple artifact ownership are related but separate decisions.

5. **Alternative bridge options**: package-based artifact assembly remains available if reviewers decide wp.com Simple, release engineering, or one-zip distribution needs a transitional bundle.

6. **Constraints on today's architecture** so planning this work does not create new migration debt before implementation starts.

## Constraints (apply now, even while implementation is pending review)

- WordPress plugin installs do not automatically enforce minimum versions for dependencies declared in `Requires Plugins`.
- The current standalone zip is a drop-in plugin bundle; existing bundled-only installs need an explicit upgrade story before `bundled/` is removed.
- FOSSE's root `composer.json` excludes `bundled/` from classmap autoload; bundled plugins own their own bootstrap/autoload paths until migration happens.
- `bin/build-zip.sh` validates `composer.lock` drift and installs production `vendor/`; any transitional package-based backend strategy must fit that build model or replace it deliberately.
- wp.com Simple rollout currently relies on artifact vendoring and load ordering documented in `fosse.php`.
- Backend plugins may continue to need separate release cadence, review, and ownership from FOSSE UI work.
- `bundled/` is treated as read-only. `tools/sync-bundled.sh` is the only legitimate writer until reviewers approve removal.
- New FOSSE code MUST NOT add load-bearing assumptions about `bundled/` beyond the existing surface area (`fosse.php` bootstrap + the documented class/constant references). See the spec's "What to be aware of during planning" section.

## Out of Scope (until reviewers approve implementation)

- Removing `bundled/`.
- Adding the `Requires Plugins` header to `fosse.php`.
- Changing the current backend loader, activation bootstrap, or wp.com Simple load contract.
- Building the dependency UX for missing, inactive, or too-old external backends.
- Changing ActivityPub or ATmosphere upstream code beyond the existing upstream-first policy that already governs all such decisions.
- Designing a full updater for third-party plugin dependencies.

## Success Criteria (for the updated SDD)

- The SDD acknowledges the WordPress.org ATmosphere release.
- The SDD cites `Requires Plugins: activitypub, atmosphere` as the preferred public plugin dependency mechanism.
- The SDD treats WordPress native dependency support as available for FOSSE's supported WordPress range.
- The SDD separates public WordPress.org distribution from wp.com Simple artifact ownership.
- The SDD keeps a clear upgrade discussion for existing bundled-only installs.
