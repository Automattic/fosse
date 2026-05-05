# Deactivation Lifecycle - Requirements

## Goal

Define FOSSE's WordPress lifecycle behavior for deactivation, uninstall/deletion, and coexistence with standalone ActivityPub / Atmosphere installs. The implementation should preserve user federation configuration, remove only FOSSE-owned state on uninstall, and prevent standalone-plugin installs from turning into class redeclare fatals.

## Linear Issue

- **DOTCOM-16865** - Deactivation & deletion handling for FOSSE and bundled plugins

## Current State

- `fosse.php` programmatically loads bundled ActivityPub and Atmosphere from `bundled/` unless the standalone plugin constant is already defined or the canonical standalone plugin file exists under `WP_PLUGIN_DIR`.
- Bundled backend first-run activation is bridged by `Automattic\Fosse\Bundled\Bootstrap::maybe_run()` on `init`, keyed by `fosse_bundled_ap_bootstrapped` and `fosse_bundled_atmosphere_bootstrapped`.
- `src/Admin/class-menu.php` hides native ActivityPub and Atmosphere admin menu entries only while FOSSE is active. Native settings pages remain registered and reachable by direct URL.
- FOSSE's setup and wizard flows now write directly to upstream option keys such as `activitypub_actor_mode`, `activitypub_support_post_types`, `activitypub_blog_identifier`, and `atmosphere_auto_publish`. Earlier `fosse_ap_*` shadow option projection was rejected under DOTCOM-16875.
- FOSSE still owns projector and lifecycle options: `fosse_object_type`, `fosse_long_form_strategy`, `fosse_onboarding_completed`, `fosse_activation_redirect`, `fosse_bundled_ap_bootstrapped`, and `fosse_bundled_atmosphere_bootstrapped`.
- The usage metrics SDD adds FOSSE-owned options such as `fosse_metrics_consent`, `fosse_metrics_last_observed_at`, `fosse_metrics_first_observed_at`, and `fosse_metrics_funnel`; lifecycle cleanup must include them if that SDD ships before or alongside this one.
- FOSSE owns a per-user OAuth return-context transient prefix: `fosse_bluesky_oauth_return_`.
- No FOSSE user meta exists today.
- There is currently no deactivation hook, uninstall hook, `uninstall.php`, or standalone conflict notice.

## Requirements

1. **Deactivation preserves configuration.** Deactivating FOSSE must not delete FOSSE-owned options or upstream ActivityPub / Atmosphere options. Deactivation is reversible.
2. **Deactivation restores native admin surfaces and surfaces the handoff.** When FOSSE stops running, its menus and menu-suppression hooks disappear. If standalone ActivityPub and/or Atmosphere are active as normal WordPress plugins, their native menus reappear with their last configured options. A one-line confirmation notice surfaces on the next admin page load — "FOSSE deactivated. Federation will continue via the standalone ActivityPub/Atmosphere plugin." — so the handoff is legible. No notice fires when no standalone backend is active (silent deactivation is fine).
3. **Bundled backends are not separate active plugins.** If a site only had FOSSE's bundled copies and then deactivates FOSSE, ActivityPub and Atmosphere do not continue loading. Their options remain in the database so a later FOSSE reactivation or standalone install can pick up the last configuration.
4. **Uninstall cleans only FOSSE-owned state.** Deleting FOSSE should remove FOSSE-owned options, transients, metrics options, and future FOSSE user meta, but must not delete ActivityPub or Atmosphere options that represent user configuration or credentials.
5. **Upstream federation configuration is preserved.** Preserve `activitypub_*` and `atmosphere_*` options, including AP actor/post-type settings, AP blog identifier, Atmosphere connection data, Atmosphere auto-publish, upstream bootstrap state, and upstream OAuth transients. FOSSE wrote some of these values, but they are canonical upstream plugin settings.
6. **Standalone-before-FOSSE remains supported.** The existing skip-when-standalone checks must remain intact: active standalone plugins or canonical plugin files under `WP_PLUGIN_DIR/activitypub/activitypub.php` and `WP_PLUGIN_DIR/atmosphere/atmosphere.php` make FOSSE yield to the standalone backend.
7. **Standalone-after-FOSSE avoids fatals.** If a user installs standalone ActivityPub or Atmosphere after FOSSE, FOSSE must not load a bundled copy that would collide with the standalone code. The existing filesystem-skip behavior handles this silently. FOSSE does NOT show admin notices about inactive standalone plugin files — inactive plugins do nothing, so warning the user about them solves no real problem.
8. **No edits to `bundled/`.** Any lifecycle hook needed inside ActivityPub or Atmosphere must go upstream first, then be consumed through `tools/sync-bundled.sh`.
9. **Respect WordPress lifecycle semantics.** Deactivation is not uninstall. Cleanup belongs in uninstall/deletion handling only. The cleanup path must be safe when FOSSE is inactive and WordPress calls uninstall logic in a minimal plugin context.
10. **Bluesky disable affordance is explicit for v1.** If per-provider enable/disable is deferred, v1 must still tell users how to stop Bluesky publishing without deleting FOSSE.
11. **wp.com Simple uses sticker removal, not deactivation.** On wp.com Simple, FOSSE has no Plugins-screen presence; the load gate is the `enable-fosse` blog sticker. v1 must work correctly when the sticker is removed (load stops, data persists in DB) without invoking `uninstall.php`. `Lifecycle::uninstall()` must remain callable directly so out-of-band cleanup tooling can run on platforms where the standard WP plugin lifecycle doesn't fire.

## Non-Requirements

- Do not migrate ActivityPub or Atmosphere data into FOSSE-owned shadow options.
- Do not remove or rewrite upstream plugin data on FOSSE uninstall.
- Do not hand-edit `bundled/activitypub/` or `bundled/atmosphere/`.
- Do not implement a general provider enable/disable registry in this lifecycle SDD unless it is required for safe uninstall or conflict handling.
- Do not make FOSSE responsible for uninstalling standalone ActivityPub or Atmosphere plugins.

## Source Material / Code Inspected

- `fosse.php` - bundled backend loading, first-load bootstrap, activation redirect signal, provider boot, admin menu registration.
- `src/Admin/class-menu.php` - FOSSE menu registration, bundled menu suppression, activation redirect, notice suppression.
- `src/class-provider-loader.php` - provider registration lifecycle.
- `src/Bundled/class-bootstrap.php` - version-keyed bundled backend activation shim.
- `src/Admin/class-ap-provider.php` - direct writes to ActivityPub options and native AP settings link.
- `src/Admin/class-bluesky-provider.php` - direct Atmosphere option usage, OAuth return-context transient, connect/disconnect flow.
- `src/Admin/class-onboarding-wizard.php` - FOSSE-owned wizard options and direct AP option writes.
- `src/class-object-type.php` - `fosse_object_type` projector.
- `src/class-long-form-strategy.php` - `fosse_long_form_strategy` projector.
- `src/class-post-types.php` - ActivityPub option as post-type source of truth.
- `tests/php/Admin/MenuTest.php`, `tests/php/Bundled/BootstrapTest.php`, `tests/e2e/bundled-backends.spec.ts` - current lifecycle and menu coverage.
- `sdd/bundled-backends/implementation-notes.md` - existing decision to skip bundled load when standalone plugin files are present on disk to avoid activation-time redeclare fatals.
