# Spec: Bundled Backends

## Goal

Ship FOSSE with the release-build source of `wordpress-activitypub` (Mastodon federation) and `wordpress-atmosphere` (AT Protocol / Bluesky federation) vendored into the plugin, loaded automatically at bootstrap, and suppressed when the user already has the standalone plugin active. This unblocks the Week 1 "post on WordPress, see it on Mastodon" milestone without requiring users to install two extra plugins, and gives FOSSE a concrete federation engine to build its own UI on top of. This is a deliberately short-term bootstrap — a "rough starting point" — expected to be replaced when FOSSE gains its own unified posting/admin UI.

## Requirements Summary

- Raise FOSSE's PHP floor to 8.2 (Atmosphere's requirement).
- Vendor release-build copies of both plugins under a stable path, including Atmosphere's built `vendor/`.
- Skip bundled load when `ACTIVITYPUB_PLUGIN_VERSION` / `ATMOSPHERE_VERSION` sentinels (or equivalent classes) are already defined.
- When bundled copies load, let them run as-is — no UI suppression, no namespace rewrites.
- Bridge the activation-hook gap with a one-time first-load bootstrap so CPTs, rewrite rules, and seeded options get set up.
- Provide a sync script; exclude tests/dev-tooling/docs/lockfiles from the bundled copy.
- Exclude bundled source from FOSSE's composer classmap, PHPCS, PHPUnit, ESLint, Prettier, Jest.
- Option keys stay upstream-identical so a later switch to standalone is seamless.

## Chosen Approach

**A — Minimal shim.** Inline load logic in `fosse.php`, bundled source at top-level `bundled/`, direct call to upstream `activate()` routines guarded by per-backend sentinel options, bash+rsync sync script, Atmosphere's `vendor/` checked in.

Selected because it matches the explicit "short-term bootstrap" framing: smallest possible surface, one self-contained PR, easy to delete when FOSSE grows its own UI. We don't invest in an abstraction that's likely to be replaced.

### Alternatives Considered

- **B — Modest abstraction (Bundled\\Loader class).** A cleaner, array-driven loader in `src/`. Rejected for now: the abstraction would be replaced when FOSSE builds its own UI anyway, and A's ~20-line inline block is not a maintenance burden at n=2 backends.
- **C — Bundle-as-library (mirror activation side-effects).** Most decoupled from upstream internals, but doubles the maintenance for every upstream activation-logic change. Rejected as over-engineering for a bootstrap.

## Technical Details

### Architecture

FOSSE's existing bootstrap (`fosse.php`) is a thin header + composer autoload. We layer the bundled-backend loader directly into it:

```
fosse.php
  ├── require vendor/autoload.php (existing)
  ├── load bundled/activitypub/activitypub.php   (if sentinel absent)
  └── load bundled/atmosphere/atmosphere.php      (if sentinel absent)
```

Because WordPress loads active plugins alphabetically and `fosse/` sorts after `activitypub/` and `atmosphere/`, the standalone plugins — if active — have already defined their constants and classes before `fosse.php` runs. Sentinel checks therefore reliably detect them.

When the bundled copy loads, it runs normally: its own constants/autoloader/REST routes/CPT/admin menu register exactly as upstream does. Settings pages appear under their native menus (e.g., Settings → ActivityPub).

The activation-hook gap is bridged in a `fosse_bundled_bootstrap` function hooked to `plugins_loaded` at late priority (after the bundled copies have loaded):

```
if ( bundled_ap_was_loaded && ! get_option( 'fosse_bundled_ap_bootstrapped' ) ) {
    \Activitypub\Activitypub::activate();
    update_option( 'fosse_bundled_ap_bootstrapped', ACTIVITYPUB_PLUGIN_VERSION );
}
// same for Atmosphere
```

The stored value is the upstream version string (not just `true`) so future versions can detect a version change and re-run bootstrap if upstream introduces a new side-effect.

### Data Flow

Not applicable — this is runtime loader wiring, no user data flows through new code paths.

### Key Components

