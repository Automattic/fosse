# Spec: Bundled Backends Migration

## Goal

Move FOSSE from "checked-in bundled backend plugins are the product architecture" to "FOSSE is the UI/orchestration plugin, and backend plugins are independently distributed dependencies", without interrupting current standalone zip installs or wp.com Simple rollout.

## Recommendation

### Near-Term Recommendation

Keep artifact vendoring as the near-term bridge, but tighten policy and prove a replacement packaging path immediately.

Near-term means:

- Continue shipping the existing standalone zip with backend artifacts included while FOSSE needs one-click install behavior.
- Keep `bundled/` read-only and refreshed only through `tools/sync-bundled.sh`.
- Add explicit policy and verification around vendored source so no one treats it as FOSSE-owned code.
- Build a packaging proof that assembles ActivityPub and Atmosphere from versioned package inputs instead of checked-in `bundled/` source.
- Keep wp.com Simple on artifact vendoring until the platform has an equivalent deployment bundle or dependency-management story.

This avoids breaking rollout while making the migration measurable.

### Long-Term Recommendation

Split FOSSE UI/orchestration from backend plugin distribution.

Long-term, FOSSE should not contain hidden copies of ActivityPub or Atmosphere in its repository. The preferred model is:

- `wordpress-activitypub` and `wordpress-atmosphere` ship as independent plugins with their own releases.
- FOSSE declares and detects those backend plugins as dependencies.
- FOSSE owns shared UI, onboarding, settings projection, provider state, and FOSSE-specific policy.
- Backend-agnostic correctness and protocol implementation continue upstream.
- Platform builds, including wp.com Simple, may assemble FOSSE plus required backend plugins as a deployment artifact, but that should be a platform packaging concern rather than FOSSE's source tree shape.

Composer VCS dependencies or an Automattic package registry are the best transition mechanism for reproducible artifact assembly. They are not the final user-facing architecture by themselves, because a WordPress site installing a plugin zip cannot be expected to run Composer.

## Options Evaluated

### Option A: Composer VCS Dependencies or Package Registry

FOSSE could depend on versioned package inputs for ActivityPub and Atmosphere, either through VCS repositories or a package registry. The build step would resolve those inputs and assemble the distribution artifact.

**Pros**

- Reproducible version pinning through `composer.lock` or an equivalent lockfile.
- Removes local manual sync as the source of truth.
- Creates a clean audit trail for which upstream versions ship.
- Fits CI proofs and artifact builds well.
- Can support private/pre-release Atmosphere builds before public plugin-directory availability.

**Cons**

- WordPress plugin users do not run Composer during plugin install.
- Composer packages for full WordPress plugins need careful install paths, artifact pruning, and autoload boundaries.
- If the resulting zip still embeds backend plugin source, this replaces checked-in vendoring with build-time vendoring but does not by itself solve hidden dependency ownership.
- VCS dependencies are slower and less stable than a package registry for routine CI.

**Assessment**

Recommended as the near-term packaging proof and transition path. Prefer a package registry once upstream release automation exists; use VCS only while releases are still moving quickly.

### Option B: Split FOSSE UI from Backend Plugins

FOSSE becomes an orchestration/UI plugin that requires ActivityPub and Atmosphere to be installed separately. It detects backend availability, guides activation, and degrades clearly when dependencies are missing.

**Pros**

- Clean ownership boundary.
- Backend plugins update independently and remain visible to site owners.
- FOSSE stops shipping hidden copies of other plugins.
- Aligns with upstream-first policy.
- Makes dependency state explicit in wp-admin instead of implicit in `bundled/`.
- Removes class-collision and activation-hook gaps caused by programmatic nested plugin loading.

**Cons**

- Standalone install is no longer a single zip unless the artifact includes all plugins or the user installs dependencies separately.
- Requires dependency UX, activation checks, and failure states.
- WordPress plugin dependency tooling helps, but the user experience still needs design and testing.
- wp.com Simple needs a platform deployment answer so rollout remains one coordinated install.

**Assessment**

Recommended long-term architecture. Do not switch until dependency UX and platform artifact assembly are proven.

### Option C: wp.com-Specific Artifact Strategy

Keep FOSSE source clean, but let wp.com Simple build/deploy an artifact containing FOSSE plus the required backend plugins.

**Pros**

- Matches wp.com's platform control over plugin deployment and load ordering.
- Avoids forcing wp.com constraints into the open-source repository.
- Can preserve one-step rollout while FOSSE's public source moves toward explicit dependencies.
- Allows platform-specific pinning, staged rollout, and rollback.

**Cons**

- Creates two distribution paths that must be tested separately.
- Risk of wp.com artifact behavior diverging from the public plugin zip.
- Requires clear ownership of artifact assembly and backend version pins outside the FOSSE source tree.

**Assessment**

Recommended as part of the transition. wp.com Simple can keep artifact vendoring longer than the public repo if needed, but it should become platform packaging, not checked-in FOSSE source.

### Option D: Continued Vendoring With Stricter Policy

Keep `bundled/` checked in, but add stronger rules: no hand edits, source SHA manifest, automated drift checks, build checks, and periodic removal review.

**Pros**

- Lowest immediate risk.
- Preserves current standalone zip and wp.com Simple behavior.
- Easy to understand with current code.
- Adds guardrails quickly.

**Cons**

- Still makes FOSSE carry other plugins' source.
- Still risks the temporary approach becoming permanent.
- Still creates large generated/vendored diffs.
- Does not solve dependency ownership or independent backend updates.

