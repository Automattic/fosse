# Onboarding & Setup UX — Requirements

## Goal

Replace the fragmented admin experience (ActivityPub buried under Settings, Atmosphere under a separate Settings submenu, Bluesky handle setup requiring a trip to bsky.app) with a single FOSSE-owned onboarding flow. Users should be able to configure both federation protocols from one place without prior knowledge of the bundled plugins' native admin surfaces. This is the "nothing else matters if people can't set it up" prerequisite for every other FOSSE feature.

Kraft, 2026-04-20: *"The gap between 'WordPress can technically federate' and 'WordPress is a great way to be on the social web' is enormous. It's all experience problems."*

The accessibility bar: *"Nobody else could set this up. My mom could not do this. My kids could not do this."*

## Linear Issues

- **DOTCOM-16793** — 1. Onboarding & Setup UX (parent epic, assigned to Ryan)
- **DOTCOM-16800** — Unified FOSSE setup entry point
- **DOTCOM-16801** — Bluesky handle/DID setup done inside WordPress
- **DOTCOM-16802** — ActivityPub setup simplification + default-hide advanced settings
- **DOTCOM-16803** — Unified connection-status dashboard

## Requirements

1. **Single top-level FOSSE admin menu** replacing the need to find AP under Settings and Atmosphere under Settings separately. Two sub-pages: Setup and Status.
2. **Hide bundled plugins' native admin submenus** from the Settings menu so users are not confused by duplicate surfaces. Pages remain accessible by direct URL for power users.
3. **Minimum viable ActivityPub configuration** on the Setup page. Start with just the settings that gate whether federation works: actor mode and post types. Additional settings can be pulled into the inline surface later based on real usage data. Everything else linked out to the original AP settings page.
4. **Bluesky connection via OAuth inside WordPress.** Users enter their Bluesky handle on the FOSSE Setup page, authorize via Atmosphere's OAuth flow (PKCE + DPoP + PAR), and return to the FOSSE Setup page without needing to visit the Atmosphere settings page. Disconnect also handled from FOSSE. **Note:** Users still need to configure their domain as their Bluesky handle separately (DNS TXT record or `/.well-known/atproto-did` + Bluesky's "Change Handle" UI). This is a known friction point. A future FOSSE feature could guide users through this process, but for v1 we add a help link explaining the prerequisite.
5. **Unified connection-status dashboard** showing both protocols on one screen: AP configuration state, Bluesky connection state (handle, DID, PDS), auto-publish toggle, token health, errors, and action buttons (re-auth, disconnect).
6. **Extensible provider architecture** so future protocol integrations (Leaflet.pub, Threads, etc.) can register as new providers without restructuring the UI. Third-party plugins should also be able to register providers.

## Constraints

- Self-hosted WordPress plugin first. No wpcom dependencies or Calypso APIs.
- PHP 8.2+, WP 6.9+ (matching FOSSE's existing floor).
- Bundled plugins (`bundled/activitypub/`, `bundled/atmosphere/`) must NOT be modified. All integration through their public APIs, hooks, and options. If integration requires a hook or API that doesn't exist upstream, add it via a PR to wordpress-activitypub or wordpress-atmosphere and re-sync, rather than working around the gap in FOSSE.
- Shippable MVP for the radical month sprint (April 2026). Polish comes later; functional completeness now.
- Must pass the existing CI matrix: PHPUnit, PHPCS (Jetpack ruleset), ESLint, Prettier, Playwright.

## Out of Scope

- Reader / consumption side (pfefferle + jeherve radical month).
- Multi-user onboarding (single site owner flow only).
- FOSSE-native posting UI (separate epic: DOTCOM-16794).
- Inbound reactions from Bluesky (separate epic: DOTCOM-16796).
- Full admin surface replacement of bundled plugins (this iteration hides menus and provides FOSSE's own pages; the bundled pages still exist for power users).

## Source Material

- "FOSSE: Your Home on the Web, Federated" (project P2) — product vision and key promises.
- "Initial ATProto/Bluesky Research" (project P2) — identity/posting/reactions spec (comment #3 = identity section).
- "Ideas for Project Organization" (project P2) — 7-epic breakdown, onboarding-first ordering.
- Toni (comment #19 on ATProto research): recommends Atmosphere as starting point, standard.site format, offered Bluesky dev-rel intro.
- Kraft (comment #14): *"I could see using the AP plugin and the AT plugin behind the scenes... possibly effectively suppressing the UIs to provide something different."*

## Related Code / Patterns Found

- `fosse.php:39-101` — bundled plugin loading (AP and Atmosphere), first-load bootstrap via `Bundled\Bootstrap::maybe_run`.
- `src/Bundled/Bootstrap.php` — only existing FOSSE class (version-keyed activation shim).
- `bundled/activitypub/includes/wp-admin/class-menu.php` — AP registers settings page at slug `activitypub` via `add_options_page()` at `admin_menu` priority 10.
- `bundled/activitypub/includes/wp-admin/class-settings.php:86` — `activitypub_admin_settings_tabs` filter for custom tabs.
- `bundled/activitypub/includes/wp-admin/class-settings-fields.php` — AP settings fields including `activitypub_actor_mode` and `activitypub_support_post_types`.
- `bundled/atmosphere/includes/wp-admin/class-admin.php` — Atmosphere registers settings page at slug `atmosphere` via `add_options_page()` at `admin_menu` priority 10.
- `bundled/atmosphere/includes/wp-admin/class-admin.php:325-363` — OAuth callback handler (`handle_oauth_callback`), checks `$_GET['page'] === 'atmosphere'`, calls `wp_safe_redirect` to `options-general.php?page=atmosphere&connected=1` on success.
- `bundled/atmosphere/includes/oauth/class-client.php:41-42` — `redirect_uri()` returns `admin_url('options-general.php?page=atmosphere')`, hardcoded with no filter.
- `bundled/atmosphere/includes/oauth/class-client.php:54` — `authorize($handle)` is `public static`, returns `string|\WP_Error`.
- `bundled/atmosphere/includes/oauth/class-client.php:500` — `disconnect()` is `public static`, deletes `atmosphere_connection` option.
- `bundled/atmosphere/includes/functions.php:109` — `is_connected()` checks `!empty($conn['access_token']) && !empty($conn['did'])`.

## Open Questions (Resolved)

- **AP settings ownership.** ~~We write directly to AP options via `update_option()`.~~ Resolved: FOSSE stores its own options (`fosse_ap_actor_mode`, `fosse_ap_support_post_types`) and projects them to AP via `pre_option_*` filters at read time. Clear ownership, no write-time coupling, easy to unset.
- **Atmosphere's OAuth gaps.** ~~`redirect_uri()` is hardcoded with no filter, and success message isn't persisted across redirect.~~ Resolved: upstream PRs to wordpress-atmosphere for (a) a `redirect_uri` filter and (b) transient-persisted settings errors on connect (matching what disconnect already does). Fix upstream, consume via sync-bundled.
