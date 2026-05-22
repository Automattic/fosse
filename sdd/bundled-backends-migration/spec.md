---
status: in-progress
---

# Spec: Bundled Backends Migration

## Status: IN PROGRESS

The SDD doc itself merged in PR 93 and the 2026-05-22 direction change is in PR 176. **No code implementation has started yet.** The bundled-backends approach (`bundled/activitypub/` and `bundled/atmosphere/` checked into the FOSSE repo, included in the standalone zip and the wp.com Simple artifact) remains the production architecture until reviewers approve the install-if-missing migration path described below.

The original design captured why removing `bundled/` was too risky before Atmosphere had a public release. That caution still matters, but the public dependency facts have changed enough that this SDD should be reviewed instead of left on the shelf.

## Direction change (2026-05-22)

This spec previously recommended declaring `Requires Plugins: activitypub, atmosphere` in `fosse.php` as the preferred public distribution model. We've pivoted away from that. See [requirements.md](./requirements.md#direction-change-2026-05-22) for the full rationale; in short, `Requires Plugins` is a hard activation gate and creates a bad first-run experience when either backend is missing. The cancelled work item is DOTCOM-17184; the install-if-missing guided UX is tracked under DOTCOM-17181 (expanded in scope to own the install flow, not just status display). The sections below have been rewritten around install-if-missing.

## 2026-05 Refresh

The original trigger condition has fired:

1. **Atmosphere is now published on WordPress.org.** It has a WordPress.org plugin page at <https://wordpress.org/plugins/atmosphere/> and the slug `atmosphere`. ActivityPub is at <https://wordpress.org/plugins/activitypub/> and the slug `activitypub`.

Because both backends are installable from inside wp-admin via WordPress' Plugin Install API, FOSSE can drive a guided install-if-missing flow from its own admin UI rather than carrying checked-in backend source forever.

## Current Recommendation

The migration direction should pivot from "prove package-based artifact assembly first" to "ship FOSSE standalone, install backends if missing."

1. **Public FOSSE distribution stays standalone-activatable.** FOSSE does not declare any hard plugin dependency in its plugin header. It activates cleanly on a fresh site that has neither ActivityPub nor Atmosphere installed and remains usable for any subset of backends that are present.

2. **FOSSE detects backend state at runtime.** On every admin load, FOSSE inspects which of ActivityPub / Atmosphere are installed, active, and at a compatible version/API surface. Provider availability is reported per-backend.

3. **FOSSE guides users through installing missing backends.** Admin notices on FOSSE's setup and status pages surface clear, one-click "Install ActivityPub" / "Install Atmosphere" CTAs that drive WordPress core's Plugin Install API. Already-installed-but-inactive backends get an "Activate" CTA. Incompatible versions get an "Update" CTA pointing at the standard plugin update flow. Sites whose current user lacks `install_plugins` / `activate_plugins` see a manual-install message instead of a broken button.

4. **Tests run against standalone dependency fixtures.** PHPUnit, Jest where relevant, and e2e should exercise explicit standalone ActivityPub and Atmosphere installs rather than reaching into `bundled/` internals.

5. **wp.com Simple artifact ownership is a separate decision.** wp.com Simple can use platform-installed dependencies, a platform-assembled bundle, or a temporary continuation of vendoring. That decision should not force the public repository to carry checked-in backend source forever.

6. **Package-based assembly remains an alternative bridge.** The original bridge analysis is still valuable if reviewers decide a generated one-zip artifact is required for wp.com Simple, release engineering, or a transitional warning release.

## Remaining Cautions

The changed dependency facts make migration more plausible; they do not make it automatic.

1. **Existing bundled-only installs need an upgrade path.** Sites currently relying on FOSSE's nested copies of ActivityPub and Atmosphere could lose backend functionality if `bundled/` disappears before the standalone plugins are installed and active.

2. **One-step cutover versus warning release needs review.** Reviewers should decide whether to ship a transitional release that surfaces the install-if-missing prompt against `bundled/` copies before removing checked-in backend source.

3. **Minimum compatible versions need to be chosen.** ActivityPub and Atmosphere may publish stable zips, but FOSSE must still define the minimum version or symbol set required for current integration points, since the install-if-missing flow needs to know what counts as "compatible."

4. **Install-if-missing UX needs careful design.** Admin notices need to be dismissible-but-recoverable, scoped to capable users, distinguish missing / inactive / incompatible states, and avoid harassing site owners who deliberately disabled a backend. DOTCOM-17181 owns this work; this SDD just commits to the direction.

5. **wp.com Simple rollout needs an owner.** The current platform deployment workflow is built around a single vendored artifact at `wp-content/plugins/fosse/<version>/`. Any divergence from the public plugin zip needs explicit platform ownership, test coverage, and rollback behavior.

