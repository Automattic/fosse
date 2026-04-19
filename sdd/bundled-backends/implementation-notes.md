# Implementation Notes — Bundled Backends

## Deviations from Spec

### First-load bootstrap runs on `init`, not `plugins_loaded @ 20`
- **Spec said**: Hook the bootstrap shim to `plugins_loaded` at priority 20 so it fires after the bundled plugins' own `plugins_loaded` hooks (priority 10) have initialized.
- **Implementation does**: Hooks to `init` at priority 20.
- **Reason**: `\Activitypub\Activitypub::activate()` calls `flush_rewrite_rules()`, which requires the `$wp_rewrite` global. That global is only initialized during `init`. Running the shim on `plugins_loaded` produces a fatal: `Call to a member function add_rule() on null in /wordpress/wp-includes/rewrite.php:143`. Confirmed in Playground during E2E work.
- **Impact**: Bootstrap now fires one hook-cycle later. No user-visible difference — the first-request bootstrap still completes before the first user-facing response, just after rewrite is ready. `init` runs on every request, but `Bootstrap::maybe_run` is idempotent after the version is recorded so the steady-state cost is a single `get_option` lookup per backend per request.

### `Activitypub::activate()` requires an argument
- **Spec said**: Call upstream `activate()` routines directly via `array( \Activitypub\Activitypub::class, 'activate' )`.
- **Implementation does**: Wraps the call in a closure that passes `false` for the `$network_wide` parameter: `static function () { \Activitypub\Activitypub::activate( false ); }`.
- **Reason**: `Activitypub::activate( $network_wide )` is a 1-argument method because WordPress normally passes `$network_wide` when a plugin is activated from the plugins screen. Callable-as-array invocation passes zero args, producing `ArgumentCountError: Too few arguments`.
- **Impact**: None functionally — we declare the activation non-network-wide, which matches the "bundled copy runs on a single site" model. Atmosphere's `activate()` takes no arguments, so that backend is unaffected.

