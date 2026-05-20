# Deactivation Lifecycle Implementation Plan

Based on: `sdd/deactivation-lifecycle/spec.md`

## Progress

- [x] Task 1: Add lifecycle uninstall cleanup tests
- [x] Task 2: Implement FOSSE-only uninstall cleanup
- [x] Task 3: Add Plugins-screen handoff row tests
- [x] Task 4: Implement Plugins-screen handoff row
- [x] Task 5: Add uninstall entrypoint
- [x] Task 6: Extend e2e lifecycle/conflict coverage
- [x] Task 7: Update SDD implementation notes
- [x] Task 8: Run verification

## Tasks

### Task 1: Add lifecycle uninstall cleanup tests

- **Status**: ✅ Done (this branch)
- **Files**:
  - Create: `tests/php/LifecycleTest.php`
- **Do**:
  1. Create a WorDBless test class for `Automattic\Fosse\Lifecycle`.
  2. Seed FOSSE-owned options: `fosse_object_type`, `fosse_long_form_strategy`, `fosse_onboarding_completed`, `fosse_onboarding_destination`, `fosse_activation_redirect`, `fosse_bundled_ap_bootstrapped`, `fosse_bundled_atmosphere_bootstrapped`, `fosse_canonical_options_migrated`, `fosse_metrics_consent`, `fosse_metrics_last_observed_at`, `fosse_metrics_first_observed_at`, `fosse_metrics_funnel`.
  3. Seed FOSSE-owned transients: `fosse_activation_redirect` and `fosse_bluesky_oauth_return_123`.
  4. Seed FOSSE-owned user meta: `_fosse_wizard_started_emitted` for a test user id.
  5. Seed upstream-owned options that must survive: `activitypub_actor_mode`, `activitypub_support_post_types`, `activitypub_blog_identifier`, `atmosphere_connection`, `atmosphere_auto_publish`.
  6. Call `Lifecycle::uninstall()`.
  7. Assert FOSSE-owned options/transients/user meta are gone and upstream-owned options retain exact seeded values.
  8. Add a second test that calls `Lifecycle::uninstall()` with no seeded FOSSE options and expects no warnings or errors.
- **Verify**:
  - `composer run-script test-php -- --filter LifecycleTest` fails because `Automattic\Fosse\Lifecycle` does not exist yet.
- **Depends on**: none

### Task 2: Implement FOSSE-only uninstall cleanup

- **Status**: ✅ Done (this branch)
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

### Task 3: Add Plugins-screen handoff row tests

- **Status**: ✅ Done (this branch)
- **Files**:
  - Create: `tests/php/Admin/Standalone_Handoff_NoticeTest.php`
- **Do**:
  1. Add tests for a class that renders a small descriptive row under FOSSE on the Plugins screen via `after_plugin_row_fosse/fosse.php`, telling the user what will happen if they deactivate FOSSE while a standalone backend is active.
  2. Cover these states:
     - Standalone AP active → row renders, references "ActivityPub" singular.
     - Standalone Atmosphere active → row renders, references "Atmosphere" singular.
     - Both standalone plugins active → row mentions both, joined naturally.
     - Neither standalone active → row does NOT render.
     - User without `activate_plugins` capability → row does NOT render.
  3. The class's render method takes the active-plugin list as an explicit argument so the test doesn't have to mutate WordPress' `active_plugins` option directly.
- **Verify**:
  - `composer run-script test-php -- --filter Standalone_Handoff_NoticeTest` fails because the class does not exist yet.
- **Depends on**: none

### Task 4: Implement Plugins-screen handoff row

- **Status**: ✅ Done (this branch)
- **Files**:
  - Create: `src/Admin/class-standalone-handoff-notice.php`
  - Modify: `src/Admin/class-menu.php` (register the row on `after_plugin_row_fosse/fosse.php`)
  - Modify: `tests/php/Admin/Standalone_Handoff_NoticeTest.php`