## What to be aware of during planning

These are constraints today's architectural decisions should respect until implementation starts:

1. **Don't add NEW load-bearing assumptions about `bundled/`.** Existing FOSSE code reads bundled AP/Atmosphere class names and constants (e.g. `ACTIVITYPUB_PLUGIN_DIR`, `\Activitypub\Activitypub`, `\Atmosphere\Publisher`). That's existing surface area. Don't grow it. Specifically:
   - Don't add `require_once bundled/...` calls outside `fosse.php`'s existing bootstrap.
   - Don't add filters/hooks whose contracts depend on bundled code being at a specific filesystem path.
   - Don't add tests that reach into `bundled/` directly.

2. **Treat `bundled/` as read-only.** Already enforced by policy. `tools/sync-bundled.sh` is the only legitimate writer until removal is approved.

3. **Land protocol-agnostic functionality upstream first.** This is the existing upstream-first policy; migration just makes it more important. Anything that's a candidate for "should be in AP / Atmosphere" should land there before the bundled copy gets it via sync.

4. **Document the load contract at every coupling point.** The wp.com Simple load-order contract comment near the top of `fosse.php` is a model. When FOSSE adds a new touchpoint with bundled code, document the contract in-line so the migration team can find the contracts to redesign.

5. **Keep `bin/build-zip.sh` validation strict.** The bundled `vendor/autoload.php` existence check in `bin/build-zip.sh` protects the bundled artifact from silent breakage. Add similar guards for any new bundled file that load-bearing code depends on.

## Proposed direction (pending review)

The proposed public distribution path is Option A in the analysis below: install-if-missing with no hard dependency header.

The implementation path should be:

1. **Review and decision phase**: confirm the install-if-missing strategy, the minimum backend compatibility requirements, the existing-install upgrade path, and the wp.com Simple artifact owner.

2. **Transitional readiness phase**: add runtime checks and install-if-missing admin UX for missing, inactive, or incompatible standalone backends while `bundled/` is still present. The UX can ship and be exercised against bundled copies before `bundled/` is removed.

3. **Migration phase**: update tests and e2e fixtures to use standalone plugins, and remove checked-in backend source only after the install-if-missing UX has run for the approved release window.

The original package-artifact bridge should remain available as a wp.com-specific or release-engineering alternative, not the default public plugin strategy.

## Options analysis

The candidate distribution models, updated after the 2026-05 refresh and 2026-05-22 direction change. The original package-based and `Requires Plugins`-based reasoning is preserved here so reviewers can still revisit it deliberately if needed.

### Option A: Install-if-missing (no hard dependency header)

Recommended as the public distribution target.

FOSSE activates standalone, detects backend state at runtime, and surfaces guided install/activate/update CTAs for missing or incompatible standalone ActivityPub and Atmosphere installs.

**Pros**: FOSSE activates cleanly on any site (no refuse-to-activate gate); the user sees what FOSSE does before being asked to install backends; per-backend independence (ActivityPub-only or Atmosphere-only sites work); incompatible versions can be detected and explained, not just "missing"; aligns with upstream-first; keeps public source ownership clean; works across FOSSE's WordPress >=6.9 support range.
**Cons**: more UX surface than a header line — FOSSE has to own the install/activate/update prompts and their states; admin notices need careful scoping and dismissal behavior; wp.com Simple still needs a platform answer.

### Option B: WordPress `Requires Plugins` header (rejected 2026-05-22)

Previously the recommendation; rejected because it's a hard activation gate. If a user activates FOSSE without ActivityPub or Atmosphere installed, WordPress refuses to activate FOSSE at all — there's no recovery surface inside FOSSE to guide them. Listed here for audit trail; not the target.

**Pros**: uses WordPress core's dependency mechanism; zero FOSSE-side install UX to build.
**Cons**: hard activation gate produces a poor first-run experience; both backends become mandatory even when a site only wants one; no version/API-level enforcement; can't distinguish missing / inactive / incompatible; user can't even see what FOSSE does before being blocked.

### Option C: Composer VCS dependencies or package registry

Useful as a packaging proof or wp.com bridge, not the preferred public plugin strategy.

**Pros**: reproducible version pinning via lockfile; removes manual sync as source of truth; clean audit trail; CI-friendly; supports private/pre-release Atmosphere builds.
**Cons**: WP plugin users don't run Composer; resulting zip may still embed backend source at build time; VCS deps are slower than registry; does not use the public WordPress.org plugin channels users already understand.

### Option D: Split FOSSE UI from backend plugins

Recommended long-term architecture. Install-if-missing is the practical public path to this split.