### PHPCS `testVersion` lowered from the 7.4 floor in the same commit as tooling excludes
- **Spec said**: (Implicit — the spec didn't explicitly call for updating the PHPCompatibility `testVersion`.)
- **Implementation does**: Task 3 also bumped `.phpcs.xml.dist` `testVersion` from `7.4-` to `8.2-` to match the new PHP floor.
- **Reason**: Leaving `testVersion` at `7.4-` would have PHPCS flag legal 8.2+ syntax as "incompatible with PHP 7.4" — incoherent with the new floor.
- **Impact**: Stricter-but-more-accurate compatibility checking. No code changes required to satisfy it.

### Prettier gained extra ignores for `sdd/` and `.claude/`
- **Spec said**: (Implicit — spec called only for `bundled/` prettier ignore.)
- **Implementation does**: Also adds `sdd/` and `.claude/` to `.prettierignore`.
- **Reason**: CI Prettier check was failing on the three SDD markdown files authored during this feature (pre-existing failure from the initial docs PR), and locally failing on `.claude/` Conductor worktree artifacts. SDD docs are discussion prose, not product surface. `.claude/` is contributor-local.
- **Impact**: Cleaner CI. No effect on shipped code.

### E2E workflow now runs `composer install` before Playwright
- **Spec said**: (Implicit — spec didn't touch CI workflows beyond the PHP matrix trim.)
- **Implementation does**: `.github/workflows/e2e.yml` now installs PHP + runs `composer install --no-dev --optimize-autoloader` before Playwright starts Playground.
- **Reason**: Playground mounts the repo root as a plugin. Our bootstrap fire relies on `\Automattic\Fosse\Bundled\Bootstrap`, which lives in `src/` and is autoloaded via composer. Without `vendor/autoload.php`, Playground fatals at `init@20`: `Class "Automattic\Fosse\Bundled\Bootstrap" not found`. The PHPUnit job already runs composer; e2e didn't. In production, released FOSSE builds will ship `vendor/` baked in — CI now matches that.
- **Impact**: +~10s e2e job time. Matches what an installed plugin would have.

### A minor class (`src/Bundled/Bootstrap.php`) was extracted rather than keeping the bootstrap inline in `fosse.php`
- **Spec said**: "Approach A — Minimal shim. Inline load logic in `fosse.php`."
- **Implementation does**: The load block stays inline in `fosse.php`, but the first-load gating logic (version-keyed option check + idempotent activate invocation) lives in `\Automattic\Fosse\Bundled\Bootstrap::maybe_run` with a 3-test PHPUnit spec.
- **Reason**: Idempotency/version-change behavior is the only real logic in this feature and benefits from a unit test. Plan.md flagged this deviation before implementation; it was approved by the user when picking the plan. Included here for completeness of the record.
- **Impact**: One extra PHP file (~35 LOC) and one test file. No runtime behavior change vs. a pure inline implementation.

## Known Limitations

### Atmosphere's `'unreleased'` version string means the bootstrap shim fires exactly once, ever
`Bundled\Bootstrap::maybe_run` keys on the upstream version constant so a sync that bumps to a new version re-runs activation. Atmosphere currently hard-codes `ATMOSPHERE_VERSION = 'unreleased'`. Once `fosse_bundled_atmosphere_bootstrapped` is seeded to `'unreleased'`, re-syncing the bundle will not re-trigger activation even if upstream changes add new option defaults, rewrite rules, or other activation-time side effects. Acceptable for this short-term bootstrap; will be a non-issue once (a) Atmosphere cuts real releases, or (b) we replace bundling with a cleaner distribution approach. If it becomes a problem before either of those, the fix is to have `tools/sync-bundled.sh` write the upstream SHA to a tracked marker file and use that as the version key.

### Production installs must ship `vendor/autoload.php`
`Automattic\Fosse\Bundled\Bootstrap` is autoloaded via composer. If FOSSE is installed in an environment without `vendor/` (bare clone, unpackaged release), the `init@20` hook now degrades cleanly (`class_exists` check) and skips the bootstrap — bundled plugins still load, just without their first-run activation side effects. Released FOSSE builds must still include `vendor/` for the shim to run; this is standard plugin-release hygiene but worth calling out.

## Notes

- **Upstream SHAs vendored in this PR**: wordpress-activitypub `c7d64fb2`, wordpress-atmosphere `d4bc2b7`. Recorded in the Task 6 commit body for traceability.
- **Bundle sizes**: `bundled/activitypub/` ≈ 5.2 MB (357 files), `bundled/atmosphere/` ≈ 3.1 MB (576 files, including production `vendor/` for `web-token/jwt-library` and transitive deps). Most of the file-count is Atmosphere's vendor tree.
- **Bundled Atmosphere ships its `vendor/`**: No runtime `composer install` in the FOSSE directory; the bundle is self-contained.
- **E2E coverage is intentionally shallow**: one smoke test confirms WP admin boots and the bundled AP submenu registers. Deeper feature tests (post federation, Bluesky handoff, reactions) are out of scope for this SDD and land with their respective feature SDDs.
- **Tooling sanity**: PHPUnit (4 tests / 7 assertions), Jest, ESLint, Prettier, and Playwright (2 tests) all green locally before push.
- **Follow-up work identified**:
  - Task 8's `Bundled\Bootstrap` test spec assumes a single option key per backend. If a future change needs cross-backend coordination (e.g. re-running AP activation when Atmosphere version changes), this class and its tests will need to grow.
  - No deactivation/uninstall path for bundled backends. When FOSSE is deactivated, the bundled plugins' side-effects (rewrite rules, stored options) persist. Spec flagged this as out-of-scope; recording here so it isn't forgotten.
  - Bundling is explicitly a short-term bootstrap. Expected to be replaced by a cleaner distribution mechanism (composer VCS dep, Automattic package registry, or splitting the FOSSE UI from the backends entirely) in a later SDD.