- **Do**:
  1. Add `Automattic\Fosse\Admin\Standalone_Handoff_Notice`.
  2. Register the row callback in `Menu::register()` on `after_plugin_row_<FOSSE basename>`. Use `plugin_basename( FOSSE plugin file )` so the hook name matches the actual install path; don't hard-code `fosse/fosse.php`.
  3. The callback runs `current_user_can( 'activate_plugins' )` first; bails silently otherwise.
  4. The callback inspects `is_plugin_active( 'activitypub/activitypub.php' )` and `is_plugin_active( 'atmosphere/atmosphere.php' )` to decide which standalones are active, and bails silently when neither is.
  5. Row content: "Federation will continue via the standalone ActivityPub plugin if you deactivate FOSSE." (singular) / "Federation will continue via the standalone Atmosphere plugin…" / "Federation will continue via the standalone ActivityPub and Atmosphere plugins…" (both).
  6. Render as a WordPress-standard `<tr class="plugin-update-tr active"><td colspan="N">…</td></tr>` row beneath FOSSE.
  7. Skip the original `register_deactivation_hook` transient design; it can't reach a next-request render and was struck from the spec. Document the design pivot in `sdd/deactivation-lifecycle/implementation-notes.md` (Task 7).
- **Verify**:
  - `composer run-script test-php -- --filter Standalone_Handoff_NoticeTest`
  - `composer run-script lint-php -- src/Admin/class-standalone-handoff-notice.php src/Admin/class-menu.php`
- **Depends on**: Task 3

### Task 5: Add uninstall entrypoint

- **Status**: ✅ Done (this branch)
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
  7. Add `PluginLoadsTest` coverage that `uninstall.php` exists and contains the `WP_UNINSTALL_PLUGIN` guard.
- **Verify**:
  - `php -l uninstall.php`
  - `composer run-script test-php -- --filter 'LifecycleTest|PluginLoadsTest'`
  - `composer run-script lint-php -- uninstall.php tests/php/PluginLoadsTest.php`
- **Depends on**: Task 2

### Task 6: Extend e2e lifecycle/conflict coverage

- **Status**: ✅ Done (this branch)
- **Files**:
  - Modify: `tests/e2e/bundled-backends.spec.ts`
- **Do**:
  1. Keep the existing assertions that FOSSE hides native ActivityPub menu entries while active and direct backend settings URLs remain accessible.
  2. Add a no-fatal assertion for the Plugins screen after backend detection runs.
  3. Add a Plugins-screen handoff row case (best-effort under Playground — skip if a standalone AP/Atmo install isn't reachable in the blueprint): activate FOSSE with standalone AP active → assert the handoff row appears under FOSSE on the Plugins screen and references "ActivityPub" by name.
  4. Add a no-handoff-row case: activate FOSSE without a standalone backend → assert no handoff row surfaces under the FOSSE plugin row.
- **Verify**:
  - `pnpm exec playwright test tests/e2e/bundled-backends.spec.ts`
- **Depends on**: Task 4

### Task 7: Update SDD implementation notes

- **Status**: ✅ Done (this branch)
- **Files**:
  - Create: `sdd/deactivation-lifecycle/implementation-notes.md`
  - Modify: `sdd/deactivation-lifecycle/plan.md`
- **Do**:
  1. Record any implementation deviations.
  2. Update task statuses in this plan as each task ships, using the AGENTS.md Done status value with a commit or PR reference.
  3. Keep the top `## Progress` checklist in sync with per-task statuses.
- **Verify**:
  - `sdd/deactivation-lifecycle/implementation-notes.md` exists after implementation starts.
  - `sdd/deactivation-lifecycle/plan.md` has synchronized progress/status entries.
- **Depends on**: implementation tasks as they complete

### Task 8: Run verification

- **Status**: ✅ Done (this branch)
- **Files**:
  - No new files
- **Do**:
  1. Run targeted PHPUnit while developing:
     - `composer run-script test-php -- --filter LifecycleTest`
     - `composer run-script test-php -- --filter Standalone_Handoff_NoticeTest`
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
- **Depends on**: Tasks 1-7
