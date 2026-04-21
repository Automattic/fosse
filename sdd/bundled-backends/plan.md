# Implementation Plan: Bundled Backends

Based on: sdd/bundled-backends/spec.md

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

## Tasks

### Task 1: Raise PHP floor to 8.2
- **Files**: `fosse.php`, `composer.json`
- **Do**:
  1. In `fosse.php` change the `Requires PHP:` header from `7.4` to `8.2`.
  2. In `composer.json` change `require.php` from `>=7.4` to `>=8.2`.
  3. Run `composer update --no-interaction --no-progress` to refresh the install and autoload.
  4. Commit: `Raise FOSSE PHP floor to 8.2`
- **Verify**:
  - `grep "Requires PHP: 8.2" fosse.php` matches.
  - `composer validate` passes.
  - `composer run-script test-php` still passes on local PHP 8.2+.
- **Depends on**: none

### Task 2: Trim PHP <8.2 from CI matrix + AGENTS.md reference
- **Files**: `.github/workflows/tests.yml`, `AGENTS.md`
- **Do**:
  1. In `.github/workflows/tests.yml` change the `php:` matrix entry from `['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5']` to `['8.2', '8.3', '8.4', '8.5']`.
  2. In `AGENTS.md` under "CI Matrix", update the PHP-version list to reflect the new range.
  3. In `AGENTS.md` under "Tech Stack", update the PHP row from `>=7.4` to `>=8.2`.
  4. Commit: `ci: drop PHP <8.2 from test matrix`
- **Verify**:
  - `yq '.jobs.phpunit.strategy.matrix.php' .github/workflows/tests.yml` returns the new 4-entry list.
  - `grep "8\.2" AGENTS.md` matches; `grep "7\.4" AGENTS.md` returns nothing.
- **Depends on**: Task 1

