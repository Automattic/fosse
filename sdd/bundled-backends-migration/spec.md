---
status: planning
---

# Spec: Bundled Backends Migration

## Status: PLANNING

This SDD is open for review after the 2026-05 dependency refresh. **No code implementation is planned in this PR.** The bundled-backends approach (`bundled/activitypub/` and `bundled/atmosphere/` checked into the FOSSE repo, included in the standalone zip and the wp.com Simple artifact) remains the production architecture until reviewers approve a migration path.

The original design captured why removing `bundled/` was too risky before ATmosphere had a public release and before FOSSE could rely on native WordPress plugin dependencies. That caution still matters, but the public dependency facts have changed enough that this SDD should be reviewed instead of left on the shelf.

## 2026-05 Refresh

Two original trigger conditions have fired or materially changed:

1. **ATmosphere is now published on WordPress.org.** It has a WordPress.org plugin page at <https://wordpress.org/plugins/atmosphere/> and the slug `atmosphere`.

2. **FOSSE can rely on native WordPress plugin dependencies.** WordPress 6.5 introduced plugin dependencies, and FOSSE requires WordPress >=6.9. The Plugin Handbook documents the `Requires Plugins` header as a comma-separated list of WordPress.org slugs.

ActivityPub is also available from WordPress.org at <https://wordpress.org/plugins/activitypub/>, so the preferred public dependency declaration is:

```text
Requires Plugins: activitypub, atmosphere
```

This header should be the default public WordPress.org distribution strategy unless reviewers explicitly decide one-zip distribution remains a hard requirement.

## Current Recommendation

The migration direction should pivot from "prove package-based artifact assembly first" to "prefer native WordPress plugin dependencies for public distribution."

1. **Public FOSSE distribution declares plugin dependencies.** FOSSE should eventually add `Requires Plugins: activitypub, atmosphere` and stop treating checked-in backend source as the public plugin source of truth.

2. **FOSSE keeps defensive runtime checks.** WordPress dependency headers identify required slugs; they do not express minimum versions, required classes, constants, filters, or behavior. FOSSE still needs runtime readiness checks for ActivityPub and ATmosphere before enabling provider-specific features.

3. **Tests run against standalone dependency fixtures.** PHPUnit, Jest where relevant, and e2e should exercise explicit standalone ActivityPub and ATmosphere installs rather than reaching into `bundled/` internals.

4. **wp.com Simple artifact ownership is a separate decision.** wp.com Simple can use native dependencies, a platform-assembled bundle, or a temporary continuation of vendoring. That decision should not force the public repository to carry checked-in backend source forever.

5. **Package-based assembly remains an alternative bridge.** The original bridge analysis is still valuable if reviewers decide a generated one-zip artifact is required for wp.com Simple, release engineering, or a transitional warning release.

## Remaining Cautions

The changed dependency facts make migration more plausible; they do not make it automatic.

1. **Existing bundled-only installs need an upgrade path.** Sites currently relying on FOSSE's nested copies of ActivityPub and ATmosphere could lose backend functionality if `bundled/` disappears before the standalone plugins are installed and active.

2. **One-step cutover versus warning release needs review.** Reviewers should decide whether to ship a transitional release that warns about upcoming required plugins before removing checked-in backend source.

3. **Minimum compatible versions need to be chosen.** ActivityPub and ATmosphere may publish stable zips, but FOSSE must still define the minimum version or symbol set required for current integration points.

4. **Dependency UX still matters.** WordPress core can show dependency relationships, but FOSSE's setup/status surfaces should remain clear when a dependency is absent, inactive, too old, or missing a required API.

5. **wp.com Simple rollout needs an owner.** The current platform deployment workflow is built around a single vendored artifact at `wp-content/plugins/fosse/<version>/`. Any divergence from the public plugin zip needs explicit platform ownership, test coverage, and rollback behavior.

## What to be aware of during planning

These are constraints today's architectural decisions should respect until implementation starts:

