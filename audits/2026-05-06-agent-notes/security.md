# Security Audit Notes - Agent A

Target: `origin/trunk` at `1437a14f3b3022050ba6625d31497828f581c07c`.

Scope reviewed: `fosse.php`, `src/**/*.php`, `src/Admin/templates/*.php`, `bin/build-zip.sh`, `.github/workflows/*.yml`, `composer.json`, and `package.json`.

Bundled plugin code under `bundled/activitypub` and `bundled/atmosphere` was not used for direct findings; it was inspected only where needed to understand FOSSE's integration contracts.

## Confirmed Issues

None found in the scoped FOSSE files.

## High-Risk Vectors Checked But Not Found In Code

- Admin option-write CSRF/capability bypass: not found. The unified Settings handler checks `manage_options` and `check_admin_referer()` before any delegated option writes (`src/Admin/class-setup-page.php:78-99`), and the wizard save path checks `manage_options` plus `wp_verify_nonce()` before writing destination, actor-mode, blog-handle, completion, and post-type options (`src/Admin/class-onboarding-wizard.php:225-322`).
- Bluesky connect/disconnect/auto-publish action bypass: not found. Each `admin_post_*` handler requires `manage_options` and its own nonce before starting OAuth, disconnecting, or updating `atmosphere_auto_publish` (`src/Admin/class-bluesky-provider.php:511-553`, `src/Admin/class-bluesky-provider.php:562-587`, `src/Admin/class-bluesky-provider.php:602-615`).
- OAuth callback CSRF/state confusion: not found. FOSSE requires the callback to target `page=fosse`, requires both `code` and `state`, gates completion on `manage_options`, binds wizard return context to the stored OAuth state, and delegates the actual state validation to Atmosphere's callback API (`src/Admin/class-bluesky-provider.php:686-725`, `src/Admin/class-bluesky-provider.php:887-908`).
- User-controlled open redirect: not found in FOSSE code. The only non-safe redirect is the intentional OAuth handoff after nonce/capability checks, non-empty handle validation, and AT handle format validation (`src/Admin/class-bluesky-provider.php:511-553`); other FOSSE redirects use `wp_safe_redirect()` to `admin_url()` or `add_query_arg()` over fixed admin destinations (`src/Admin/class-onboarding-wizard.php:508-517`, `src/Admin/class-setup-page.php:111-114`).
- Public `/.well-known/atproto-did` HTML/header injection: not found. The handler reads the raw request URI only for strict path matching, returns `text/plain`, requires an Atmosphere connection, and rejects stored DIDs that fail the anchored ASCII DID regex before echoing the body (`src/Admin/class-bluesky-provider.php:388-458`).
- Bluesky token leakage in admin UI: not found. FOSSE's status API surface returns handle, DID, PDS endpoint, and token error only, not access/refresh tokens (`src/Admin/class-bluesky-provider.php:105-123`), and the rendered connected/status views output only those fields with escaping/formatter helpers (`src/Admin/class-bluesky-provider.php:225-240`, `src/Admin/class-bluesky-provider.php:272-325`).
- Bundled bootstrap path injection / duplicate activation: not found. Bundled backends are loaded from fixed plugin-relative paths only when standalone constants/files are absent (`fosse.php:57-70`), and activation shims run through fixed callables keyed by upstream version options (`fosse.php:87-113`, `src/Bundled/class-bootstrap.php:33-46`).
- Untrusted PR workflow token escalation: not found. Test, lint, and e2e workflows run on `pull_request` rather than `pull_request_target` and grant read-only repository permissions (`.github/workflows/tests.yml:3-14`, `.github/workflows/linting.yml:3-15`, `.github/workflows/e2e.yml:3-14`).
- Release workflow broad write token during build: not found. The build job is `contents: read`; `contents: write` is scoped to release publishing jobs after the zip artifact has already been produced (`.github/workflows/build-zip.yml:15-17`, `.github/workflows/build-zip.yml:58-88`). The zip script stages tracked content via `git archive`, installs production Composer deps with `--no-dev --no-scripts`, removes Composer metadata, and sanity-checks required runtime files before publish (`bin/build-zip.sh:31-35`, `bin/build-zip.sh:52-64`, `bin/build-zip.sh:71-83`).
