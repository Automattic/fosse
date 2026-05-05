# Bundled Backends Migration - Requirements

Tracked under: [DOTCOM-16826](https://linear.app/a8c/issue/DOTCOM-16826) "Plan migration off bundled-backends bootstrap".

## Goal

Define the strategy for moving FOSSE off the current bundled-backends bootstrap without breaking the standalone plugin zip or wp.com Simple rollout that currently depend on artifact vendoring.

The outcome should make the current `bundled/` approach explicitly temporary, identify the next packaging proof needed to replace it, and choose a long-term distribution model for the federation backends.

## Current State

FOSSE currently vendors release-build source for `wordpress-activitypub` and `wordpress-atmosphere` under `bundled/`:

- `bundled/activitypub/`
- `bundled/atmosphere/`

The vendored copies are refreshed from local upstream checkouts by `tools/sync-bundled.sh`, which:

- Reads `FOSSE_AP_SOURCE` and `FOSSE_ATMO_SOURCE` environment variables.
- Runs `composer update --no-dev --optimize-autoloader` inside the Atmosphere source before syncing.
- Uses `rsync --delete` and `tools/bundled-excludes.txt`.
- Treats the vendored copies as release-build artifacts, not editable source.

The distribution zip is built by `bin/build-zip.sh` from `git archive HEAD` plus a production Composer install. Anything not marked `export-ignore` in `.gitattributes` ships. `bundled/` is not export-ignored, so the standalone zip currently includes:

- `fosse.php`
- `src/`
- `bundled/`
- production `vendor/`

This was created by the earlier `sdd/bundled-backends/` work and landed as the short-term bootstrap for PR #11. Both that SDD and `AGENTS.md` say bundling is temporary and expected to be replaced by a cleaner distribution approach.

## Requirements

1. **Keep current rollout unblocked while planning the migration.**
   The plan must not require an immediate removal of `bundled/`, because the standalone zip and wp.com Simple rollout currently rely on artifact vendoring.

2. **Recommend both a near-term and long-term direction.**
   The SDD must evaluate the known options and make a recommendation, not just list tradeoffs.

3. **Evaluate these options explicitly:**
   - Composer VCS dependencies or package registry.
   - Splitting FOSSE UI/orchestration from backend plugin distribution.
   - wp.com-specific artifact strategy.
   - Continued vendoring with stricter policy.

4. **Respect the upstream-first policy.**
   General ActivityPub or Atmosphere fixes belong upstream. FOSSE should not accumulate backend-specific shims or patches in `bundled/` except as unavoidable release-artifact consumption.

5. **Do not hand-edit vendored backend source.**
   Any plan that keeps a vendored artifact for any period must keep `bundled/` read-only and refreshed through automation, not manual edits.

6. **Avoid making bundled backends permanent load-bearing infrastructure by accident.**
   The migration plan must add decision gates, ownership rules, and removal criteria so the temporary bootstrap does not become the default architecture.

7. **Preserve install ergonomics.**
   A user installing the standalone FOSSE zip should still get a working Social Web experience. If the long-term model requires separate backend plugins, FOSSE must provide clear dependency detection, activation guidance, and failure states.

8. **Account for wp.com Simple separately from general plugin distribution.**
   wp.com Simple can use platform-specific artifact assembly or deployment controls that should not necessarily define the open-source repository shape.

9. **Keep packaging reproducible.**
   Replacing checked-in `bundled/` must include a proof that the same backend versions can be resolved, built, assembled into an installable artifact, and verified in CI.

10. **Keep migration reversible until the replacement is proven.**
    The first implementation phase should produce a packaging proof and policy hardening before deleting the existing bundle.

## Constraints

- WordPress plugin installs do not automatically install arbitrary Composer dependencies at runtime.
- The current standalone zip is a drop-in plugin bundle and cannot assume a site runs Composer.
- FOSSE's root `composer.json` currently excludes `bundled/` from classmap autoload; bundled plugins own their own bootstrap/autoload paths.
- `bin/build-zip.sh` validates `composer.lock` drift and installs production `vendor/`; any Composer-based backend strategy must fit that build model or replace it deliberately.
- wp.com Simple rollout currently relies on artifact vendoring and load ordering documented in `fosse.php`.
- Backend plugins may continue to need separate release cadence, review, and ownership from FOSSE UI work.

## Out of Scope

- Removing `bundled/` in this SDD.
- Changing the current backend loader, activation bootstrap, or wp.com Simple load contract.
- Implementing Composer/package-registry publishing.
- Changing ActivityPub or Atmosphere upstream code.
- Replacing FOSSE admin UI.
- Designing a full updater for third-party plugin dependencies.

## Success Criteria

- The repository has a clear migration strategy with near-term and long-term recommendations.
- The plan defines concrete follow-up tasks for decision record, packaging proof, build/CI changes, migration checks, and deprecation/removal.
- The plan preserves the existing rollout path until a replacement artifact strategy is proven.
- The plan makes `bundled/` visibly temporary and governed by stricter policy while it remains.
