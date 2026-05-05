# Deactivation Lifecycle Implementation Plan

Based on: `sdd/deactivation-lifecycle/spec.md`

## Progress

- [ ] Task 1: Add lifecycle uninstall cleanup tests
- [ ] Task 2: Implement FOSSE-only uninstall cleanup
- [ ] Task 3: Add backend status helper tests
- [ ] Task 4: Refactor bundled backend detection through helper
- [ ] Task 5: Add inactive standalone backend notice tests
- [ ] Task 6: Implement inactive standalone backend admin notices
- [ ] Task 7: Add uninstall entrypoint
- [ ] Task 8: Extend e2e lifecycle/conflict coverage
- [ ] Task 9: Update SDD implementation notes
- [ ] Task 10: Run verification

## Tasks

### Task 1: Add lifecycle uninstall cleanup tests

- **Status**: Not started
- **Files**:
  - Create: `tests/php/LifecycleTest.php`
- **Do**:
  1. Create a WorDBless test class for `Automattic\Fosse\Lifecycle`.
  2. Seed FOSSE-owned options: `fosse_object_type`, `fosse_long_form_strategy`, `fosse_onboarding_completed`, `fosse_activation_redirect`, `fosse_bundled_ap_bootstrapped`, `fosse_bundled_atmosphere_bootstrapped`, `fosse_metrics_consent`, `fosse_metrics_last_observed_at`, `fosse_metrics_first_observed_at`, `fosse_metrics_funnel`.
  3. Seed FOSSE-owned transients: `fosse_activation_redirect` and `fosse_bluesky_oauth_return_123`.
  4. Seed upstream-owned options that must survive: `activitypub_actor_mode`, `activitypub_support_post_types`, `activitypub_blog_identifier`, `atmosphere_connection`, `atmosphere_auto_publish`.
  5. Call `Lifecycle::uninstall()`.
  6. Assert FOSSE-owned options/transients are gone and upstream-owned options retain exact seeded values.
  7. Add a second test that calls `Lifecycle::uninstall()` with no seeded FOSSE options and expects no warnings or errors.
- **Verify**:
  - `composer run-script test-php -- --filter LifecycleTest` fails because `Automattic\Fosse\Lifecycle` does not exist yet.
- **Depends on**: none

### Task 2: Implement FOSSE-only uninstall cleanup

- **Status**: Not started
- **Files**:
  - Create: `src/class-lifecycle.php`
  - Modify: `tests/php/LifecycleTest.php`
- **Do**:
  1. Add `Automattic\Fosse\Lifecycle` with `public static function uninstall(): void`.
  2. Add private constant arrays for owned option keys and owned transient prefixes.
  3. Delete exact FOSSE-owned options with `delete_option()`.
  4. Delete exact FOSSE-owned transients with `delete_transient()`.
  5. Delete wildcard FOSSE transient rows for `fosse_bluesky_oauth_return_` using `$wpdb` and escaped `LIKE` patterns for both `_transient_` and `_transient_timeout_`.
  6. Do not call any ActivityPub or Atmosphere uninstall routines.
  7. Do not delete any `activitypub_*` or `atmosphere_*` key.
- **Verify**:
  - `composer dump-autoload`
  - `composer run-script test-php -- --filter LifecycleTest`
  - `composer run-script lint-php -- src/class-lifecycle.php tests/php/LifecycleTest.php`
- **Depends on**: Task 1

### Task 3: Add backend status helper tests

- **Status**: Not started
- **Files**:
  - Create: `tests/php/Bundled/Standalone_Backend_StatusTest.php`
- **Do**:
  1. Add tests for a helper that accepts backend definitions and returns state for ActivityPub and Atmosphere.
  2. Cover these states:
     - Standalone constant defined: state is `standalone-active`, bundled should not load, no inactive notice.
     - Canonical standalone plugin file exists but constant missing: state is `standalone-present-inactive`, bundled should not load, inactive notice should render.
     - No standalone file and bundled file exists: state is `bundled-available`, bundled should load.
     - Neither standalone nor bundled file exists: state is `missing`, bundled should not load, unavailable behavior remains.
  3. Use injectable paths/constants in the helper so tests do not create or delete real plugin files under `WP_PLUGIN_DIR`.
