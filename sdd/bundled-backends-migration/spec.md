# Spec: Bundled Backends Migration

## Status: DEFERRED

This SDD captures a future migration. **No implementation is planned at this time.** The bundled-backends approach (`bundled/activitypub/` and `bundled/atmosphere/` checked into the FOSSE repo, included in the standalone zip and the wp.com Simple artifact) is the production architecture for now and the foreseeable future. The "short-term bootstrap" framing from `sdd/bundled-backends/` is correct in spirit but, in practice, the bootstrap is now a load-bearing wall.

This doc exists so the eventual migration has a starting design rather than a fresh blank page, and so today's architectural decisions are made with the future migration in mind.

## Why deferred

Concrete blockers — each of these would need to clear before migration becomes worth the cost:

1. **Atmosphere has no stable public release.** `ATMOSPHERE_VERSION` is `'unreleased'` in the bundled copy. Composer-based deps, package registries, and external-plugin distribution all assume a stable release artifact to depend on. There isn't one yet.

2. **wp.com Simple just shipped on bundled.** DOTCOM-16983 (artifact vendoring) and DOTCOM-16984 (mu-plugin loader) landed within the past week. The platform deployment workflow is built around a single vendored artifact at `wp-content/plugins/fosse/<version>/`. Migrating now would force a redesign of the wp.com Simple deployment story before its first round of production-incident-driven feedback has even arrived.

3. **WordPress.org plugin directory does not allow Composer-managed dependencies.** Plugins must include their vendored code in the zip. So "FOSSE depends on AP/Atmosphere via Composer" only works for self-hosted-via-GitHub or platform-built artifacts — it doesn't help the WP.org distribution case at all.

4. **Bundling is working in production.** Coexistence with standalone AP/Atmosphere is handled cleanly by the skip-when-standalone checks at `fosse.php:42-58`. There are no open bug reports or operational incidents traceable to the bundling architecture. The maintenance burden (`tools/sync-bundled.sh`) is real but small — a few syncs a month.

5. **Migration is high-risk, low-immediate-value.** The benefits (smaller plugin zip, cleaner upstream sync) don't outweigh the costs (wp.com platform redesign, dependency UX work, WP.org policy navigation, regression risk on a production-critical path) until at least one of items 1-4 changes.

## Trigger conditions for revisiting

Revisit this SDD and consider activating implementation when ANY of the following becomes true:

- **Atmosphere ships a stable public release** (versioned tag, published on a reachable distribution channel — wordpress.org, an Automattic Composer registry, or a stable GitHub release artifact).
- **The bundling approach actively breaks.** Specifically: a `tools/sync-bundled.sh` run produces unrecoverable conflicts, a security advisory in upstream AP or Atmosphere requires faster-than-sync turnaround, or the bundled zip exceeds a size limit imposed by a distribution channel.
- **WordPress.org's plugin dependency tooling matures** to allow declarative cross-plugin dependencies that work for the standalone install case (currently in development as part of WP core; not yet a usable surface).
- **wp.com platform asks for separated artifacts** as part of its own deployment evolution.
- **Three or more sync conflicts in a single quarter** that require manual resolution beyond the standard `sync-bundled.sh` flow.

When a trigger fires, the next step is: re-evaluate this spec, confirm the chosen direction below still holds, expand `plan.md` with implementation tasks, and only then begin work.

## What to be aware of in the meantime

These are constraints today's architectural decisions should respect even though we're not migrating:

1. **Don't add NEW load-bearing assumptions about `bundled/`.** Existing FOSSE code reads bundled AP/Atmosphere class names and constants (e.g. `ACTIVITYPUB_PLUGIN_DIR`, `\Activitypub\Activitypub`, `\Atmosphere\Publisher`). That's existing surface area. Don't grow it. Specifically:
   - Don't add `require_once bundled/...` calls outside `fosse.php`'s existing bootstrap.
   - Don't add filters/hooks whose contracts depend on bundled code being at a specific filesystem path.
   - Don't add tests that reach into `bundled/` directly.

2. **Treat `bundled/` as read-only.** Already enforced by policy. `tools/sync-bundled.sh` is the only legitimate writer.

3. **Land protocol-agnostic functionality upstream first.** This is the existing "upstream-first" policy; migration just makes it more important. Anything that's a candidate for "should be in AP / Atmosphere" should land there before the bundled copy gets it via sync. Reduces the migration delta when the time comes.

4. **Document the load contract at every coupling point.** The wp.com Simple load-order contract (`fosse.php:25-38`) is a model. When FOSSE adds a new touchpoint with bundled code, document the contract in-line so the migration team (probably future-us) can find the contracts to redesign.

5. **Keep `bin/build-zip.sh` validation strict.** The existing "fail if `bundled/vendor/autoload.php` missing" check (`bin/build-zip.sh:73-83`) is exactly the kind of guard that protects the bundled artifact from silent breakage. Add similar guards for any new bundled file that load-bearing code depends on.

## Chosen direction (when implementation begins)

Two-phase migration:

1. **Bridge phase**: keep checked-in `bundled/` artifacts, but harden policy and add a packaging proof. Build a CI job that assembles an equivalent FOSSE zip from versioned package inputs (Composer VCS, GitHub release zips, or Automattic package registry — whichever is most stable at the time) without relying on `bundled/` source. Compare the assembled artifact byte-for-byte (or structurally) with the current bundled output.

2. **Migration phase**: once the packaging proof + dependency UX + wp.com Simple platform path are all proven, move backend source out of the FOSSE repo. FOSSE becomes UI/orchestration; ActivityPub and Atmosphere become external dependencies. The standalone artifact (if still desired) is built from package inputs at release time, not from checked-in source.