| Component | Responsibility |
|---|---|
| `fosse.php` bundled-load block | Per-backend sentinel check; `require_once` bundled entrypoint. |
| `fosse_bundled_bootstrap()` | Per-backend first-load activation shim. Hooked to `plugins_loaded` @ priority 20 (after bundled copies' own `plugins_loaded` hooks at default priority 10). Gates on sentinel option vs. upstream version. |
| `bundled/activitypub/` | Release-build copy of wordpress-activitypub. Read-only; refreshed via sync script. |
| `bundled/atmosphere/` | Release-build copy of wordpress-atmosphere, including `vendor/` with `web-token/jwt-library`. Read-only. |
| `tools/sync-bundled.sh` | Bash+rsync sync script. Reads `FOSSE_AP_SOURCE` / `FOSSE_ATMO_SOURCE` env vars (defaulting to `~/code/wordpress-activitiypub` and `~/code/wordpress-atmosphere`). Runs `composer install --no-dev --optimize-autoloader --working-dir="$FOSSE_ATMO_SOURCE"` before rsync-ing Atmosphere so `vendor/` is present. Uses a fixed exclude list. |
| Tooling excludes | `.phpcs.xml.dist`, `phpunit.xml.dist`, `eslint.config.mjs`, `.prettierignore`, `jest.config.js`, `composer.json` (`autoload.exclude-from-classmap`). |
| CI matrix trim | `.github/workflows/tests.yml` drops PHP 7.4/8.0/8.1 rows; 8.2 becomes the new floor. |

### File Changes

| File | Change Type | Description |
|------|-------------|-------------|
| `fosse.php` | modify | Bump `Requires PHP` header from 7.4 to 8.2. Add bundled-load block (sentinel check + `require_once`) for each backend. Add `fosse_bundled_bootstrap()` hooked to `plugins_loaded` @ 20. |
| `composer.json` | modify | Bump `require.php` to `>=8.2`. Add `autoload.exclude-from-classmap` entry for `bundled/`. |
| `.github/workflows/tests.yml` | modify | Drop PHP 7.4/8.0/8.1 rows from the matrix. |
| `.phpcs.xml.dist` | modify | Add `<exclude-pattern>*/bundled/*</exclude-pattern>`. |
| `phpunit.xml.dist` | modify | Add `<exclude>bundled</exclude>` to the test suite definition so upstream tests never execute even if one slips through sync. |
| `eslint.config.mjs` | modify | Add `bundled/**` to the ignore list. |
| `.prettierignore` | modify | Add `bundled/`. |
| `jest.config.js` | modify | Add `bundled/` to `testPathIgnorePatterns` and `coveragePathIgnorePatterns`. |
| `.gitignore` | modify (if needed) | Ensure nothing in `bundled/` is accidentally ignored (we want it checked in). |
| `bundled/activitypub/**` | new (vendored) | Release-build source of wordpress-activitypub, minus tests/dev-tooling/docs/lockfiles. Initial contents from `~/code/wordpress-activitiypub` at v7.9.0 (or latest stable .org ZIP). |
| `bundled/atmosphere/**` | new (vendored) | Release-build source of wordpress-atmosphere including `vendor/`, minus tests/dev-tooling/docs/lockfiles. Initial contents from `~/code/wordpress-atmosphere`. |
| `tools/sync-bundled.sh` | new | Bash+rsync sync script with env-var-driven upstream paths and a fixed exclude list. Also runs Atmosphere `composer install --no-dev --optimize-autoloader` before its rsync. |
| `tools/bundled-excludes.txt` | new | Rsync exclude list (tests, `.github`, docs, lockfiles, `node_modules`, upstream `.git`, etc.) kept separate so the script body stays readable. |
| `src/` | unchanged | Stays empty for this SDD; FOSSE's own code arrives in a later SDD (CPT + posting UI + unified stream). |

## Out of Scope

- Custom FOSSE UI replacing the bundled plugins' admin screens (intentional — deferred to a later SDD).
- Reader / inbound consumption features.
- FOSSE's own CPT, posting UI, unified homepage stream, reactions-come-home wiring.
- PHP <8.2 fallback (we raised the floor instead).
- Long-term distribution/versioning strategy (Composer VCS dep, Automattic registry, automated upstream drift detection). For v1 the sync script is human-driven.
- Explicit migration or cleanup flow when the user installs the standalone plugin after using the bundled copy. Option-key compatibility is automatic (it IS upstream code), so we rely on that transparent handover.
- Filtering out the bundled plugins' text domain / translation loads (they'll load alongside FOSSE's).
- WP-CLI commands for sync / bootstrap re-run.

## Open Questions Resolved

- **Bundled source location:** Chose top-level `bundled/` over `src/bundled/`. Keeps `src/` meaningfully "FOSSE's own code" and avoids a multi-tool classmap/ignore dance under `src/`.
- **Atmosphere vendor handling:** Check in. The sync script runs `composer install --no-dev --optimize-autoloader` in the upstream Atmosphere source before rsync, so the checked-in `bundled/atmosphere/vendor/` is always present and self-contained. Contributors don't need to `composer install` inside bundled directories.
- **First-load bootstrap mechanics:** Call upstream `Activate` routines directly (`\Activitypub\Activitypub::activate()` and Atmosphere's equivalent). Accepts short-term coupling to upstream internal API shape; acceptable because the bundled approach itself is short-term. Store the upstream version in the bootstrap-sentinel option to enable "re-bootstrap on upstream upgrade" if ever needed.
- **Sync script config source:** Environment variables (`FOSSE_AP_SOURCE`, `FOSSE_ATMO_SOURCE`) with sensible defaults matching existing `~/code/` checkouts. Documented in script header. Avoids a separate config file for v1.
- **Plugin header collision:** The bundled plugins' `Plugin Name:` headers do not cause duplicate entries in the WP plugins screen because they live under `bundled/` (not `wp-content/plugins/`). WordPress scans only `wp-content/plugins/*/` and `wp-content/plugins/*.php` top-level for plugins. No action needed.
- **PHPUnit / bundled tests:** Belt-and-braces — the sync script excludes upstream `tests/`, and `phpunit.xml.dist` adds a hard exclude for the `bundled/` directory, so even a stray upstream test file would not execute under FOSSE's PHPUnit.

## Review Notes for Ryan

This spec is intentionally narrow: it just gets both federation engines loading inside FOSSE. Noteworthy decisions to push back on if you disagree:

1. **PHP 8.2 floor.** Non-trivial bump from FOSSE's current 7.4. Driven entirely by Atmosphere's declared requirement. If we can confirm Atmosphere's actual syntax only needs 8.1, we could soften that.
2. **Direct `activate()` call on first load.** Couples FOSSE to upstream internals. The alternative (mirror side-effects ourselves) doubles maintenance on every upstream release — we picked the coupling.
3. **Bundled source at repo root (`bundled/`) not under `src/`.** Arguable either way; picked root for clean separation of "FOSSE code" vs. "vendored plugins".
4. **No admin UI suppression.** The bundled plugins' settings pages show up as-is. The crisp FOSSE UI is a later SDD; we're explicit that this is short-term.