### Task 3: Exclude `bundled/` from FOSSE tooling
- **Files**: `composer.json`, `.phpcs.xml.dist`, `phpunit.xml.dist`, `eslint.config.mjs`, `.prettierignore`, `jest.config.js`
- **Do**:
  1. In `composer.json`, add `"exclude-from-classmap": ["bundled/"]` under the `autoload` object.
  2. In `.phpcs.xml.dist`, add `<exclude-pattern>*/bundled/*</exclude-pattern>` at the ruleset top.
  3. In `phpunit.xml.dist`, add `<exclude>bundled</exclude>` inside the `<testsuite>` definition.
  4. In `eslint.config.mjs`, add `'bundled/**'` to the top-level ignores array.
  5. In `.prettierignore`, append `bundled/` on a new line.
  6. In `jest.config.js`, add `'<rootDir>/bundled/'` to `testPathIgnorePatterns` (create the key if it doesn't exist).
  7. Run `composer dump-autoload` and confirm the generated `vendor/composer/autoload_classmap.php` does not reference `bundled/` (expected: no matches at this point anyway, since `bundled/` doesn't exist yet — this is pre-declared).
  8. Commit: `Exclude bundled/ from FOSSE tooling`
- **Verify**:
  - `composer dump-autoload` succeeds.
  - `pnpm run lint` and `pnpm run format:check` succeed (no new warnings).
  - `composer run-script test-php` still passes.
- **Depends on**: none

### Task 4: Write rsync exclude list
- **Files**: `tools/bundled-excludes.txt`
- **Do**:
  1. Create `tools/bundled-excludes.txt` with one rsync pattern per line covering: `tests/`, `.github/`, `docs/`, `README.md`, `CHANGELOG.md`, `CODE_OF_CONDUCT*`, `SECURITY*`, `CONTRIBUTING*`, `phpcs.xml*`, `phpunit.xml*`, `jest.config*`, `composer.lock`, `package.json`, `package-lock.json`, `pnpm-lock.yaml`, `node_modules/`, `.git/`, `.gitignore`, `.gitattributes`, `.editorconfig`, `agent-os/`, `local/`, `bin/`.
  2. Commit: `Add rsync exclude list for bundled plugin sync`
- **Verify**:
  - File exists and has one pattern per line.
  - `rsync --exclude-from=tools/bundled-excludes.txt --dry-run ~/code/wordpress-activitiypub/ /tmp/ap-test/` runs without error and prints a file list that excludes the patterns.
- **Depends on**: none

### Task 5: Write sync script
- **Files**: `tools/sync-bundled.sh`
- **Do**:
  1. Create `tools/sync-bundled.sh` starting with `#!/usr/bin/env bash` and `set -euo pipefail`.
  2. Read upstream source paths from env vars: `FOSSE_AP_SOURCE` (default `~/code/wordpress-activitiypub`), `FOSSE_ATMO_SOURCE` (default `~/code/wordpress-atmosphere`).
  3. Validate both source dirs exist; abort with clear error if not.
  4. Before syncing Atmosphere, run `composer install --no-dev --optimize-autoloader --working-dir="$FOSSE_ATMO_SOURCE"` so `vendor/` is present.
  5. `rsync -a --delete --exclude-from=tools/bundled-excludes.txt "$FOSSE_AP_SOURCE/" "bundled/activitypub/"` and similar for Atmosphere.
  6. `chmod +x tools/sync-bundled.sh`.
  7. Commit: `Add tools/sync-bundled.sh`
- **Verify**:
  - `bash -n tools/sync-bundled.sh` passes (syntax check).
  - `shellcheck tools/sync-bundled.sh` passes if shellcheck is installed.
- **Depends on**: Task 4

### Task 6: Run first sync — vendor both plugins
- **Files**: `bundled/activitypub/**`, `bundled/atmosphere/**`
- **Do**:
  1. Ensure `~/code/wordpress-activitiypub` and `~/code/wordpress-atmosphere` are at the commits we want to vendor (note the SHAs in the commit message for traceability).
  2. Run `./tools/sync-bundled.sh`.
  3. Sanity-check the result: `bundled/activitypub/activitypub.php` exists, `bundled/atmosphere/atmosphere.php` exists, `bundled/atmosphere/vendor/autoload.php` exists, no `tests/`, `.github/`, `docs/` inside either.
  4. Commit: `Vendor bundled activitypub + atmosphere release builds` (include upstream SHAs in the commit body).
- **Verify**:
  - Both entry PHP files exist.
  - Atmosphere `vendor/` contains `autoload.php` and `web-token/` packages.
  - `git status` shows only `bundled/` additions.
  - `pnpm run lint` and `composer run-script test-php` still pass (excludes from Task 3 must hold).
- **Depends on**: Task 3, Task 5

### Task 7: Add bundled-load block to `fosse.php`
- **Files**: `fosse.php`
- **Do**:
  1. After the existing `vendor/autoload.php` require, add:
     - If `! defined( 'ACTIVITYPUB_PLUGIN_VERSION' )` AND `file_exists( __DIR__ . '/bundled/activitypub/activitypub.php' )` → `require_once __DIR__ . '/bundled/activitypub/activitypub.php'`.
     - If `! defined( 'ATMOSPHERE_VERSION' )` AND `file_exists( __DIR__ . '/bundled/atmosphere/atmosphere.php' )` → `require_once __DIR__ . '/bundled/atmosphere/atmosphere.php'`.
  2. Commit: `Load bundled backends when standalone plugin not active`
- **Verify**:
  - `php -l fosse.php` succeeds.
  - Manual Playground smoke: `pnpm run test:e2e` runs existing specs without fatal.
  - Boot WordPress locally with FOSSE active → check that `wp-admin` Settings menu shows "ActivityPub" (from bundled AP).
- **Depends on**: Task 6

### Task 8: TDD — extract and test first-load bootstrap
- **Files**: `tests/php/Bundled/BootstrapTest.php`, `src/Bundled/Bootstrap.php`
- **Do**:
  1. Write a failing PHPUnit test `Automattic\Fosse\Tests\Bundled\BootstrapTest` that:
     - Arranges: a fresh option key (deleted in `@before`), a spy callable that counts invocations, a version string like `"7.9.0"`.
     - Calls `\Automattic\Fosse\Bundled\Bootstrap::maybe_run( $option_key, $version, $callable )` twice.
     - Asserts: the callable was invoked exactly once; the option is stored with the version string; a second call with the same version is a no-op.
     - Additionally asserts: if the stored option value differs from the supplied version, the callable is invoked again and the option updates.
  2. Run `composer run-script test-php` → verify the test fails (class does not exist).
  3. Implement `src/Bundled/Bootstrap.php` with a single public static method `maybe_run( string $option_key, string $version, callable $activate ): void` that:
     - Reads the option via `get_option( $option_key )`.
     - If strict-equal to `$version`, returns.
     - Otherwise calls `$activate`, then `update_option( $option_key, $version, false )`.
  4. Run `composer run-script test-php` → verify the test now passes.
  5. Commit: `Add idempotent first-load bootstrap for bundled backends`
- **Verify**:
  - `composer run-script test-php` passes.
  - `composer run-script lint-php` passes on the new file (Jetpack ruleset).
- **Depends on**: Task 6

### Task 9: Hook bootstrap to `plugins_loaded` in `fosse.php`
- **Files**: `fosse.php`
- **Do**:
  1. After the bundled-load block from Task 7, add an `add_action( 'plugins_loaded', …, 20 )` callback that, for each backend whose bundled copy was loaded (detect via `defined( 'ACTIVITYPUB_PLUGIN_VERSION' )` AND the bundled entry file being in the loaded files list via `get_included_files()`, OR a simpler sentinel variable set during the Task-7 load block), invokes:
     - `\Automattic\Fosse\Bundled\Bootstrap::maybe_run( 'fosse_bundled_ap_bootstrapped', ACTIVITYPUB_PLUGIN_VERSION, [ \Activitypub\Activitypub::class, 'activate' ] )`
     - `\Automattic\Fosse\Bundled\Bootstrap::maybe_run( 'fosse_bundled_atmosphere_bootstrapped', ATMOSPHERE_VERSION, '\Atmosphere\activate' )`
  2. Simpler approach: set two local variables (e.g. `$fosse_loaded_bundled_ap = true`) in the Task-7 block when the bundled copy was required; reference those vars in the hook closure via `use`. Use this approach; avoid `get_included_files()`.
  3. Commit: `Run bundled-plugin activation on first load`
- **Verify**:
  - `php -l fosse.php` succeeds.
  - Fresh Playground run: after first request, `wp option get fosse_bundled_ap_bootstrapped` returns `7.9.0` (or current vendored version).
  - Second request does not re-invoke activation (spot-check by confirming no rewrite-rules flush log spam or duplicate option writes).
- **Depends on**: Task 7, Task 8

### Task 10: E2E smoke — WP admin boots with bundled backends
- **Files**: `tests/e2e/bundled-backends.spec.ts`
- **Do**:
  1. Write a Playwright spec that:
     - Navigates to `/wp-admin/options-general.php`.
     - Asserts the page loaded with HTTP 200 and has no PHP fatal-error banner.
     - Asserts the Settings submenu contains an "ActivityPub" link (proof the bundled AP registered its admin menu).
     - (Optional) Navigates to the ActivityPub settings page and asserts it renders without a fatal.
  2. Commit: `Add e2e smoke for bundled backends`
- **Verify**:
  - `pnpm run test:e2e` passes locally and in CI.
- **Depends on**: Task 9

### Task 11: Update AGENTS.md
- **Files**: `AGENTS.md`
- **Do**:
  1. Under "Directory Structure", add `bundled/` with the note "Vendored release builds of wordpress-activitypub and wordpress-atmosphere. Refreshed via tools/sync-bundled.sh. Do not edit by hand."
  2. Under "Commands", add a "Sync bundled backends" subsection documenting `./tools/sync-bundled.sh` and the `FOSSE_AP_SOURCE` / `FOSSE_ATMO_SOURCE` env vars.
  3. Under "Common Pitfalls", add an entry: "bundled/ is excluded from PHPCS/PHPUnit/ESLint/Prettier/Jest. Do not re-enable those checks for vendored code."
  4. Commit: `docs: document bundled backends + sync script`
- **Verify**:
  - `grep "bundled/" AGENTS.md` returns the new sections.
- **Depends on**: Task 7, Task 9