- **Verify**:
  - `composer run-script test-php -- --filter Standalone_Backend_StatusTest` fails because the helper does not exist yet.
- **Depends on**: none

### Task 4: Refactor bundled backend detection through helper

- **Status**: Not started
- **Files**:
  - Create: `src/Bundled/class-standalone-backend-status.php`
  - Modify: `fosse.php`
  - Modify: `tests/php/Bundled/Standalone_Backend_StatusTest.php`
- **Do**:
  1. Add `Automattic\Fosse\Bundled\Standalone_Backend_Status`.
  2. Define backend metadata for ActivityPub and Atmosphere: name, standalone plugin basename, sentinel constant, bundled file path.
  3. Expose `should_load_bundled( string $backend ): bool` and `get_state( string $backend ): string`.
  4. In `fosse.php`, make the helper available even when Composer autoload is missing: directly `require_once __DIR__ . '/src/Bundled/class-standalone-backend-status.php'` if the file exists and the class is not already loaded.
  5. Replace the inline `$fosse_standalone_ap_present` and `$fosse_standalone_atmo_present` checks in `fosse.php` with calls to the helper.
  6. Preserve the existing behavior exactly: a defined sentinel constant or a canonical standalone plugin file on disk means FOSSE skips the bundled copy.
  7. Keep `$fosse_loaded_bundled_ap` and `$fosse_loaded_bundled_atmo` booleans so `Bundled\Bootstrap::maybe_run()` still only runs for bundled copies.
  8. Add a test case proving backend detection still answers correctly when the helper is loaded directly rather than through Composer autoload.
- **Verify**:
  - `composer dump-autoload`
  - `composer run-script test-php -- --filter Standalone_Backend_StatusTest`
  - `composer run-script test-php -- --filter 'BootstrapTest|PluginLoadsTest'`
  - `composer run-script lint-php -- fosse.php src/Bundled/class-standalone-backend-status.php tests/php/Bundled/Standalone_Backend_StatusTest.php`
- **Depends on**: Task 3

### Task 5: Add inactive standalone backend notice tests

- **Status**: Not started
- **Files**:
  - Create: `tests/php/Admin/Standalone_Backend_NoticeTest.php`
  - Modify: `tests/php/Admin/MenuTest.php`
- **Do**:
  1. Add tests for an admin notice class that renders a warning when a standalone backend file exists but the backend sentinel constant is not defined.
  2. Assert the notice includes the backend display name and tells the user to activate the standalone plugin or remove it so FOSSE can use the bundled backend.
  3. Assert no notice renders when the backend is `standalone-active`, `bundled-available`, or `missing`.
  4. Assert users without `activate_plugins` do not see the notice.
  5. Add a `MenuTest` assertion that `Menu::register()` hooks the notice class on `admin_notices`.
- **Verify**:
  - `composer run-script test-php -- --filter 'Standalone_Backend_NoticeTest|MenuTest'` fails for the missing notice class/hook.
- **Depends on**: Task 4

### Task 6: Implement inactive standalone backend admin notices

- **Status**: Not started
- **Files**:
  - Create: `src/Admin/class-standalone-backend-notice.php`
  - Modify: `src/Admin/class-menu.php`
  - Modify: `tests/php/Admin/Standalone_Backend_NoticeTest.php`
- **Do**:
  1. Add `Automattic\Fosse\Admin\Standalone_Backend_Notice`.
  2. Implement `register()` to hook `admin_notices`.
  3. Implement `render()` to inspect ActivityPub and Atmosphere backend states via `Bundled\Standalone_Backend_Status`.
  4. Restrict rendering to `plugins.php`, `toplevel_page_fosse`, `fosse_page_fosse-status`, and `admin_page_fosse-wizard`.
  5. Require `current_user_can( 'activate_plugins' )`.
  6. Render one warning notice listing all inactive standalone backends detected in the current request.
  7. Register the notice from `Menu::register()` so it runs only in wp-admin while FOSSE is active.
