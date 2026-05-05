# Deactivation Lifecycle Implementation Plan

Based on: `sdd/deactivation-lifecycle/spec.md`

## Progress

- [ ] Task 1: Add lifecycle uninstall cleanup tests
- [ ] Task 2: Implement FOSSE-only uninstall cleanup
- [ ] Task 3: Add deactivation handoff notice tests
- [ ] Task 4: Implement deactivation handoff notice
- [ ] Task 5: Add uninstall entrypoint
- [ ] Task 6: Extend e2e lifecycle/conflict coverage
- [ ] Task 7: Update SDD implementation notes
- [ ] Task 8: Run verification

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

### Task 3: Add deactivation handoff notice tests

- **Status**: Not started
- **Files**:
  - Create: `tests/php/Admin/Standalone_Handoff_NoticeTest.php`
- **Do**:
  1. Add tests for an admin notice class that renders a one-line confirmation when FOSSE was deactivated AND a standalone AP or Atmosphere plugin is currently active.
  2. Cover these states:
     - FOSSE was deactivated, standalone AP active → notice renders, references "ActivityPub plugin".
     - FOSSE was deactivated, standalone Atmosphere active → notice renders, references "Atmosphere plugin".
     - FOSSE was deactivated, both standalone plugins active → notice mentions both.
     - FOSSE was deactivated, neither standalone active → notice does NOT render.
     - FOSSE never deactivated (or notice already dismissed/seen) → notice does NOT render.
  3. The "FOSSE was deactivated" signal can be a transient set in a `register_deactivation_hook` callback that the notice consumes once and clears.
- **Verify**:
  - `composer run-script test-php -- --filter Standalone_Handoff_NoticeTest` fails because the notice class does not exist yet.
- **Depends on**: none

### Task 4: Implement deactivation handoff notice

- **Status**: Not started
- **Files**:
  - Create: `src/Admin/class-standalone-handoff-notice.php`
  - Modify: `fosse.php` (register the deactivation hook that sets the handoff transient)
  - Modify: `src/Admin/class-menu.php` (register the notice on `admin_notices`)
  - Modify: `tests/php/Admin/Standalone_Handoff_NoticeTest.php`
- **Do**:
  1. Add `Automattic\Fosse\Admin\Standalone_Handoff_Notice`.
  2. Register a `register_deactivation_hook` callback in `fosse.php` that sets a short-lived transient (`fosse_deactivation_handoff_pending`) marking that FOSSE was just deactivated.
  3. The notice class checks for that transient on `admin_notices`, inspects whether `ACTIVITYPUB_PLUGIN_VERSION` or `ATMOSPHERE_VERSION` is defined (i.e. a standalone backend is active), and if so renders one notice. The notice clears the transient once shown (one-shot UX).
  4. Notice content: "FOSSE deactivated. Federation will continue via the standalone <plugin name> plugin." Singular or plural based on which standalone backends are active.
  5. Restrict to users with `activate_plugins`.
  6. The notice lives at the global admin level (it surfaces on the next admin page load after deactivation, regardless of which screen the user lands on).
- **Verify**:
  - `composer run-script test-php -- --filter Standalone_Handoff_NoticeTest`
  - `composer run-script lint-php -- src/Admin/class-standalone-handoff-notice.php src/Admin/class-menu.php fosse.php`
- **Depends on**: Task 3

### Task 5: Add uninstall entrypoint

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
  7. Add `PluginLoadsTest` coverage that `uninstall.php` exists and contains the `WP_UNINSTALL_PLUGIN` guard.
- **Verify**:
  - `php -l uninstall.php`
  - `composer run-script test-php -- --filter 'LifecycleTest|PluginLoadsTest'`
  - `composer run-script lint-php -- uninstall.php tests/php/PluginLoadsTest.php`
- **Depends on**: Task 2

### Task 6: Extend e2e lifecycle/conflict coverage

- **Status**: Not started
- **Files**:
  - Modify: `tests/e2e/bundled-backends.spec.ts`
- **Do**:
  1. Keep the existing assertions that FOSSE hides native ActivityPub menu entries while active and direct backend settings URLs remain accessible.
  2. Add a no-fatal assertion for the Plugins screen after backend detection runs.
  3. Add a deactivation-handoff e2e: activate FOSSE → activate standalone AP → deactivate FOSSE → assert the handoff notice appears on the next admin page load and references the standalone plugin by name.
  4. Add a no-handoff-notice case: activate FOSSE → deactivate FOSSE without a standalone backend installed → assert no handoff notice surfaces.
- **Verify**:
  - `pnpm exec playwright test tests/e2e/bundled-backends.spec.ts`
- **Depends on**: Task 4

### Task 7: Update SDD implementation notes

- **Status**: Not started
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

- **Status**: Not started
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
