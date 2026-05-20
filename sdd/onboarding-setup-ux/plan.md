# Implementation Plan: Onboarding & Setup UX

Based on: sdd/onboarding-setup-ux/spec.md

## Progress

- [x] Task 1: Create Connection_Provider interface + Registry
- [x] Task 2: Create Menu.php + wire up in fosse.php
- [x] Task 3: Create AP_Provider + Setup_Page shell
- [x] Task 4: Open upstream Atmosphere PRs
- [x] Task 5: Create Bluesky_Provider
- [x] Task 5.5: Create first-run onboarding wizard
- [x] Task 5.6: Replace Bluesky wizard placeholder with live connect form
- [x] Task 6: Create Status_Page with provider status cards
- [x] Task 7: Add admin CSS
- [x] Task 8: Write tests
- [x] Task 9: Exclude `src/` from WordPress.Files.FileName PHPCS sniff
- [x] Task 10: Update SDD documentation

## Tasks

### Task 1: Create Connection_Provider interface + Registry
- **Status**: ✅ Done (#27)
- **Files**: `src/Admin/Connection_Provider.php`, `src/Admin/Connection_Provider_Registry.php`
- **Do**:
  1. Create `Connection_Provider` interface with methods: `get_slug()`, `get_name()`, `is_available()`, `get_status()`, `render_setup_section()`, `render_status_card()`, `register_hooks()`.
  2. Create `Connection_Provider_Registry` with static `register()`, `get_providers()`, `get_provider()`, `reset()` methods. Duplicate slugs silently ignored (first wins).
  3. Run `composer dump-autoload` to pick up new files in classmap.
- **Verify**:
  - `composer dump-autoload` succeeds.
  - `composer run-script lint-php` passes on new files.
- **Depends on**: none

### Task 2: Create Menu.php + wire up in fosse.php
- **Status**: ✅ Done (#27)
- **Files**: `src/Admin/Menu.php`, `fosse.php`
- **Do**:
  1. Create `Menu` class with `register()` static method that fires `fosse_register_providers`, calls `register_hooks()` on available providers, hooks `admin_menu` at priority 9 (register menu) and 99 (hide bundled menus), and hooks `admin_enqueue_scripts` for CSS.
  2. `add_menu_page('fosse')` with `dashicons-share` icon. Two submenu pages: Setup (slug `fosse`) and Status (slug `fosse-status`).
  3. At priority 99, hide all bundled-plugin admin entries:
     - `remove_submenu_page('options-general.php', 'activitypub')` — AP Settings submenu.
     - `remove_submenu_page('options-general.php', 'atmosphere')` — Atmosphere Settings submenu.
     - `remove_menu_page('activitypub-social-web')` — AP top-level Dashboard page (gated by `activitypub_reader_ui`).
     - `remove_submenu_page('users.php', 'activitypub-followers-list')` — AP Users submenu.
     - `remove_submenu_page('users.php', 'activitypub-following-list')` — AP Users submenu.
     Pages remain registered, so direct-URL access still works for power users.
  4. Add `is_admin()` block to `fosse.php` that inits providers and calls `Menu::register()`.
- **Verify**:
  - `php -l fosse.php` succeeds.
  - `composer run-script lint-php` passes.
  - Playground: no AP or Atmosphere entries appear in Settings, Users, or the top-level menu.
- **Depends on**: Task 1

### Task 3: Create AP_Provider + Setup_Page shell
- **Status**: ✅ Done (#27)
- **Files**: `src/Admin/AP_Provider.php`, `src/Admin/Setup_Page.php`, `src/Admin/templates/setup-page.php`
- **Do**:
  1. Create `AP_Provider` implementing `Connection_Provider`. Self-registers on `fosse_register_providers` with `class_exists('\Activitypub\Activitypub')` guard.
  2. Setup section renders inline config: actor mode radio, post type checkboxes, fediverse address, link to advanced AP settings. Form POSTs to `admin_post.php?action=fosse_save_ap_settings`.
  3. `handle_save()` validates nonce + capability, sanitizes against allowlists, stores values directly to `activitypub_actor_mode` / `activitypub_support_post_types` via `update_option()`. AP's own settings screen keeps editing the same option keys; both surfaces share one source of truth.
  4. No `pre_option_*` projection filters. Cross-network post-type sync into Atmosphere is handled separately by `Automattic\Fosse\Post_Types` (see `sdd/post-type-sync/`), so AP_Provider only writes the option and leaves the projector to pick it up.
  5. Status card shows actor mode, post types, follower count (if available), fediverse address.
  6. Create `Setup_Page` that iterates providers and calls `render_setup_section()`. Handles notice transients.
  7. Create `setup-page.php` template with page shell, notice display, and provider iteration.
- **Verify**:
  - `composer run-script lint-php` passes.
  - Playground: FOSSE > Setup page renders with AP section.
- **Depends on**: Task 2

### Task 4: Open upstream Atmosphere PRs
- **Status**: ✅ Done (Automattic/wordpress-atmosphere#33)
- **Do**:
  1. Open PR against wordpress-atmosphere for (a) a filter on `Client::redirect_uri()` so consumers can set their own callback URL.
  2. Open PR against wordpress-atmosphere for (b) transient-persisted settings errors on connect (matching what disconnect already does).
  3. Track both PRs to merge; once landed, re-sync via `tools/sync-bundled.sh`.
- **Verify**:
  - Both upstream PRs are merged and `bundled/atmosphere/` reflects the new API.
- **Depends on**: none

### Task 5: Create Bluesky_Provider
- **Status**: ✅ Done (#34)
- **Files**: `src/Admin/Bluesky_Provider.php`
- **Do**:
  1. Create `Bluesky_Provider` implementing `Connection_Provider`. Self-registers on `fosse_register_providers` with `class_exists('\Atmosphere\Atmosphere')` guard.
  2. Setup section: if not connected, render handle input form (POSTs to `admin_post.php?action=fosse_connect_bluesky`). If connected, render handle/DID/PDS/auto-publish display + disconnect button.
  3. `handle_connect()`: verify nonce + capability, sanitize handle, call `\Atmosphere\OAuth\Client::authorize($handle)` using the upstream `redirect_uri` filter to set FOSSE's callback URL, store failure notices using the upstream persisted-notice mechanism.
  4. `handle_disconnect()`: verify nonce + capability, call `\Atmosphere\OAuth\Client::disconnect()`, redirect to FOSSE.
  5. Status card: connected/disconnected indicator, handle/DID/PDS/auto-publish details, token health check, error display, action buttons.
- **Verify**:
  - `composer run-script lint-php` passes.
  - Playground: FOSSE > Setup page shows Bluesky connect form.
  - End-to-end OAuth return-to-FOSSE flow verified once the required upstream Atmosphere changes are available. (Full OAuth flow requires a real Bluesky account and cannot be tested in Playground.)
- **Depends on**: Task 2, Task 4

### Task 5.5: Create first-run onboarding wizard
- **Status**: ✅ Done (#33)
- **Files**: `src/Admin/class-onboarding-wizard.php`, `src/Admin/class-menu.php`, `src/Admin/assets/css/admin.css`, `fosse.php`
- **Do**:
  1. Create `Onboarding_Wizard` class with step-based rendering: Welcome, Appearance (actor mode), Content (post types), Bluesky, Complete.
  2. Register the wizard as a hidden submenu in `Menu::add_menu()` by passing an empty parent slug to `add_submenu_page()`. The page has a real admin URL (`?page=fosse-wizard`) and inherits capability checks, but never appears in the menu sidebar.
  3. Add `register_activation_hook` in `fosse.php` that writes a one-shot `fosse_activation_redirect` option (autoload `false`).
  4. Add `admin_init` handler in `Menu` that checks the option, deletes it, and redirects to `?page=fosse-wizard` on first activation.
  5. Each step with form data POSTs to `admin_post.php?action=fosse_wizard_save`. Handler validates nonce + capability, saves step settings directly to AP's `activitypub_actor_mode` / `activitypub_support_post_types` options (matching AP_Provider's direct-write pattern), redirects to next step.
  6. "Skip setup" and the completion step both set `fosse_onboarding_completed` option to `1`.
  7. Actor mode selection uses card-style UI with hidden radio inputs inside `<label>` elements (works without JS). Post type selection uses checkboxes.
  8. Bluesky step renders a placeholder for future Bluesky setup work.
  9. Completion step shows summary of configured values, with links to Status Dashboard and Setup page.
  10. Add wizard CSS to `admin.css` under `.fosse-wizard` prefix. Styles use WP design tokens (colors, spacing, radii) and lean on native WP admin classes where possible.
- **Verify**:
  - `composer run-script lint-php` passes.
  - Playground: activating FOSSE for the first time redirects to the wizard.
  - Playground: completing all steps saves correct option values.
  - Playground: "Skip setup" marks wizard complete and lands on Setup page.
  - Playground: after completion, visiting FOSSE menu goes to Setup page (not wizard).
  - Wizard page is not visible in the admin menu sidebar.
- **Depends on**: Task 3

### Task 5.6: Replace Bluesky wizard placeholder with live connect form
- **Status**: ✅ Done (#47)
- **Files**: `src/Admin/class-onboarding-wizard.php`, `src/Admin/class-bluesky-provider.php`, `src/Admin/assets/css/admin.css`, `tests/php/Admin/Onboarding_WizardTest.php`, `tests/php/Admin/Bluesky_ProviderTest.php`, `tests/e2e/onboarding-wizard.spec.ts`, `sdd/onboarding-setup-ux/spec.md`, `sdd/onboarding-setup-ux/planned-decisions.md`
- **Do**:
  1. Have the wizard Bluesky step read Bluesky provider status through the provider registry, with a direct provider fallback for tests and early admin boot.
  2. When disconnected, render the live OAuth handle form that posts to `admin-post.php?action=fosse_connect_bluesky`.
  3. Include a hidden wizard return context so successful wizard-origin OAuth flows return to `?page=fosse-wizard&step=bluesky`.
  4. When connected, render the connected handle/DID/auto-publish summary and make the primary action finish setup.
  5. When unavailable, render a skip-only notice instead of a connect form.
  6. Include Bluesky connection status in the completion summary.
  7. Clear stale wizard return context when a later setup-page connect attempt starts without the wizard return marker.
  8. Update wizard CSS, PHP tests, e2e coverage, and SDD docs for the live Bluesky step.
- **Verify**:
  - `composer run-script lint-php` passes.
  - `composer run-script test-php` passes.
  - `pnpm run format:check` passes.
  - `pnpm run lint` passes.
  - `pnpm exec playwright test tests/e2e/onboarding-wizard.spec.ts` passes.
- **Depends on**: Task 5, Task 5.5

### Task 6: Create Status_Page with provider status cards
- **Status**: ✅ Done (#27)
- **Files**: `src/Admin/Status_Page.php`, `src/Admin/templates/status-page.php`
- **Do**:
  1. Create `Status_Page` that iterates providers, counts total/connected, and includes template.
  2. Create `status-page.php` template with summary row ("2 of 2 protocols active") and provider card grid.
- **Verify**:
  - `composer run-script lint-php` passes.
  - Playground: FOSSE > Status page renders with both protocol cards.
- **Depends on**: Task 3, Task 5

### Task 7: Add admin CSS
- **Status**: ✅ Done (#27)
- **Files**: `src/Admin/assets/css/admin.css`
- **Do**:
  1. Status indicator dots (green/red).
  2. Status card grid layout.
  3. Summary bar styles (healthy/attention).
  4. Provider section spacing.
  5. Lean on native WP admin classes (`form-table`, `card`, `notice`, `widefat`).
- **Verify**:
  - Playground: pages render with correct styling.
- **Depends on**: Task 6

### Task 8: Write tests
- **Status**: ✅ Done (#27, #34)
- **Files**: `tests/php/Admin/Connection_Provider_RegistryTest.php`, `tests/php/Admin/AP_ProviderTest.php`, `tests/php/Admin/Bluesky_ProviderTest.php`
- **Do**:
  1. Registry tests: register/retrieve, get_providers returns all, duplicate slug ignored, unknown slug returns null, reset clears.
  2. AP_Provider tests: status shape, direct `update_option()` writes to `activitypub_actor_mode` / `activitypub_support_post_types`, post type defaults and overrides, slug and name.
  3. Bluesky_Provider tests: redirect URI filter integration, persisted-notice read, status disconnected/connected/expired-token, unauthorized user rejection, bad nonce rejection, handle `@` normalization.
  4. Run `composer run-script lint-php` and `composer run-script test-php`.
- **Known gap**: `handle_oauth_callback()` success/warning/error branches are not unit-tested because `Client::handle_callback()` requires real PKCE state. These branches rely on manual verification (connect flow, `sync_publication` failure).
- **Verify**:
  - All tests pass.
  - Lint clean.
- **Depends on**: Task 3, Task 5

### Task 9: Exclude `src/` from WordPress.Files.FileName PHPCS sniff
- **Status**: ✅ Done (#27), then reversed in a follow-up. `src/` was migrated to the WordPress `class-*.php` filename convention so the exclusion is no longer needed; the current `.phpcs.xml.dist` only excludes `tests/php/`. The original PascalCase paths in Tasks 1–8 above describe the as-shipped-then-renamed shape — actual filenames on trunk are e.g. `src/Admin/class-connection-provider-registry.php`, `src/Admin/interface-connection-provider.php`, `src/Admin/class-ap-provider.php`.
- **Files**: `.phpcs.xml.dist`
- **Depends on**: none

### Task 10: Update SDD documentation
- **Status**: ✅ Done (#33)
- **Files**: `sdd/onboarding-setup-ux/requirements.md`, `spec.md`, `plan.md`, `planned-decisions.md`
- **Do**:
  1. Update all four SDD documents to reflect the as-built implementation — verify deviations, decisions, and limitations are accurate.
- **Verify**:
  - Four files exist in `sdd/onboarding-setup-ux/`.
  - Content accurately reflects the implemented code.
- **Depends on**: Task 8