**Pros**: clean ownership boundary; backend plugins update independently; no hidden copies; aligns with upstream-first; explicit dependency state in wp-admin; removes class-collision and activation-hook gaps from programmatic nested loading.
**Cons**: standalone install no longer behaves as a single self-contained zip; install/activate UX still needs design; wp.com Simple needs a platform deployment answer.

### Option E: wp.com-specific artifact strategy

Recommended as a separate coordination item. wp.com Simple can keep artifact vendoring longer than the public repo if needed, but it should become platform packaging, not checked-in FOSSE source.

**Pros**: matches wp.com's platform control over plugin deployment; avoids forcing wp.com constraints into open-source repo; preserves one-step rollout while public source moves; allows platform-specific pinning, staged rollout, rollback.
**Cons**: two distribution paths to test separately; wp.com artifact behavior could diverge from public zip; requires clear ownership of artifact assembly outside FOSSE source tree.

### Option F: Continued checked-in vendoring

Accept only as the current production state while the reviewed migration path is not approved.

**Pros**: lowest immediate risk; preserves current standalone zip + wp.com Simple behavior; easy to understand; current guardrails (no hand edits, sync-only refresh) work.
**Cons**: makes FOSSE carry other plugins' source; creates large vendored diffs; hides dependency ownership; keeps accumulating migration debt now that public dependency channels exist.

## Target architecture (after migration)

```
Development source:

Automattic/fosse
  |-- fosse.php                  # No Requires Plugins header; activates standalone
  |-- src/                       # FOSSE UI/orchestration + install-if-missing flow
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
    fosse.php                    # No Requires Plugins header
    src/
    vendor/
  plus FOSSE runtime checks and install-if-missing admin UX for ActivityPub / Atmosphere

wp.com Simple artifact:
  one of:
    - platform-installed plugin dependencies
    - platform-controlled bundle of FOSSE + backend plugins
    - temporary continuation of vendoring with an explicit removal date
```

## Migration principles

- **Source ownership stays upstream.** If a change is useful outside FOSSE, land it in ActivityPub or Atmosphere first.
- **FOSSE owns projection and product policy.** FOSSE-specific option defaults, unified UI, and cross-network coordination stay in FOSSE.
- **Public source should not carry backend source indefinitely.** Build or platform artifacts may temporarily contain dependencies, but checked-in backend source should have an approved removal path.
- **Dependency state must be visible and actionable.** Users and operators should be able to tell whether ActivityPub and Atmosphere are installed, active, compatible, or missing — and act on that state from within FOSSE.
- **Rollout should be reversible.** Do not delete `bundled/` until install-if-missing UX, upgrade sequencing, and wp.com Simple behavior are all verified.

## Required proofs before removing `bundled/`

1. **Install-if-missing UX proof**: missing, inactive, and incompatible backends are detected and surfaced with working install/activate/update CTAs; admin notices respect user capabilities and dismissal state.
2. **Runtime proof**: install FOSSE with standalone ActivityPub and Atmosphere; FOSSE admin loads without fatals; provider availability is reported correctly; FOSSE remains functional with any subset of backends.
3. **Upgrade proof**: existing bundled-only installs receive either a transitional release that surfaces the install-if-missing prompt against `bundled/` copies, or a one-step migration path that avoids silent loss of federation behavior.
4. **Test fixture proof**: PHPUnit and e2e fixtures run against explicit standalone dependency installs and no tests reach into `bundled/` directly.
5. **wp.com Simple proof**: platform artifact or deployment path installs FOSSE plus required backend versions; load ordering remains correct; rollback returns to the previous known-good artifact.
6. **Bridge proof, if chosen**: resolve AP and Atmosphere from versioned inputs; assemble an installable artifact without relying on checked-in `bundled/`; verify the artifact includes whatever backend files the selected transition path requires.

## Non-goals (for this docs PR)

- This SDD does not remove `bundled/`.
- This SDD does not implement the install-if-missing admin UX (tracked under DOTCOM-17181).
- This SDD does not pick exact minimum backend versions.
- This SDD does not redesign FOSSE onboarding or provider UI beyond the dependency-missing surface area.
- This SDD does not change upstream release policy for ActivityPub or Atmosphere.

## Review questions

- Do we ship one migration PR or a transitional release that runs the install-if-missing UX against `bundled/` copies first?
- What ActivityPub minimum version should FOSSE require?
- What Atmosphere minimum version or symbol set should FOSSE require?
- Should wp.com Simple use platform-installed dependencies, a platform-assembled bundle, or a temporary continuation of vendoring?
- Should CI fetch dependencies from WordPress.org zips, SVN tags, GitHub release artifacts, or local source overrides?
- Where exactly does the install-if-missing notice render — global admin notice, FOSSE setup page, FOSSE status page, or all three? (DOTCOM-17181 design question; this SDD just confirms the direction.)