1. **Don't add NEW load-bearing assumptions about `bundled/`.** Existing FOSSE code reads bundled AP/ATmosphere class names and constants (e.g. `ACTIVITYPUB_PLUGIN_DIR`, `\Activitypub\Activitypub`, `\Atmosphere\Publisher`). That's existing surface area. Don't grow it. Specifically:
   - Don't add `require_once bundled/...` calls outside `fosse.php`'s existing bootstrap.
   - Don't add filters/hooks whose contracts depend on bundled code being at a specific filesystem path.
   - Don't add tests that reach into `bundled/` directly.

2. **Treat `bundled/` as read-only.** Already enforced by policy. `tools/sync-bundled.sh` is the only legitimate writer until removal is approved.

3. **Land protocol-agnostic functionality upstream first.** This is the existing upstream-first policy; migration just makes it more important. Anything that's a candidate for "should be in AP / ATmosphere" should land there before the bundled copy gets it via sync.

4. **Document the load contract at every coupling point.** The wp.com Simple load-order contract comment near the top of `fosse.php` is a model. When FOSSE adds a new touchpoint with bundled code, document the contract in-line so the migration team can find the contracts to redesign.

5. **Keep `bin/build-zip.sh` validation strict.** The bundled `vendor/autoload.php` existence check in `bin/build-zip.sh` protects the bundled artifact from silent breakage. Add similar guards for any new bundled file that load-bearing code depends on.

## Proposed direction (pending review)

The proposed public distribution path is Option A in the analysis below: WordPress-native dependency declarations.

```text
Requires Plugins: activitypub, atmosphere
```

The implementation path should be:

1. **Review and decision phase**: confirm the public dependency strategy, the minimum backend compatibility requirements, the existing-install upgrade path, and the wp.com Simple artifact owner.

2. **Transitional readiness phase**: add runtime checks and admin/status UX for missing, inactive, or incompatible standalone backends while `bundled/` is still present.

3. **Migration phase**: add the dependency header, update tests and e2e fixtures to use standalone plugins, and remove checked-in backend source only after the upgrade path is proven.

The original package-artifact bridge should remain available as a wp.com-specific or release-engineering alternative, not the default public plugin strategy.

## Options analysis

The candidate distribution models, updated after the 2026-05 refresh. The original package-based reasoning is preserved here so reviewers can still choose it deliberately if needed.

### Option A: Native WordPress plugin dependencies

Recommended as the public distribution target.

**Pros**: uses WordPress core's dependency mechanism; makes ActivityPub and ATmosphere visible as standalone plugins; avoids hidden copies; aligns with upstream-first; keeps public source ownership clean; works across FOSSE's WordPress >=6.9 support range.
**Cons**: dependency headers do not encode minimum versions or symbol requirements; existing bundled-only installs need an upgrade path; one-click install behavior depends on WordPress dependency UX; wp.com Simple still needs a platform answer.

### Option B: Composer VCS dependencies or package registry

Useful as a packaging proof or wp.com bridge, not the preferred public plugin strategy.

**Pros**: reproducible version pinning via lockfile; removes manual sync as source of truth; clean audit trail; CI-friendly; supports private/pre-release ATmosphere builds.
**Cons**: WP plugin users don't run Composer; resulting zip may still embed backend source at build time; VCS deps are slower than registry; does not use the public WordPress.org dependency relationship users already understand.

### Option C: Split FOSSE UI from backend plugins

Recommended long-term architecture. Native plugin dependencies are the practical public path to this split.

**Pros**: clean ownership boundary; backend plugins update independently; no hidden copies; aligns with upstream-first; explicit dependency state in wp-admin; removes class-collision and activation-hook gaps from programmatic nested loading.
**Cons**: standalone install no longer behaves as a single self-contained zip; dependency UX still needs design; wp.com Simple needs a platform deployment answer.

### Option D: wp.com-specific artifact strategy

Recommended as a separate coordination item. wp.com Simple can keep artifact vendoring longer than the public repo if needed, but it should become platform packaging, not checked-in FOSSE source.

**Pros**: matches wp.com's platform control over plugin deployment; avoids forcing wp.com constraints into open-source repo; preserves one-step rollout while public source moves; allows platform-specific pinning, staged rollout, rollback.
**Cons**: two distribution paths to test separately; wp.com artifact behavior could diverge from public zip; requires clear ownership of artifact assembly outside FOSSE source tree.

