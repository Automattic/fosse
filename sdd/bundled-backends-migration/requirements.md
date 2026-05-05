# Bundled Backends Migration — Requirements

Tracked under: [DOTCOM-16826](https://linear.app/a8c/issue/DOTCOM-16826) "Plan migration off bundled-backends bootstrap".

## Status: DEFERRED

This SDD is **not active for implementation**. See `spec.md` for the deferral rationale, trigger conditions, and the design captured for the eventual migration.

## Goal

Capture the design for moving FOSSE off the current bundled-backends bootstrap so that, when migration becomes worth doing, there's a starting point — the analysis, the chosen direction, the principles, the proofs needed before removing `bundled/`. While deferred, the doc also serves as a list of constraints that should shape today's architectural decisions ("don't grow the bundled-coupling surface area").

## What this deferred design must capture

1. **The four candidate distribution models**: Composer VCS dependencies / package registry, splitting FOSSE UI from backend plugins, wp.com-specific artifact strategy, continued vendoring with stricter policy. Each evaluated for pros, cons, and fit at the time of activation.

2. **A chosen direction** for the migration team to start from: two-phase (bridge then migration), with package-based artifact assembly as the bridge proof.

3. **Migration principles** that respect the upstream-first policy and the read-only treatment of `bundled/`.

4. **The proofs required before removing `bundled/`**: packaging, runtime, dependency UX, wp.com Simple platform path.

5. **Trigger conditions** that, when met, make the migration worth activating.

6. **Constraints on today's architecture** so deferring this work doesn't create more migration debt over time.

## Constraints (apply now, even though implementation is deferred)

- WordPress plugin installs do not automatically install arbitrary Composer dependencies at runtime.
- The current standalone zip is a drop-in plugin bundle and cannot assume a site runs Composer.
- FOSSE's root `composer.json` excludes `bundled/` from classmap autoload; bundled plugins own their own bootstrap/autoload paths.
- `bin/build-zip.sh` validates `composer.lock` drift and installs production `vendor/`; any future Composer-based backend strategy must fit that build model or replace it deliberately.
- wp.com Simple rollout currently relies on artifact vendoring and load ordering documented in `fosse.php`.
- Backend plugins may continue to need separate release cadence, review, and ownership from FOSSE UI work.
- `bundled/` is treated as read-only. `tools/sync-bundled.sh` is the only legitimate writer.
- New FOSSE code MUST NOT add load-bearing assumptions about `bundled/` beyond the existing surface area (`fosse.php` bootstrap + the documented class/constant references). See the spec's "What to be aware of in the meantime" section.

## Out of Scope (now)

- Removing `bundled/`.
- Implementing Composer/package-registry publishing.
- Building the dependency UX for missing/inactive external backends.
- Changing the current backend loader, activation bootstrap, or wp.com Simple load contract.
- Changing ActivityPub or Atmosphere upstream code (other than the existing upstream-first policy that already governs all such decisions).
- Designing a full updater for third-party plugin dependencies.

## Success Criteria (for the deferred design)

- The repository has a documented migration strategy that captures the analysis without requiring it to ship now.
- The trigger conditions are explicit enough that the next person looking at this doc can answer "should we start now?" by checking against them.
- The "what to be aware of in the meantime" section gives current contributors clear constraints to respect.
- When implementation does activate, `plan.md` can be expanded from a trigger checklist into real tasks without re-doing the analysis.
