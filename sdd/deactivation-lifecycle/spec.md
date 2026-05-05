# Spec: Deactivation Lifecycle

## Goal

Make FOSSE safe to deactivate, delete, and run alongside standalone ActivityPub / Atmosphere installs without surprising data loss or PHP fatals. The guiding rule is: FOSSE cleans up FOSSE-owned state, while upstream federation settings remain owned by ActivityPub and Atmosphere even when FOSSE wrote to them.

## Decisions

### 1. Deactivation Is A No-Op For Data

FOSSE will not add a data-cleaning deactivation hook in v1.

When FOSSE is deactivated, WordPress stops loading `fosse.php`. That naturally removes:

- The FOSSE admin menu.
- FOSSE's suppression of native ActivityPub / Atmosphere menus.
- FOSSE projector filters (`fosse_object_type`, `fosse_long_form_strategy`, AP post-type projection into Atmosphere).
- FOSSE provider hooks and OAuth redirect override.

No options are deleted on deactivation. If standalone ActivityPub and/or Atmosphere are active, their native menus reappear and continue reading the same `activitypub_*` and `atmosphere_*` options FOSSE edited. If the site only used FOSSE's bundled copies, those backends stop loading because they are not separate active WordPress plugins; their options remain for later reactivation or standalone handoff.

### 2. Uninstall Deletes FOSSE-Owned State Only

Deleting FOSSE will remove FOSSE-owned lifecycle, projector, and temporary state:

- `fosse_object_type`
- `fosse_long_form_strategy`
- `fosse_onboarding_completed`
- `fosse_activation_redirect`
- `fosse_bundled_ap_bootstrapped`
- `fosse_bundled_atmosphere_bootstrapped`
- `fosse_metrics_consent`
- `fosse_metrics_last_observed_at`
- `fosse_metrics_first_observed_at`
- `fosse_metrics_funnel`
- Legacy transient storage for `fosse_activation_redirect`
- Per-user OAuth return-context transients with prefix `fosse_bluesky_oauth_return_`
- Future FOSSE user meta keys, if any are introduced before implementation

Deleting FOSSE will not remove:

- `activitypub_actor_mode`
- `activitypub_support_post_types`
- `activitypub_blog_identifier`
- Any other `activitypub_*` option, transient, post meta, or user meta
- `atmosphere_connection`
- `atmosphere_auto_publish`
- Any other `atmosphere_*` option, transient, post meta, or user meta
- Posts, comments, actors, remote records, or custom post types created by ActivityPub or Atmosphere

Rationale: FOSSE intentionally writes AP/Atmosphere's canonical options directly. That makes the standalone handoff simple, but it also means FOSSE uninstall must not treat those options as disposable FOSSE state.

### 3. Use `uninstall.php` Plus A Small Lifecycle Class

Use a root `uninstall.php` so cleanup runs when WordPress deletes the plugin even if FOSSE is inactive. The file should:

1. Guard on `defined( 'WP_UNINSTALL_PLUGIN' )`.
2. Load `vendor/autoload_packages.php` when present.
3. Call `Automattic\Fosse\Lifecycle::uninstall()` when the class is available.
4. Fall back to a minimal procedural cleanup list if the autoloader is unavailable, so release packaging mistakes do not leave known FOSSE options behind.

The reusable logic lives in `src/class-lifecycle.php` so PHPUnit can call it directly without simulating WordPress's plugin deletion flow.

### 4. Standalone Backend Detection Stays Conservative

Keep the current `fosse.php` behavior:

- If `ACTIVITYPUB_PLUGIN_VERSION` is defined, do not load `bundled/activitypub/activitypub.php`.
- If `WP_PLUGIN_DIR/activitypub/activitypub.php` exists, do not load `bundled/activitypub/activitypub.php`.
- If `ATMOSPHERE_VERSION` is defined, do not load `bundled/atmosphere/atmosphere.php`.
- If `WP_PLUGIN_DIR/atmosphere/atmosphere.php` exists, do not load `bundled/atmosphere/atmosphere.php`.

This yields to standalone installs before activation and prevents WordPress's `plugin_sandbox_scrape()` from requiring a standalone plugin into the same request after FOSSE already loaded a bundled copy.

The implementation must preserve FOSSE's current bare-clone degradation behavior. Today, missing Composer autoload does not stop the bundled backend load checks from running. If detection moves into a helper class, `fosse.php` must either require that helper file directly before using it or fall back to equivalent inline checks when the class is unavailable.

### 5. Add Admin Notices For Inactive Standalone Backends

The filesystem skip avoids class redeclare fatals, but it can leave a confusing state: a standalone backend exists on disk, is not active, and FOSSE therefore skips its bundled copy. In that state the provider is unavailable and FOSSE should explain why.

V1 adds an admin notice when all of the following are true for a backend:

- The standalone plugin file exists at its canonical path.
- The corresponding standalone sentinel constant is not defined.
- FOSSE did not load the bundled copy.
- The current user can `activate_plugins`.

Notice behavior:

- Show on `plugins.php`, FOSSE Setup, FOSSE Wizard, and FOSSE Status screens.
- Use a warning notice, not an error, because activation or removal is an admin choice.
- Include the backend name and exact action: activate the standalone plugin, or remove it so FOSSE can load its bundled backend.
- If `is_plugin_active()` is available and confirms the standalone plugin is active but the sentinel is still missing, show a stronger "backend failed to load" message.

