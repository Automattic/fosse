# Spec: Onboarding & Setup UX

## Goal

Ship a unified FOSSE admin experience that replaces the bundled plugins' fragmented settings pages with a single setup flow and status dashboard. Users configure both ActivityPub and Bluesky from one menu without needing to know the bundled plugins exist. The architecture is extensible so future protocol integrations register as providers without restructuring the UI.

## Requirements Summary

- Top-level FOSSE admin menu with Setup and Status sub-pages.
- Hide all bundled-plugin admin entries (Settings submenus, top-level Dashboard page, Users submenus) so FOSSE is the single admin surface. Pages remain accessible by direct URL.
- Inline ActivityPub configuration (actor mode, post types) with link to advanced settings.
- Bluesky OAuth connection flow embedded in the FOSSE Setup page via Atmosphere's public API.
- Connection-status dashboard showing both protocols' health on one screen.
- Extensible Connection_Provider interface for future protocols.

## Chosen Approach

**PHP-rendered admin pages with a Connection_Provider abstraction.**

### UI Stack: PHP over React

- Atmosphere's OAuth flow is a full-page redirect to an external auth server. React adds no value here.
- The status dashboard is read-only display. Standard WP admin patterns (`form-table`, `notice`, `widefat`) suffice.
- No `@wordpress/scripts`, `@wordpress/element`, or `@wordpress/components` in the project yet. Adding them means a new build step, `wp-scripts build` config, and asset compilation in CI for no MVP benefit.
- React can be introduced later if genuinely interactive components are needed.
- One small vanilla JS file may be warranted later for UX polish (loading states), but does not require the full `@wordpress/scripts` toolchain.

### Provider Abstraction: Interface + Registry

Each federated protocol implements a `Connection_Provider` interface. The Setup and Status pages iterate over registered providers rather than hardcoding protocol-specific sections. MVP ships two implementations (`AP_Provider`, `Bluesky_Provider`). Adding a third protocol later (Leaflet.pub, Threads, etc.) is: create a new class implementing the interface, register it on the `fosse_register_providers` hook, done.

Third-party plugins can also register providers via the same hook.

Selected because future extensibility was an explicit requirement, and the abstraction cost at n=2 providers is minimal (one interface, one registry class with ~30 LOC).

### Alternatives Considered

- **A — Hardcoded two-section page.** Fastest to ship but locks the UI to exactly AP + Bluesky. Adding a third protocol means restructuring the Setup and Status pages. Rejected because extensibility was an explicit requirement.
- **B — React SPA admin.** Modern feel, but requires adding `@wordpress/scripts` + build toolchain, and the OAuth flow is inherently page-redirect-based. Over-engineered for MVP. Can be introduced later for specific interactive components.
- **C — Tab-based UI reusing AP's existing tab system.** Uses `activitypub_admin_settings_tabs` filter to inject FOSSE tabs. Rejected because it ties FOSSE's UI to AP's page structure, doesn't help with Bluesky, and doesn't create a standalone FOSSE identity in the admin.

## Technical Details

### Architecture

```
fosse.php
  └── is_admin() guard
        ├── AP_Provider::init()       → hooks fosse_register_providers
        ├── Bluesky_Provider::init()  → hooks fosse_register_providers
        └── Menu::register()
              ├── do_action('fosse_register_providers')
              │     → providers self-register into Connection_Provider_Registry
              ├── provider->register_hooks() for each available provider
              ├── admin_menu @ 9   → add_menu_page('fosse') + sub-pages
              ├── admin_menu @ 99  → hide all bundled-plugin admin entries
              └── admin_enqueue_scripts → fosse-admin CSS
```

Setup_Page and Status_Page iterate `Connection_Provider_Registry::get_providers()` and call `render_setup_section()` / `render_status_card()` on each.

### OAuth Integration (Bluesky_Provider)

**Upstream-first approach.** Two gaps in Atmosphere's current API block clean integration:

1. `Client::redirect_uri()` is hardcoded to `admin_url('options-general.php?page=atmosphere')` with no filter. FOSSE needs to set its own redirect URI so the OAuth callback lands on the FOSSE page.
2. `handle_oauth_callback()` calls `add_settings_error()` then `wp_safe_redirect()` + exit, but doesn't persist the success message to a transient (unlike the disconnect handler). The message is lost across the redirect.

**Resolution:** Open upstream PRs against wordpress-atmosphere for (a) a `redirect_uri` filter and (b) transient-persisted settings errors on connect. Once landed and re-synced via `tools/sync-bundled.sh`, the FOSSE integration becomes straightforward:

1. FOSSE Setup page renders a handle input form that POSTs to `admin_post.php?action=fosse_connect_bluesky`.
2. `Bluesky_Provider::handle_connect()` validates nonce + capability, calls `\Atmosphere\OAuth\Client::authorize($handle)` with FOSSE's redirect URI set via the new filter.
3. User is redirected to the external OAuth provider.
4. Auth server redirects back to FOSSE's page (via the filtered redirect_uri).
5. Atmosphere's callback handler exchanges tokens, stores connection, persists the success message (via the upstream fix).
6. User lands on the FOSSE Setup page with a success notice.

Disconnect uses `admin_post_fosse_disconnect_bluesky`, calls `\Atmosphere\OAuth\Client::disconnect()` (public static), redirects back to FOSSE.

### AP Settings Integration (AP_Provider)

