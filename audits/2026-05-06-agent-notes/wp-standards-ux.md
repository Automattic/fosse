# Agent B notes: WordPress standards and admin UX

Target: `origin/trunk` at `1437a14f3b3022050ba6625d31497828f581c07c`

Scope reviewed: `src/Admin/**/*.php`, `src/Admin/assets/**/*`, `src/Admin/templates/*.php`, `fosse.php`, `.phpcs.xml.dist`, `eslint.config.mjs`, and package scripts. Bundled plugins were used only as context for call behavior; no direct bundled-plugin defects are reported here.

Local verification note: dependency installs are absent in this worktree (`tools/vendor`, `vendor`, and `node_modules` are missing), so I could not run PHPCS, PHPUnit, ESLint, or Prettier locally.

## Findings

### 1. bug: Bluesky/OAuth error notices can render unescaped error text on the Settings page

Evidence:

- `src/Admin/class-bluesky-provider.php:547` passes `$auth_url->get_error_message()` directly into `redirect_with_notice()`.
- `src/Admin/class-bluesky-provider.php:725` passes `$result->get_error_message()` directly into `redirect_with_notice()`.
- `src/Admin/class-bluesky-provider.php:755-759` interpolates `$sync_result->get_error_message()` into a notice message.
- `src/Admin/class-bluesky-provider.php:787-789` stores the resulting `$message` in `add_settings_error( 'atmosphere', ... )` without escaping or stripping HTML.
- `src/Admin/class-bluesky-provider.php:183` renders `settings_errors( 'atmosphere' )` on the FOSSE Settings page.

Why it matters: WordPress core stores settings-error messages as formatted message text and prints the message field without escaping in `settings_errors()` (`wp-admin/includes/template.php:2012-2017` in the local WP checkout). The wizard path manually escapes Atmosphere notices before printing (`src/Admin/class-onboarding-wizard.php:1254-1264`), but the Settings page delegates to `settings_errors()`. Any upstream or remote OAuth/token/publication error message containing HTML can therefore become admin-page markup.

Expected direction: escape before storing FOSSE-owned notices, or render this notice group through a FOSSE-owned escaped renderer instead of raw `settings_errors()`.

### 2. standards gap: raw attribute fragments are echoed in several admin templates

Evidence:

- `src/Admin/templates/status-page.php:26` echoes a conditional class fragment inside `class=""`.
- `src/Admin/class-ap-provider.php:232` and `src/Admin/class-bluesky-provider.php:265` echo `connected`/`disconnected` class fragments in attribute context.
- `src/Admin/class-onboarding-wizard.php:817`, `src/Admin/class-onboarding-wizard.php:819`, and `src/Admin/class-onboarding-wizard.php:1467` echo attribute fragments directly.

The current branches only produce fixed class/attribute strings, so this is not a user-input escaping bug today. It is still below the normal WPCS admin-template bar: build the full class/attribute string and pass it through `esc_attr()` rather than relying on raw conditional echoes.

### 3. standards gap: inline admin style bypasses the enqueued admin stylesheet

Evidence:

- `src/Admin/class-bluesky-provider.php:672` renders `<form ... style="margin-bottom: 6px;">`.
- `src/Admin/class-menu.php:245-263` already enqueues `src/Admin/assets/css/admin.css` for FOSSE admin screens.

Expected direction: move this spacing rule to a class in `src/Admin/assets/css/admin.css` so admin presentation stays centralized and lintable.

### 4. standards gap: wizard radio/checkbox groups lack semantic fieldsets and legends

Evidence:

- Destination radio cards are rendered inside plain `<div>` containers at `src/Admin/class-onboarding-wizard.php:862-880`.
- Actor-mode radio cards are rendered inside plain `<div>` containers at `src/Admin/class-onboarding-wizard.php:998-1020`.
- Content-type checkboxes are grouped with `<div>` labels rather than a `<fieldset><legend>` structure at `src/Admin/class-onboarding-wizard.php:1143-1188`.
- By contrast, the Settings page uses `fieldset` and screen-reader legends for the same kinds of controls at `src/Admin/templates/setup-page.php:61-77` and `src/Admin/templates/setup-page.php:100-165`.

Expected direction: use semantic `fieldset`/`legend` wrappers in the wizard too, even if the visible design remains card-based.

### 5. standards gap: key/value tables use data cells for row labels

Evidence:

- ActivityPub status rows use `<td class="fosse-status-card__label">` for labels at `src/Admin/class-ap-provider.php:241-247`.
- Bluesky status rows use `<td class="fosse-status-card__label">` for labels at `src/Admin/class-bluesky-provider.php:274-276` and following rows.
- Wizard review summary rows use `<td class="fosse-summary__label">` for labels at `src/Admin/class-onboarding-wizard.php:1443-1467`.