- **Verify**:
  - `composer run-script test-php -- --filter 'Standalone_Backend_NoticeTest|MenuTest'`
  - `composer run-script lint-php -- src/Admin/class-standalone-backend-notice.php src/Admin/class-menu.php tests/php/Admin/Standalone_Backend_NoticeTest.php`
- **Depends on**: Task 5

### Task 7: Add uninstall entrypoint

- **Status**: Not started
- **Files**:
  - Create: `uninstall.php`
  - Modify: `tests/php/PluginLoadsTest.php`
- **Do**:
  1. Create root `uninstall.php`.
  2. Guard with `if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }`.
  3. Load `vendor/autoload_packages.php` when present.
  4. If `Automattic\Fosse\Lifecycle` exists, call `Lifecycle::uninstall()` and return.
  5. Add a procedural fallback that deletes the exact FOSSE-owned options and transients listed in the spec.
  6. In the procedural fallback, also delete wildcard `fosse_bluesky_oauth_return_` transient and timeout rows, matching `Lifecycle::uninstall()`.
  6. Add `PluginLoadsTest` coverage that `uninstall.php` exists and contains the `WP_UNINSTALL_PLUGIN` guard.
- **Verify**:
  - `php -l uninstall.php`
  - `composer run-script test-php -- --filter 'LifecycleTest|PluginLoadsTest'`
  - `composer run-script lint-php -- uninstall.php tests/php/PluginLoadsTest.php`
- **Depends on**: Task 2

### Task 8: Extend e2e lifecycle/conflict coverage

- **Status**: Not started
- **Files**:
  - Modify: `tests/e2e/bundled-backends.spec.ts`
  - Create: `tests/e2e/mu-plugins/fosse-standalone-conflict-seed.php` if a fixture is needed
- **Do**:
  1. Keep the existing assertions that FOSSE hides native ActivityPub menu entries while active and direct backend settings URLs remain accessible.
  2. Add a no-fatal assertion for the Plugins screen after backend detection changes.
  3. If Playwright can create a safe fake inactive standalone file inside the Playground mount, add coverage that the inactive standalone notice appears. If the Playground filesystem cannot model this cleanly, document the gap in `implementation-notes.md` and rely on PHPUnit for the notice state machine.
- **Verify**:
  - `pnpm exec playwright test tests/e2e/bundled-backends.spec.ts`
  - If a fixture is added, run `pnpm run format:check` and `pnpm run lint`.
- **Depends on**: Task 6

### Task 9: Update SDD implementation notes

- **Status**: Not started
- **Files**:
  - Create: `sdd/deactivation-lifecycle/implementation-notes.md`
  - Modify: `sdd/deactivation-lifecycle/plan.md`
- **Do**:
  1. Record any implementation deviations, especially if e2e cannot model inactive standalone plugin files.
  2. Update task statuses in this plan as each task ships, using the AGENTS.md Done status value with a commit or PR reference.
  3. Keep the top `## Progress` checklist in sync with per-task statuses.
- **Verify**:
  - `sdd/deactivation-lifecycle/implementation-notes.md` exists after implementation starts.
  - `sdd/deactivation-lifecycle/plan.md` has synchronized progress/status entries.
- **Depends on**: implementation tasks as they complete

### Task 10: Run verification

- **Status**: Not started
- **Files**:
  - No new files
- **Do**:
  1. Run targeted PHPUnit while developing:
     - `composer run-script test-php -- --filter LifecycleTest`
     - `composer run-script test-php -- --filter Standalone_Backend_StatusTest`
     - `composer run-script test-php -- --filter Standalone_Backend_NoticeTest`
  2. Run broader PHP coverage:
     - `composer run-script test-php`
  3. Run required lint before push:
     - `composer run-script lint-php`
     - `pnpm run format:check`
     - `pnpm run lint`
  4. Run targeted e2e:
     - `pnpm exec playwright test tests/e2e/bundled-backends.spec.ts`
- **Verify**:
  - All commands pass, or failures are recorded in `sdd/deactivation-lifecycle/implementation-notes.md` with exact command output and next steps.
- **Depends on**: Tasks 1-9