Minimum viable configuration on the Setup page. Start with the two settings that gate whether federation works:
- Actor mode radio (Blog profile / Author profiles / Both)
- Post type checkboxes (which types to federate)
- Fediverse address display (computed from site URL + actor mode, read-only)

Additional settings can be pulled into the inline surface later based on real usage data. A "Show advanced ActivityPub settings" link sends the user to `options-general.php?page=activitypub` for everything else.

**Direct AP option writes.** FOSSE's Setup page writes directly to AP's option keys (`activitypub_actor_mode`, `activitypub_support_post_types`). AP's own settings screen (reachable via the "Show advanced ActivityPub settings" link) keeps editing the same keys, so both surfaces are consistent and AP's admin UI stays honest. No parallel `fosse_ap_*` options.

The alternative — FOSSE-owned options projected into AP via `pre_option_*` filters — was rejected per DOTCOM-16875. That pattern silently overrides AP's admin on read: a user toggles a checkbox in AP's settings, FOSSE's filter returns something else when federation runs, the admin UI becomes a lie. Single source of truth (AP's option) + direct writes from both surfaces is cleaner. See `sdd/post-type-sync/` for the full decision trail.

Cross-network projection still happens for post types, but one-way: `Automattic\Fosse\Post_Types` reads `activitypub_support_post_types` and feeds Atmosphere's `atmosphere_syncable_post_types` filter, so a user's AP selection also governs Bluesky sync. Actor mode is AP-specific (no Atmosphere analogue) and needs no cross-network projection.

Form POSTs to `admin_post.php?action=fosse_save_ap_settings`. The handler validates against the allowlist of actor mode values and the list of public post types, then calls `update_option()` on the `activitypub_*` keys.

### Key Components

| Component | File | Responsibility |
|---|---|---|
| `Connection_Provider` | `src/Admin/Connection_Provider.php` | Interface: slug, name, availability, status, setup render, status render, hook registration. |
| `Connection_Provider_Registry` | `src/Admin/Connection_Provider_Registry.php` | Static registry: register, get_providers, get_provider, reset. |
| `AP_Provider` | `src/Admin/AP_Provider.php` | ActivityPub setup section (inline config) + status card. Self-registers on `fosse_register_providers`. |
| `Bluesky_Provider` | `src/Admin/Bluesky_Provider.php` | Bluesky setup section (OAuth connect/disconnect via upstream Atmosphere API) + status card. Self-registers on `fosse_register_providers`. Depends on upstream Atmosphere `redirect_uri` filter + persisted-notice transient. |
| `Menu` | `src/Admin/Menu.php` | Top-level menu registration, bundled-menu suppression, CSS enqueue, provider registration trigger. |
| `Setup_Page` | `src/Admin/Setup_Page.php` | Iterates providers, renders setup sections with notice handling. |
| `Status_Page` | `src/Admin/Status_Page.php` | Iterates providers, renders status cards with summary row. |
| Templates | `src/Admin/templates/setup-page.php`, `status-page.php` | HTML shells for the admin pages. |
| CSS | `src/Admin/assets/css/admin.css` | Status indicators, cards, layout. Leans on native WP admin classes. |

### File Changes

| File | Change Type | Description |
|------|-------------|-------------|
| `fosse.php` | modify | Add `is_admin()` block that inits AP_Provider, Bluesky_Provider, and Menu::register(). |
| `.phpcs.xml.dist` | modify | Exclude `src/` from `WordPress.Files.FileName` (classmap autoload uses PascalCase). |
| `src/Admin/Connection_Provider.php` | new | Interface definition. |
| `src/Admin/Connection_Provider_Registry.php` | new | Static provider registry. |
| `src/Admin/AP_Provider.php` | new | ActivityPub provider implementation. |
| `src/Admin/Bluesky_Provider.php` | new | Bluesky provider implementation (OAuth connect/disconnect via upstream Atmosphere API). |
| `src/Admin/Menu.php` | new | Admin menu registration and bundled-menu suppression. |
| `src/Admin/Setup_Page.php` | new | Setup page controller. |
| `src/Admin/Status_Page.php` | new | Status dashboard controller. |
| `src/Admin/templates/setup-page.php` | new | Setup page HTML template. |
| `src/Admin/templates/status-page.php` | new | Status dashboard HTML template. |
| `src/Admin/assets/css/admin.css` | new | Admin styles. |
| `tests/php/Admin/Connection_Provider_RegistryTest.php` | new | Registry unit tests. |
| `tests/php/Admin/AP_ProviderTest.php` | new | AP provider unit tests. |
| `tests/php/Admin/Bluesky_ProviderTest.php` | new | Bluesky provider unit tests (redirect URI filter integration, persisted-notice transient read, status data). |

## Out of Scope

- FOSSE-native posting UI (separate epic: DOTCOM-16794).
- Inbound reactions from Bluesky (separate epic: DOTCOM-16796).
- React/JS-based admin components. PHP-rendered pages for MVP; React introduced later if needed.
- Wrapping AP's advanced settings (relays, blocklists, authorized fetch, etc.) in the FOSSE UI. Link out for MVP.
- Multi-user onboarding. Single site-owner flow only.
- Backfill UI for Atmosphere (stays on the original Atmosphere settings page, accessible by direct URL).

## Extensibility

Adding a new protocol (e.g. Leaflet.pub) requires:
1. Create a class implementing `Connection_Provider`.
2. Hook into `fosse_register_providers` and call `Connection_Provider_Registry::register()`.
3. Done. Setup and Status pages pick it up automatically.

Third-party plugins can register providers via the same hook.