### Option E: Continued checked-in vendoring

Accept only as the current production state while the reviewed migration path is not approved.

**Pros**: lowest immediate risk; preserves current standalone zip + wp.com Simple behavior; easy to understand; current guardrails (no hand edits, sync-only refresh) work.
**Cons**: makes FOSSE carry other plugins' source; creates large vendored diffs; hides dependency ownership; keeps accumulating migration debt now that public dependency channels exist.

## Target architecture (after migration)

```
Development source:

Automattic/fosse
  |-- fosse.php                  # Declares Requires Plugins once migration ships
  |-- src/                       # FOSSE UI/orchestration
  |-- composer.json              # FOSSE runtime deps
  `-- dependency checks/tests    # Version/API readiness, not backend source

Automattic/wordpress-activitypub
  `-- independent WordPress.org plugin release

Automattic/wordpress-atmosphere
  `-- independent WordPress.org plugin release
```

Distribution artifacts at the migration target:

```
Public WordPress.org plugin:
  fosse/
    fosse.php                    # Requires Plugins: activitypub, atmosphere
    src/
    vendor/
  plus FOSSE runtime checks and setup/status UX for dependency readiness

wp.com Simple artifact:
  one of:
    - platform-installed plugin dependencies
    - platform-controlled bundle of FOSSE + backend plugins
    - temporary continuation of vendoring with an explicit removal date
```

## Migration principles

- **Source ownership stays upstream.** If a change is useful outside FOSSE, land it in ActivityPub or ATmosphere first.
- **FOSSE owns projection and product policy.** FOSSE-specific option defaults, unified UI, and cross-network coordination stay in FOSSE.
- **Public source should not carry backend source indefinitely.** Build or platform artifacts may temporarily contain dependencies, but checked-in backend source should have an approved removal path.
- **Dependency state must be visible.** Users and operators should be able to tell whether ActivityPub and ATmosphere are installed, active, compatible, or missing.
- **Rollout should be reversible.** Do not delete `bundled/` until dependency readiness, upgrade sequencing, and wp.com Simple behavior are all verified.

## Required proofs before removing `bundled/`

1. **Dependency declaration proof**: add `Requires Plugins: activitypub, atmosphere`; verify WordPress recognizes both slugs and surfaces the dependency relationship in supported versions.
2. **Runtime proof**: install FOSSE with standalone ActivityPub and ATmosphere; FOSSE admin loads without fatals; provider availability is reported correctly; missing, inactive, and incompatible backends degrade cleanly.
3. **Upgrade proof**: existing bundled-only installs receive either a transitional warning release or a one-step migration path that avoids silent loss of federation behavior.
4. **Test fixture proof**: PHPUnit and e2e fixtures run against explicit standalone dependency installs and no tests reach into `bundled/` directly.
5. **wp.com Simple proof**: platform artifact or deployment path installs FOSSE plus required backend versions; load ordering remains correct; rollback returns to the previous known-good artifact.
6. **Bridge proof, if chosen**: resolve AP and ATmosphere from versioned inputs; assemble an installable artifact without relying on checked-in `bundled/`; verify the artifact includes whatever backend files the selected transition path requires.

## Non-goals (for this docs PR)

- This SDD does not remove `bundled/`.
- This SDD does not add the dependency header to `fosse.php`.
- This SDD does not pick exact minimum backend versions.
- This SDD does not redesign FOSSE onboarding or provider UI.
- This SDD does not change upstream release policy for ActivityPub or ATmosphere.

## Review questions

- Do we ship one migration PR or a warning release first?
- What ActivityPub minimum version should FOSSE require?
- What ATmosphere minimum version or symbol set should FOSSE require?
- Should wp.com Simple use plugin dependencies, a platform-assembled bundle, or a temporary continuation of vendoring?
- Should CI fetch dependencies from WordPress.org zips, SVN tags, GitHub release artifacts, or local source overrides?
- Does the public FOSSE zip need to remain one-click with backend artifacts included, or can it rely on WordPress dependency installation UX?