The bridge phase has an explicit expiration gate: once package-based artifact assembly is green in CI, new feature work must not extend `bundled/` except through upstream syncs needed to reach migration cutover.

## Options analysis (for future reference)

The four candidate distribution models, evaluated when this SDD was originally drafted. Preserved here so the migration team has the prior reasoning when they pick this up.

### Option A: Composer VCS dependencies or package registry

Recommended as the near-term packaging proof and transition path. Prefer a package registry once upstream release automation exists; use VCS only while releases are still moving quickly.

**Pros**: reproducible version pinning via lockfile; removes manual sync as source of truth; clean audit trail; CI-friendly; supports private/pre-release Atmosphere builds.
**Cons**: WP plugin users don't run Composer; resulting zip may still embed backend source (build-time vendoring instead of checked-in vendoring); VCS deps slower than registry.

### Option B: Split FOSSE UI from backend plugins

Recommended long-term architecture. Do not switch until dependency UX and platform artifact assembly are proven.

**Pros**: clean ownership boundary; backend plugins update independently; no hidden copies; aligns with upstream-first; explicit dependency state in wp-admin; removes class-collision and activation-hook gaps from programmatic nested loading.
**Cons**: standalone install no longer one zip unless all plugins assembled; requires dependency UX work; WP plugin dependency tooling helps but UX still needs design; wp.com Simple needs platform deployment answer.

### Option C: wp.com-specific artifact strategy

Recommended as part of the transition. wp.com Simple can keep artifact vendoring longer than the public repo if needed, but it should become platform packaging, not checked-in FOSSE source.

**Pros**: matches wp.com's platform control over plugin deployment; avoids forcing wp.com constraints into open-source repo; preserves one-step rollout while public source moves; allows platform-specific pinning, staged rollout, rollback.
**Cons**: two distribution paths to test separately; wp.com artifact behavior could diverge from public zip; requires clear ownership of artifact assembly outside FOSSE source tree.

### Option D: Continued vendoring with stricter policy

**This is the current production choice.** Accept as a long bridge while migration triggers haven't fired. Not recommended as the eventual long-term architecture, but the right choice today given the deferral rationale above.

**Pros**: lowest immediate risk; preserves current standalone zip + wp.com Simple behavior; easy to understand; current guardrails (no hand edits, sync-only refresh) work.
**Cons**: still makes FOSSE carry other plugins' source; risks the temporary approach becoming permanent (already happened); creates large vendored diffs; doesn't solve dependency ownership.

## Target architecture (when implementation begins)

```
Development source:

Automattic/fosse
  |-- fosse.php
  |-- src/                       # FOSSE UI/orchestration
  |-- composer.json              # FOSSE runtime deps
  `-- packaging config           # backend version pins, not backend source

Automattic/wordpress-activitypub
  `-- independent plugin release

Automattic/wordpress-atmosphere
  `-- independent plugin release
```

Distribution artifacts at the migration target:

```
Public standalone artifact (final):
  fosse/
    fosse.php
    src/
    vendor/
  plus explicit dependency UX for ActivityPub + Atmosphere

wp.com Simple artifact:
  platform-controlled bundle of FOSSE + backend plugins
  with pinned backend versions and rollout/rollback controls
```

## Migration principles (when implementation begins)

- **Source ownership stays upstream.** If a change is useful outside FOSSE, land it in ActivityPub or Atmosphere first.
- **FOSSE owns projection and product policy.** FOSSE-specific option defaults, unified UI, and cross-network coordination stay in FOSSE.
- **Build artifacts may contain dependencies; source should not.** Temporary artifact vendoring is acceptable. Long-term checked-in backend source is not.
- **Dependency state must be visible.** Users and operators should be able to tell whether ActivityPub and Atmosphere are installed, active, bundled, or missing.
- **Rollout should be reversible.** Do not delete `bundled/` until package-based artifact assembly and dependency UX both have tests.

## Required proofs before removing `bundled/` (when implementation begins)

1. **Packaging proof**: resolve AP and Atmosphere from versioned inputs; assemble an installable FOSSE artifact without relying on checked-in `bundled/`; verify the artifact includes whatever backend files the selected transition path requires; CI build job green.
2. **Runtime proof**: install generated artifact in Playground; FOSSE admin loads without fatals; AP and Atmosphere provider availability reported correctly; standalone backend plugins don't class-collide with FOSSE.
3. **Dependency UX proof**: missing AP shows clear action/state; missing Atmosphere shows clear action/state; installed-but-inactive distinguished from absent; FOSSE setup/status pages remain useful when one backend is unavailable.
4. **wp.com Simple proof**: platform artifact or deployment path installs FOSSE plus required backend versions; load ordering still suppresses duplicate platform AP loads where required; rollback returns to previous known-good artifact.

## Non-goals (now AND when implementation begins)

- This SDD does not remove `bundled/`.
- This SDD does not pick exact package names or registry infrastructure.
- This SDD does not redesign FOSSE onboarding or provider UI.
- This SDD does not change upstream release policy for ActivityPub or Atmosphere.

## Open questions (for the future migration team)

- Should the first packaging proof use Composer VCS repositories, GitHub release zips, or an internal Automattic package registry?
- Does the public FOSSE zip need to remain one-click with backend artifacts included after v1, or can it rely on WordPress plugin dependency installation UX?
- Who owns wp.com Simple artifact assembly once it diverges from the public plugin zip?
- What version pin format should be the source of truth during the bridge phase: Composer lock, package manifest, or a dedicated backend manifest?
- What is the earliest release milestone where checked-in `bundled/` can be deleted without harming current rollout?