This is FOSSE-owned admin UX and does not require upstream hooks.

### 6. Standalone-Active Is Not A Conflict

When standalone ActivityPub or Atmosphere is active and successfully loaded, FOSSE treats it as the backend. FOSSE's admin UI can remain the unified surface while FOSSE is active, and the native menus reappear when FOSSE is deactivated. No data migration is needed because option keys are upstream-identical.

### 7. Per-Provider Disable Is Deferred

V1 does not add a generic provider disable toggle such as `fosse_bluesky_enabled` or `fosse_activitypub_enabled`.

For Bluesky, v1 already offers two practical controls:

- Uncheck **Auto-publish** to stop automatic Bluesky publishing while keeping the connection.
- Use **Disconnect Bluesky** to remove the Atmosphere connection and stop account-backed publishing.

For ActivityPub, v1 does not add a FOSSE-level off switch. Users can change AP's supported post types or deactivate FOSSE / standalone AP depending on the desired scope. A future provider-control SDD can add first-class provider enablement if product wants a single "turn this network off" affordance.

## Lifecycle Matrix

| Scenario | V1 Behavior |
| --- | --- |
| FOSSE active, no standalone AP/Atmosphere files | FOSSE loads bundled backends, bootstraps upstream activation side effects once per version, hides native menus, shows FOSSE UI. |
| FOSSE active, standalone AP/Atmosphere active | FOSSE skips bundled copy, uses standalone backend APIs/options, hides native menus while active, keeps direct URL access. |
| FOSSE active, standalone AP/Atmosphere files present but inactive | FOSSE skips bundled copy to avoid future redeclare fatal, provider may be unavailable, admin notice tells user to activate or remove standalone backend. |
| FOSSE deactivated, standalone AP/Atmosphere active | FOSSE menus and suppressions disappear; native backend menus reappear; upstream options keep last configured values. |
| FOSSE deactivated, no standalone AP/Atmosphere active | Bundled backends stop loading; FOSSE and native backend menus are absent; stored options remain. |
| FOSSE deleted/uninstalled | FOSSE-owned state is deleted; AP/Atmosphere options and credentials remain. |
| User installs standalone backend after FOSSE | Existing filesystem skip prevents bundled load collisions; new notice handles inactive/ambiguous state. |

## Data Ownership

| Key / Prefix | Owner | Uninstall Action |
| --- | --- | --- |
| `fosse_object_type` | FOSSE projector | Delete |
| `fosse_long_form_strategy` | FOSSE projector | Delete |
| `fosse_onboarding_completed` | FOSSE wizard | Delete |
| `fosse_activation_redirect` option/transient | FOSSE activation redirect | Delete |
| `fosse_bundled_ap_bootstrapped` | FOSSE bundled activation shim | Delete |
| `fosse_bundled_atmosphere_bootstrapped` | FOSSE bundled activation shim | Delete |
| `fosse_metrics_consent` | FOSSE metrics consent | Delete |
| `fosse_metrics_last_observed_at` | FOSSE metrics daily observation guard | Delete |
| `fosse_metrics_first_observed_at` | FOSSE metrics first-observed timestamp | Delete |
| `fosse_metrics_funnel` | FOSSE metrics first-post milestones | Delete |
| `fosse_bluesky_oauth_return_*` transients | FOSSE wizard return context | Delete |
| `activitypub_*` | ActivityPub | Preserve |
| `atmosphere_*` | Atmosphere | Preserve |

## Implementation Notes

- Do not edit `bundled/`.
- Do not introduce `fosse_ap_*` shadow settings.
- Any future FOSSE-owned option introduced by another SDD must update this lifecycle cleanup list before that feature ships.
- Keep cleanup code defensive: uninstall can run with a partial autoload environment.
- Keep bundled-load detection defensive: missing Composer autoload must not make FOSSE skip both bundled backends silently.
- Wildcard transient deletion will require deleting both `_transient_fosse_bluesky_oauth_return_%` and `_transient_timeout_fosse_bluesky_oauth_return_%` rows from the options table. Use `$wpdb->esc_like()` and `prepare()`.
- Multisite v1 can clean the current site's options only, matching FOSSE's current single-site posture from the bundled-backends implementation notes. If FOSSE later adds first-class multisite support, network uninstall cleanup should be revisited.

## Tests

Required test coverage:

- `tests/php/LifecycleTest.php`: `Lifecycle::uninstall()` deletes only FOSSE-owned options/transients and preserves seeded `activitypub_*` / `atmosphere_*` values.
- `tests/php/LifecycleTest.php`: uninstall cleanup handles missing FOSSE options without warnings.
- `tests/php/Admin/Standalone_Backend_NoticeTest.php`: notices render for inactive standalone backend files and do not render when the backend is active or absent.
- `tests/php/Bundled/Standalone_Backend_StatusTest.php`: backend status helper distinguishes bundled-loaded, standalone-active, standalone-present-inactive, and absent states.
- `tests/e2e/bundled-backends.spec.ts`: extend existing coverage to assert no fatal banner appears on backend pages and that native menu suppression remains unchanged while FOSSE is active.

## Open Questions

- Should FOSSE eventually provide a single provider enable/disable control per network? Deferred out of v1 because Bluesky already has auto-publish and disconnect controls, and ActivityPub disable semantics need a product decision.
- Should uninstall cleanup become network-wide on multisite? Deferred until FOSSE supports multisite as a first-class target.