**Assessment**

Accept only as a short bridge while Option A's packaging proof and Option B's dependency model are built. Not recommended as the long-term architecture.

## Chosen Direction

Use a two-phase migration:

1. **Bridge phase:** Keep the current checked-in `bundled/` artifacts, but harden the policy and add migration checks. In parallel, prove that CI can assemble an equivalent FOSSE zip from versioned backend package inputs.
2. **Migration phase:** Move backend source out of the FOSSE repository once the packaging proof, dependency UX, and wp.com artifact path are accepted. FOSSE then treats ActivityPub and Atmosphere as external backend plugins.

The bridge phase should have an explicit expiration gate: once package-based artifact assembly and dependency UX are green in CI, new backend feature work must not extend `bundled/` except through upstream syncs required to reach the migration cutover.

## Target Architecture

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

Distribution artifacts may differ:

```
Public standalone artifact, transitional:
  fosse/
    fosse.php
    src/
    vendor/
    bundled/ or assembled backend artifacts

Public standalone artifact, final:
  fosse/
    fosse.php
    src/
    vendor/
  plus explicit dependency UX for ActivityPub + Atmosphere

wp.com Simple artifact:
  platform-controlled bundle of FOSSE + backend plugins
  with pinned backend versions and rollout/rollback controls
```

## Migration Principles

- **Source ownership stays upstream.** If a change is useful outside FOSSE, land it in ActivityPub or Atmosphere first.
- **FOSSE owns projection and product policy.** FOSSE-specific option defaults, unified UI, and cross-network coordination stay in FOSSE.
- **Build artifacts may contain dependencies; source should not.** Temporary artifact vendoring is acceptable. Long-term checked-in backend source is not.
- **Dependency state must be visible.** Users and operators should be able to tell whether ActivityPub and Atmosphere are installed, active, bundled, or missing.
- **Rollout should be reversible.** Do not delete `bundled/` until package-based artifact assembly and dependency UX both have tests.

## Required Proofs Before Removing `bundled/`

1. **Packaging proof**
   - Resolve ActivityPub and Atmosphere from versioned inputs.
   - Assemble an installable FOSSE artifact without relying on checked-in `bundled/`.
   - Verify the artifact includes whatever backend files the selected transition path requires.
   - Run `composer run-script build-zip` or successor build command in CI.

2. **Runtime proof**
   - Install the generated artifact in Playground.
   - Confirm FOSSE admin loads without fatals.
   - Confirm ActivityPub and Atmosphere provider availability is reported correctly.
   - Confirm standalone backend plugins do not class-collide with FOSSE.

3. **Dependency UX proof**
   - Missing ActivityPub shows a clear action/state.
   - Missing Atmosphere shows a clear action/state.
   - Installed-but-inactive backends are distinguished from absent backends.
   - FOSSE setup/status pages remain useful when one backend is unavailable.

4. **wp.com Simple proof**
   - Platform artifact or deployment path installs FOSSE plus the required backend versions.
   - Load ordering still suppresses duplicate platform ActivityPub loads where required.
   - Rollback can return to the previous known-good artifact.

## Build and CI Implications

The existing `bin/build-zip.sh` assumes tracked source plus production `vendor/`. The migration should either:

- Extend the build to assemble backend artifacts from package inputs before zipping, or
- Remove backend artifacts from the FOSSE zip and rely on explicit plugin dependencies.

During the bridge phase, CI should keep verifying the current checked-in bundle. Once the packaging proof exists, CI should add a second artifact job that builds from package inputs and compares key files/behavior against the current bundle.

Minimum checks:

- Backend entrypoints are present in transitional artifacts when expected.
- FOSSE root `vendor/autoload_packages.php` is present.
- Bundled or external backend activation path does not fatal.
- `bundled/` is not included in FOSSE Composer classmap.
- No files under `bundled/` are modified except by a sync task.

## Deprecation Plan

1. Mark checked-in `bundled/` as bridge-only in SDD, AGENTS.md, and release engineering notes.
2. Add a backend-version manifest or package-lock equivalent so vendored source has traceable provenance while it remains.
3. Prove package-based artifact assembly in CI.
4. Build dependency UX for missing/inactive external backends.
5. Decide whether the public standalone artifact should:
   - Continue including backend artifacts assembled at build time for one-click install, or
   - Stop including backend artifacts and rely on explicit plugin dependencies.
6. Move wp.com Simple to a platform-owned artifact/dependency strategy.
7. Remove checked-in `bundled/`, `tools/sync-bundled.sh`, and bundle-specific export/linguist/tooling rules after the selected replacement ships.

## Non-Goals

- This SDD does not remove `bundled/`.
- This SDD does not pick exact package names or registry infrastructure.
- This SDD does not redesign FOSSE onboarding or provider UI.
- This SDD does not change upstream release policy for ActivityPub or Atmosphere.

## Open Questions

- Should the first packaging proof use Composer VCS repositories, GitHub release zips, or an internal Automattic package registry?
- Does the public FOSSE zip need to remain one-click with backend artifacts included after v1, or can it rely on WordPress plugin dependency installation UX?
- Who owns wp.com Simple artifact assembly once it diverges from the public plugin zip?
- What version pin format should be the source of truth during the bridge phase: Composer lock, package manifest, or a dedicated backend manifest?
- What is the earliest release milestone where checked-in `bundled/` can be deleted without harming current rollout?