Expected direction: use `<th scope="row">` for label cells in these key/value tables. That preserves the visual design while giving assistive tech real row-header semantics.

### 6. standards gap: decorative Dashicons are not hidden from assistive tech

Evidence:

- Destination selected-state icon: `src/Admin/class-onboarding-wizard.php:876-878`.
- Actor-mode icons and selected-state icons: `src/Admin/class-onboarding-wizard.php:1008-1016`.
- Completion check icon: `src/Admin/class-onboarding-wizard.php:1432-1434`.

These icons are decorative because nearby text carries the meaning. Add `aria-hidden="true"` to the decorative Dashicon spans. The status indicators are handled differently and already expose an explicit `aria-label` (`src/Admin/class-ap-provider.php:231-235`, `src/Admin/class-bluesky-provider.php:264-268`).

### 7. standards gap: several wizard text colors miss normal-text contrast

Evidence:

- Inactive progress text uses `#949494` on white at `src/Admin/assets/css/admin.css:169-175`; computed contrast is about `3.03:1`.
- Completed progress text uses `#4ab866` on white at `src/Admin/assets/css/admin.css:182-184`; computed contrast is about `2.52:1`.
- Hint text uses `#757575` on `#f0f0f0` at `src/Admin/assets/css/admin.css:277-287`; computed contrast is about `4.04:1` for 12px text.

Expected direction: use darker WP admin palette values for small text, or reserve these lighter colors for non-text decoration only.

### 8. product/design opportunity: token-error recovery is clearer on Status than on Settings

Evidence:

- `src/Admin/class-bluesky-provider.php:110-114` records a token error when `OAuth\Client::access_token()` returns `WP_Error`.
- The Settings-page connected state shows a raw `Token Health` row at `src/Admin/class-bluesky-provider.php:238-240`, then only renders a disconnect form at `src/Admin/class-bluesky-provider.php:244-248`.
- The Status card gives stronger recovery language and a Settings-page link at `src/Admin/class-bluesky-provider.php:317-325`.

Opportunity: on the Settings page, turn a token-error state into an explicit recovery path: "Reconnect Bluesky" or "Disconnect and reconnect", with error details progressively disclosed. The current page shows the failure but makes the next action less obvious than the Status page.

### 9. product/design opportunity: status rendering performs live token-health work during page load

Evidence:

- `src/Admin/class-bluesky-provider.php:105-123` calls `OAuth\Client::access_token()` as part of `get_status()`.
- `src/Admin/templates/status-page.php:17-20` calls `get_status()` while computing the summary.
- `src/Admin/templates/status-page.php:46-50` then renders provider cards, and the Bluesky card calls `get_status()` again at `src/Admin/class-bluesky-provider.php:259-260`.

Opportunity: cache provider status for the current request or separate "cheap connection summary" from "token health check". This keeps the dashboard responsive if the token path becomes slow and avoids repeated work during a single status-page render.

### 10. product/design opportunity: state-changing wizard actions are GET links

Evidence:

- Skip setup is built with `wp_nonce_url()` at `src/Admin/class-onboarding-wizard.php:525-529` and rendered as links at `src/Admin/class-onboarding-wizard.php:887-889`, `src/Admin/class-onboarding-wizard.php:1101-1103`, and `src/Admin/class-onboarding-wizard.php:1200-1202`.
- Finish/skip Bluesky uses a nonced `admin-post.php` link at `src/Admin/class-onboarding-wizard.php:1356` and renders state-changing anchors at `src/Admin/class-onboarding-wizard.php:1363-1372`.
- Reset wizard is a nonced link at `src/Admin/class-onboarding-wizard.php:1507-1510`.

These are nonced, so this is not a CSRF finding. It is an admin UX/adoption opportunity: use small POST forms/buttons for mutations so browser prefetching, link opening, and assistive-tech expectations do not treat state changes as ordinary navigation.

### 11. product/design opportunity: unavailable-backend messaging has no direct recovery action

Evidence:

- Wizard unavailable copy tells the user to reactivate FOSSE or install/activate ActivityPub, but renders no direct action link or diagnostics at `src/Admin/class-onboarding-wizard.php:206-216`.
- Settings and Status fallback notices say "Ensure ActivityPub and Atmosphere are installed" at `src/Admin/templates/setup-page.php:42-45` and `src/Admin/templates/status-page.php:53-56`.

Opportunity: because FOSSE bundles these backends, this state is more likely a load/package/autoload problem than a normal "go install both plugins" task. Link to Plugins, Status, or a small diagnostics panel showing which backend class/function is missing.
