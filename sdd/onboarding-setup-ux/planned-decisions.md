# Implementation Notes — Onboarding & Setup UX

These implementation notes capture the planned implementation decisions, constraints, and known limitations for the onboarding and setup UX work ahead of the code PR.

## Design Decisions

### PHPCS filename exclusion planned for all of `src/`
- **Current repo state**: `.phpcs.xml.dist` scans `src/` and does not yet exclude it from the `WordPress.Files.FileName` rule.
- **Planned change**: Add `<exclude-pattern>src/</exclude-pattern>` to the `WordPress.Files.FileName` rule in the implementation PR.
- **Reason**: The Jetpack PHPCS ruleset enforces `class-*.php` lowercase-hyphenated filenames, which is the WordPress convention for non-namespaced code. FOSSE uses namespaced classes with classmap autoloading, where PascalCase filenames matching class names is the standard PHP convention. The existing `src/Bundled/Bootstrap.php` already uses PascalCase.
- **Expected impact**: Files under `src/` will be exempt from the filename sniff. Other sniffs (spacing, escaping, i18n, etc.) will continue to apply fully.

### Option projection instead of direct AP option writes
- **Initial approach**: Write directly to `activitypub_actor_mode` and `activitypub_support_post_types` via `update_option()`.
- **Revised approach**: FOSSE stores its own options (`fosse_ap_actor_mode`, `fosse_ap_support_post_types`) and projects them to AP via `pre_option_*` filters at read time.
- **Reason**: Avoids write-time coupling to AP's option schema, provides clear ownership story (FOSSE owns `fosse_*`, AP owns `activitypub_*`), and makes "stop letting FOSSE manage this" a one-line operation (delete the FOSSE option, AP's stored value takes over).

### Upstream-first OAuth integration (not wp_redirect intercept)
- **Initial approach**: Hook `wp_redirect` to intercept Atmosphere's post-callback redirect and rewrite it to the FOSSE page.
- **Revised approach**: Open upstream PRs against wordpress-atmosphere for (a) a `redirect_uri` filter and (b) transient-persisted settings errors on connect. Consume via sync-bundled once landed.
- **Reason**: Per the upstream-first policy in AGENTS.md. Both gaps are post-type-agnostic (any consumer of Atmosphere running its own admin surface would want these), so they belong upstream, not as FOSSE-side shims.

### Provider `register_hooks()` called from `Menu::register()`
- **Decision**: `Menu::register()` fires `fosse_register_providers`, then iterates registered providers and calls `register_hooks()` on each. Provider hooks (like `admin_post_*` handlers) are only registered in admin context.
- **Reason**: Avoids registering admin-post handlers on front-end requests. The `is_admin()` guard in `fosse.php` already limits this to admin context, but having `Menu::register()` control the lifecycle is cleaner.

## Known Limitations (Expected)

### Users must configure their domain handle on bsky.app separately
The OAuth flow authorizes an existing Bluesky account but does not configure the user's domain as their Bluesky handle. Users who want `@theirdomain.com` as their handle still need to set up DNS TXT or `/.well-known/atproto-did` and use Bluesky's "Change Handle" UI. The Setup page will include a help link explaining this prerequisite. Guiding users through this process within FOSSE is a future feature.

### Status dashboard will not auto-refresh
The status dashboard will be a static PHP page. Token expiry, connection health, and follower counts are current as of page load only. No AJAX polling or auto-refresh planned for MVP.

### No E2E test for the full OAuth flow
The Bluesky OAuth flow requires a real Bluesky account and external auth server. Playground cannot test this end-to-end. Unit tests will cover redirect logic and status data. The full OAuth round-trip is manual-only.

### Deactivation/deletion behavior is deferred
This epic does not define what happens to `fosse_ap_*` options or menu state when FOSSE is deactivated or deleted, nor how FOSSE behaves if ActivityPub or Atmosphere are also installed as standalone plugins. Tracked separately in [DOTCOM-16865](https://linear.app/a8c/issue/DOTCOM-16865/deactivation-and-deletion-handling-for-fosse-and-bundled-plugins) so the decision happens before production distribution. The option-projection pattern (FOSSE stores `fosse_ap_*`, projects via `pre_option_activitypub_*`) is clean for the "FOSSE is active" case but needs a companion story for lifecycle events. Not blocking for MVP.

## Upstream Dependencies

- **wordpress-atmosphere PR (a)**: Filter on `Client::redirect_uri()` so FOSSE can set its own callback URL.
- **wordpress-atmosphere PR (b)**: Transient-persisted settings errors on connect (matching what disconnect already does).
- Both must land and be re-synced via `tools/sync-bundled.sh` before the FOSSE implementation PR ships.
