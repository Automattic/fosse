# Bundled Backends — Requirements

## Goal

Bundle the release-build versions of `wordpress-activitypub` and `wordpress-atmosphere` into FOSSE so the plugin ships with Mastodon (ActivityPub) and Bluesky (AT Protocol) federation working out of the box. When the standalone plugin is already active on the site, skip loading FOSSE's bundled copy to avoid collisions. This is an intentionally short-term bootstrap — a "rough starting point" for the Week 1 milestone — that FOSSE will iterate on (crisp, unified UI replacing the bundled plugins' admin screens is a later SDD item).

## Requirements

1. **Raise FOSSE's minimum PHP from 7.4 to 8.2.** Update `fosse.php` header, `composer.json` `require.php`, and the CI matrix in `.github/workflows/tests.yml` (dropping 7.4/8.0/8.1 rows). Driven by Atmosphere's declared 8.2 requirement.
2. **Vendor release-build copies of both plugins** under a stable subdirectory (e.g. `src/bundled/activitypub/` and `src/bundled/atmosphere/`):
   - ActivityPub: the .org-distributable ZIP contents (plugin is already published on wordpress.org).
   - Atmosphere: the equivalent locally-built release artifact (plugin is in .org review at time of writing), including its `vendor/` with `web-token/jwt-library`.
3. **Provide a sync script** (e.g. `tools/sync-bundled.sh`) that refreshes the bundled copies from a configurable upstream path, excluding:
   - Tests (`tests/`, `phpunit.xml*`)
   - Dev tooling (`.github/`, `phpcs.xml*`, `jest.config*`, `composer.lock`, `package.json`, `package-lock.json`, `pnpm-lock.yaml`)
   - Docs and changelogs (`docs/`, `README.md`, `CHANGELOG.md`, `CODE_OF_CONDUCT*`, `SECURITY*`, `CONTRIBUTING*`)
   - Transient folders (`node_modules/`, upstream source `.git/`)
4. **Skip bundled load when standalone is active.** Use defined-constant / `class_exists(..., false)` sentinel checks at FOSSE bootstrap:
   - Skip bundled ActivityPub when `ACTIVITYPUB_PLUGIN_VERSION` is defined (or equivalent class sentinel).
   - Skip bundled Atmosphere when `ATMOSPHERE_VERSION` is defined (or equivalent class sentinel).
5. **Load the bundled copies as-is when not skipped.** No UI suppression, no option filtering, no namespace rewrites. Bundled plugins register their CPTs, REST routes, options, and admin menus exactly as upstream does.
6. **Handle the activation-hook gap.** WordPress activation hooks fire only when a plugin is activated via the plugin screen. Bundled copies are loaded programmatically and therefore miss activation side-effects (option seeding, rewrite flush, first-run publication TID, etc.). FOSSE must provide a one-time "first-load bootstrap" shim — e.g. sentinel options per backend (`fosse_bundled_ap_bootstrapped`, `fosse_bundled_atmosphere_bootstrapped`) that invoke the upstream `activate()` routines on first matched load, then mark complete.
7. **Keep bundled source out of FOSSE's own tooling.** Specifically:
   - Exclude `src/bundled/**` from FOSSE's composer classmap autoload (bundled plugins register their own autoloaders).
   - Exclude `src/bundled/**` from `.phpcs.xml.dist`.
   - Exclude `src/bundled/**` from `phpunit.xml.dist` (defense-in-depth in case an upstream test file slips through the sync).
   - Exclude `src/bundled/**` from ESLint / Prettier / Jest configs as applicable.

## Constraints

- Short-term bootstrap by explicit request — do not over-engineer (no custom install resolver, no version-drift detector, no admin UI for bundle management).
- Must not collide with user-installed standalone plugins (classes, constants, options, REST routes, CPT registration).
- Must keep FOSSE's existing CI green: PHPUnit (PHP matrix), Jest, Playwright E2E, PHPCS, ESLint/Prettier.
- Option keys and DB schema stay identical to upstream (automatic — it IS upstream code), so a later switch to the standalone plugin transparently picks up where the bundled copy left off. No explicit migration path in v1.

## Out of Scope

- Any custom FOSSE UI replacing the bundled plugins' admin screens. (Later iteration.)
- Reader / inbound consumption features.
- FOSSE's own CPT, posting UI, homepage unified stream, and reactions-come-home wiring. (Future SDD items — Weeks 1-4 per the project P2.)
- AT Protocol fallback behavior for sites running PHP <8.2 (we raised the floor instead).
- Long-term distribution strategy for bundled backends (Composer VCS dependency, Automattic package registry, git subtree automation, etc.).
- Version-pinning / update-notification UX.
- Unbundling path (removing the bundled copies when the user installs standalone) beyond the load-skip behavior.

## Open Questions

- **Bundled source location:** `src/bundled/` keeps everything under `src/` but forces classmap/namespace exclusions. Alternatives: top-level `bundled/` (cleaner separation from FOSSE code, requires updating packaging/distribution scripts). Spec should pick.
- **Atmosphere vendor checked-in vs. post-sync composer install:** Preference is to check in the release-built `vendor/` so the bundle is self-contained and contributors don't need to `composer install` inside each bundled copy. Confirm in spec.
- **First-load bootstrap mechanics:** Call upstream `Activitypub::activate()` / Atmosphere's `activate()` directly, or replicate their side-effects inline? Direct call is lower-maintenance but couples us to their internal API shape. Spec should decide.
- **Sync script upstream-path source of truth:** Env var? Config file at `tools/bundled.config`? CLI args? Spec should pick.
- **Plugin header metadata:** Do the bundled plugins' `Plugin Name:` headers need to be altered or hidden so they don't appear as separate activatable plugins in the WP plugin screen? (They shouldn't — they live under `src/bundled/`, not `wp-content/plugins/` — but worth confirming during spec.)

## Related Code / Patterns Found

- `fosse.php:1-23` — current plugin bootstrap; only requires `vendor/autoload.php`. Bundled-load logic and PHP-version bump land here.
- `src/` — empty (`.gitkeep` only). Greenfield — no existing FOSSE patterns to extend.
- `composer.json` — classmap `src/` autoload. Needs `exclude-from-classmap` entry for `src/bundled/` (or similar, depending on chosen path).
- `.phpcs.xml.dist` — needs `<exclude-pattern>` for bundled directory.
- `phpunit.xml.dist` — needs `<exclude>` for bundled directory.
- `.github/workflows/tests.yml` — PHP matrix currently 7.4/8.0/8.1/8.2/8.3/8.4/8.5; drop 7.4/8.0/8.1 when PHP baseline moves.
- `~/code/wordpress-activitiypub/activitypub.php` — reference bootstrap: declares `ACTIVITYPUB_PLUGIN_*` constants, custom autoloader at `includes/class-autoloader.php`, registers activation/deactivation/uninstall hooks on its own file path. Version 7.9.0, PHP ≥7.2, namespace `Activitypub`, text domain `activitypub`.
- `~/code/wordpress-atmosphere/atmosphere.php` — reference bootstrap: declares `ATMOSPHERE_*` constants, uses composer `vendor/autoload.php`, hooks `plugins_loaded`, activation hook seeds `atmosphere_publication_tid` and flushes rewrites. PHP ≥8.2, namespace `Atmosphere`, text domain `atmosphere`, composer deps include `web-token/jwt-library ^4.1`.
- WordPress core: `is_plugin_active()` exists but requires `wp-admin/includes/plugin.php` and runs too late for some bootstraps. Defined-constant / `class_exists(..., false)` sentinel (chosen approach) avoids that ordering issue and handles forks/renames.
