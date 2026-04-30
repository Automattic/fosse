# Implementation Notes — Onboarding & Setup UX

These implementation notes capture the planned implementation decisions, constraints, and known limitations for the onboarding and setup UX work ahead of the code PR.

## Design Decisions

### PHPCS filename exclusion planned for all of `src/`
- **Current repo state**: `.phpcs.xml.dist` scans `src/` and does not yet exclude it from the `WordPress.Files.FileName` rule.
- **Planned change**: Add `<exclude-pattern>src/</exclude-pattern>` to the `WordPress.Files.FileName` rule in the implementation PR.
- **Reason**: The Jetpack PHPCS ruleset enforces `class-*.php` lowercase-hyphenated filenames, which is the WordPress convention for non-namespaced code. FOSSE uses namespaced classes with classmap autoloading, where PascalCase filenames matching class names is the standard PHP convention. The existing `src/Bundled/Bootstrap.php` already uses PascalCase.
- **Expected impact**: Files under `src/` will be exempt from the filename sniff. Other sniffs (spacing, escaping, i18n, etc.) will continue to apply fully.

### Direct AP option writes (superseded projection plan)
- **Current approach**: FOSSE's Setup page writes directly to `activitypub_actor_mode` and `activitypub_support_post_types` via `update_option()`. AP's own settings screen keeps editing the same keys.
- **Superseded (original) approach**: FOSSE would store its own `fosse_ap_actor_mode` / `fosse_ap_support_post_types` options and project them into AP via `pre_option_*` filters at read time. Abandoned per DOTCOM-16875 review — that pattern silently overrides AP's admin UI on read, so a user's checkbox toggle in AP returns something different when federation runs. Two sources of truth, worst version.
- **Reason for the flip**: The upstream PR thread on [Automattic/wordpress-activitypub#3218](https://github.com/Automattic/wordpress-activitypub/pull/3218) made the divergence concrete. AP's maintainer pointed out the stock `option_activitypub_support_post_types` filter already exists, which is exactly what a `pre_option_*` projection would ride on — and that's precisely the silent-override mechanism. Single source of truth (AP's option) + direct writes from both surfaces is the clean fix. Cross-network projection still happens, but one-way: `Automattic\Fosse\Post_Types` reads AP's option and feeds Atmosphere's `atmosphere_syncable_post_types` filter. See `sdd/post-type-sync/` for the full decision trail.

### Upstream-first OAuth integration (not wp_redirect intercept)
- **Initial approach**: Hook `wp_redirect` to intercept Atmosphere's post-callback redirect and rewrite it to the FOSSE page.
- **Revised approach**: Open upstream PRs against wordpress-atmosphere for (a) a `redirect_uri` filter and (b) transient-persisted settings errors on connect. Consume via sync-bundled once landed.
- **Reason**: Per the upstream-first policy in AGENTS.md. Both gaps are post-type-agnostic (any consumer of Atmosphere running its own admin surface would want these), so they belong upstream, not as FOSSE-side shims.

### Provider `register_hooks()` called from `Menu::register()`
- **Decision**: `Menu::register()` fires `fosse_register_providers`, then iterates registered providers and calls `register_hooks()` on each. Provider hooks (like `admin_post_*` handlers) are only registered in admin context.
- **Reason**: Avoids registering admin-post handlers on front-end requests. The `is_admin()` guard in `fosse.php` already limits this to admin context, but having `Menu::register()` control the lifecycle is cleaner.

### Wizard Bluesky step uses provider state
- **Decision**: The first-run wizard's Bluesky step reads the registered Bluesky provider status instead of keeping wizard-specific Bluesky state. Disconnected sites render the same OAuth handle form used by the Setup page; connected sites render the connected handle/DID/auto-publish summary; unavailable sites render a skip-only notice.
- **Reason**: Bluesky connection state belongs to Atmosphere and is already surfaced through `Bluesky_Provider::get_status()`. Reusing that provider keeps the wizard, Setup page, and Status page consistent while avoiding a parallel wizard-only option.

### Wizard-origin Bluesky OAuth preserves return context
- **Decision**: The wizard connect form includes a `fosse_bluesky_return=wizard` hidden field. `Bluesky_Provider::handle_connect()` stores that context in a per-user transient only after `Atmosphere\OAuth\Client::authorize()` returns an auth URL. The external OAuth callback still lands on the FOSSE Setup page, then `handle_oauth_callback()` consumes the transient and redirects back to the wizard's Bluesky step with `settings-updated=true`.
- **Reason**: Atmosphere's client metadata endpoint can advertise only a stable callback URI for the OAuth client, and FOSSE already owns that URI as `admin.php?page=fosse`. A FOSSE-side return-context transient lets the wizard preserve its flow without changing the registered OAuth callback or introducing wizard-owned Bluesky connection state.

### Wizard step order remains unchanged for the Bluesky-connect PR
- **Decision**: This PR keeps the existing wizard order: Welcome → Appearance → Content → Bluesky → Complete.
- **Reason**: The PR scope is limited to replacing the Bluesky placeholder with the real provider-backed connect state. After this ships, revisit the onboarding sequence so Bluesky no longer feels like an afterthought relative to the ActivityPub content settings.

## Known Limitations (Expected)

### Users must configure their domain handle on bsky.app separately
The OAuth flow authorizes an existing Bluesky account but does not configure the user's domain as their Bluesky handle. Users who want `@theirdomain.com` as their handle still need to set up DNS TXT or `/.well-known/atproto-did` and use Bluesky's "Change Handle" UI. The Setup page and wizard include help links explaining this prerequisite. Guiding users through this process within FOSSE is a separate Bluesky handle setup epic.

### Status dashboard will not auto-refresh
The status dashboard will be a static PHP page. Token expiry, connection health, and follower counts are current as of page load only. No AJAX polling or auto-refresh planned for MVP.

### No E2E test for the full OAuth flow
The Bluesky OAuth flow requires a real Bluesky account and external auth server. Playground cannot test this end-to-end. Unit tests will cover redirect logic and status data. The full OAuth round-trip is manual-only.

### Deactivation/deletion behavior is deferred
This epic does not define what happens to FOSSE's menu state when FOSSE is deactivated or deleted, nor how FOSSE behaves if ActivityPub or Atmosphere are also installed as standalone plugins. Tracked separately in [DOTCOM-16865](https://linear.app/a8c/issue/DOTCOM-16865/deactivation-and-deletion-handling-for-fosse-and-bundled-plugins) so the decision happens before production distribution. With the direct-write approach, there are no FOSSE-owned settings to clean up on uninstall — AP's admin naturally takes over — but standalone-activation edge cases still need a decision. Not blocking for MVP.

### First-run wizard uses activation-redirect pattern
- **Decision**: On plugin activation, `register_activation_hook` writes a one-shot `fosse_activation_redirect` option (autoload `false`). On the next qualifying `admin_init`, Menu checks for the option, deletes it, and redirects to the wizard page. The wizard is registered as a hidden submenu (via an empty parent slug on `add_submenu_page()`) so it has a real admin URL but never appears in the sidebar. A legacy 30-second transient under the same key is migrated to the option on read so existing installs upgrade transparently.
- **Reason**: This is the standard WordPress onboarding pattern (WooCommerce, Jetpack, Akismet all do it). The option-backed signal is consumed at most once and persists across reboots, so a slow first admin visit can't lose the redirect (which a 30-second transient would). The redirect is gated on `current_user_can('manage_options')`, skipped during AJAX/cron/CLI/`activate-multi`, and gated on a registered, available ActivityPub provider — so a lower-privileged or non-admin request can't burn the option before the actual administrator arrives, and the wizard never tries to render steps that depend on AP actor data when AP is missing. The hidden-page registration means the wizard inherits WP capability checks without cluttering the menu.

### Wizard saves settings per-step, not on final completion
- **Decision**: Each wizard step that collects settings POSTs to `admin_post.php` and saves directly to the AP-owned options (`activitypub_actor_mode`, `activitypub_support_post_types`) immediately, then redirects to the next step. An empty post-types submission bounces back to the same step with an inline error rather than overwriting the option with `[]`.
- **Reason**: If the user abandons the wizard mid-flow (closes tab, navigates away), settings from completed steps are already saved. This is more resilient than batching all saves to the final step. The wizard writes to the same options that AP_Provider's Setup page manages (per the direct-write decision above), so there's a single source of truth across both surfaces. Rejecting the empty post-types case prevents silent federation breakage when a user unchecks every box.

### Card-based actor mode selection works without JavaScript
- **Decision**: The actor mode cards in step 2 use `<label>` elements wrapping hidden `<input type="radio">` fields. Selected-state styling lifts up to the card via `:has(.fosse-mode-card__input:checked)`, and a `:focus-within` fallback (parallel to `:has(:focus-visible)`) surfaces a focus ring on the card for keyboard users. The post-type rows on step 3 use the same `:has(input:checked)` pattern. The wizard ships no JavaScript; the fediverse-handle preview is rendered server-side from the AP actor models, not progressively enhanced.
- **Reason**: The spec chose PHP over React to avoid a JS build step. The card UI is the one place where custom styling adds genuine UX value over standard radio buttons, and we keep it pure CSS so the wizard stays JS-free. `:has()` ships in Chrome 105+, Edge 105+, Safari 15.4+, and Firefox 121+ — well above WP-admin's effective floor by the time this lands. On a browser without `:has()`, the underlying radio/checkbox is still functional (the inputs are visually hidden but tabbable and clickable through the wrapping `<label>`); the only regression is the persistent card highlight, which is decoration. Restructuring the markup to use sibling selectors (`input:checked ~ ...`) would require lifting the input out of the `<label>` and would cost the full-card click target — not worth the trade for a cosmetic fallback.

### Wizard completion tracked by a single option
- **Decision**: `fosse_onboarding_completed` option (value `1`) tracks whether the wizard has run. Both "Skip setup" and the final completion step set this.
- **Reason**: A single boolean is the simplest state to check. The Setup page can show a "Run setup wizard" link when this option is absent, giving users a way back in without making the wizard re-trigger on every visit. The option is deliberately not tied to a version number: if we want to re-trigger the wizard for a future major feature, we can add a versioned check later.

### Setup page shows notice, not auto-redirect, when wizard is incomplete
- **Decision**: When `fosse_onboarding_completed` is absent, the Setup page renders normally but shows an admin notice linking to the wizard. It does not auto-redirect.
- **Reason**: The user may have navigated to Setup intentionally (bookmarked URL, direct link from another plugin). Auto-redirecting would be disorienting. The notice is informational, not blocking.

## Upstream Dependencies

- **wordpress-atmosphere PR (a)**: Filter on `Client::redirect_uri()` so FOSSE can set its own callback URL.
- **wordpress-atmosphere PR (b)**: Transient-persisted settings errors on connect (matching what disconnect already does).
- Both must land and be re-synced via `tools/sync-bundled.sh` before the FOSSE implementation PR ships.
