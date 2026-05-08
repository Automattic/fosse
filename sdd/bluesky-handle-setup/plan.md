# Implementation Plan: Bluesky Handle Setup

Based on: sdd/bluesky-handle-setup/spec.md

## Progress

- [x] Task 1: Add `/.well-known/atproto-did` route handler
- [ ] Task 2: Add verification check method
- [ ] Task 2.5: Add `fetch_current_handle($did)` helper
- [x] Task 3: Add domain-handle UI to Bluesky_Provider setup section
- [x] Task 4: Add admin-post handlers
- [ ] Task 5: Add DNS TXT fallback display
- [x] Task 6: Unit tests
- [ ] Task 6.5: Add Playwright E2E test for well-known route
- [ ] Task 7: Update SDD documentation

## Tasks

### Task 1: Add `/.well-known/atproto-did` route handler
- **Status**: ✅ Done (396985f, 138404f, bc43461)
- **Files**: `src/Admin/class-bluesky-provider.php`
- **Do**:
  1. Add `serve_atproto_did_well_known()` method. Hook to `init` priority 1 inside `register_hooks()`.
  2. Parse `$_SERVER['REQUEST_URI']` with `wp_parse_url( ..., PHP_URL_PATH )` and match strict equality against `/.well-known/atproto-did`. If no match, return early.
  3. Apply `fosse_serve_atproto_did_well_known` filter (default `true`). If false, return.
  4. Require Atmosphere to report connected (`did` and access token present), then read `atmosphere_connection['did']`. If not connected or the DID is empty, send 404 and exit.
  5. Set `Content-Type: text/plain` header. Echo DID with no trailing newline. Exit.
  6. Add a paired `maybe_suppress_atmosphere_well_known()` method hooked to `template_redirect` priority 1. Clears Atmosphere's `atmosphere_wellknown` query var and marks the request 404 when the FOSSE filter is false, so Atmosphere's own handler doesn't take over after FOSSE opts out.
- **Verify**:
  - `composer run-script lint-php` passes.
  - Curl `/.well-known/atproto-did` on a connected site returns plain-text DID.
- **Depends on**: none

### Task 2: Add verification check method
- **Status**: Not started
- **Files**: `src/Admin/class-bluesky-provider.php`
- **Do**:
  1. Add `check_handle_verification(string $domain): array` returning `['status', 'resolved_did', 'error']`.
  2. Build `wp_remote_get` to `https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=<domain>`.
  3. Parse JSON. If `did` matches stored DID, status `verified`. If response has `did` but mismatched, status `mismatch`. If the resolver returns `HandleNotFound` (the common "domain not configured yet" case), status `not_found`. Other errors → `error`.
  4. Cache result in transient `fosse_handle_check_<md5(domain . '|' . expected_did)>` for 5 minutes. Include the expected DID in the key so reconnecting a different account within the cache window doesn't reuse a stale `verified` result for the new DID.
- **Verify**:
  - `composer run-script lint-php` passes.
  - Unit test verified/mismatch/not-found/error branches via `pre_http_request` intercept.
- **Depends on**: none

### Task 2.5: Add `fetch_current_handle($did)` helper
- **Status**: Not started
- **Files**: `src/Admin/class-bluesky-provider.php`
- **Do**:
  1. Add `fetch_current_handle(string $did): ?string` returning the actor's current handle, or `null` on error.
  2. Call `https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=<did>` via `wp_remote_get`.
  3. Cache result in transient `fosse_handle_current_<md5(did)>` for 5 minutes (paired with the verification cache, busted by the same "Check verification" button).
- **Verify**:
  - `composer run-script lint-php` passes.
  - Unit test happy/missing/error branches via `pre_http_request` intercept.
- **Depends on**: none

### Task 3: Add domain-handle UI to Bluesky_Provider setup section
- **Status**: ✅ Done (Automattic/fosse#97)
- **Files**: `src/Admin/class-bluesky-provider.php`
- **Do**:
  1. Extract a `render_domain_handle_subsection()` method called from the existing `render_setup_section()` when connected.
  2. Implement the UI states from spec.md (hidden / ineligible / CTA / pending-not-found / mismatch / error / verified / active).
  3. Derive the candidate handle from `wp_parse_url( home_url(), PHP_URL_HOST )`; normalize to lowercase, strip any trailing dot, reject hosts with ports, and convert IDNs to ASCII with `idn_to_ascii()` when available.
  4. Check root-domain eligibility before showing setup controls: `'' === trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' )`. Subdirectory installs and subdirectory-multisite subsites are ineligible because ATProto handles cannot contain paths. Subdomain-multisite subsites and subdirectory-multisite main sites remain eligible when `home_url()` is host-root.
  5. Status pulls from `check_handle_verification($host)`, `fetch_current_handle($did)` (see Task 2.5), and the `fosse_bluesky_handle_setup_started` option. The "active" state is only reached when `fetch_current_handle($did) === $host`, since `atmosphere_connection['handle']` is captured at OAuth time and goes stale after a handle change.
- **Verify**:
  - `composer run-script lint-php` passes.
  - Playground: each UI state renders for a manually-set option combination, including the ineligible subdirectory-install state.
- **Depends on**: Task 1, Task 2, Task 2.5

### Task 4: Add admin-post handlers
- **Status**: ✅ Done (Automattic/fosse#97)
- **Files**: `src/Admin/class-bluesky-provider.php`
- **Do**:
  1. Add `admin_post_fosse_bluesky_start_handle_setup` handler. Verify nonce + capability. Set `fosse_bluesky_handle_setup_started` to `1` with autoload disabled (`add_option( ..., '', false )` on first write; `update_option()` afterward). Redirect back to FOSSE Setup with `settings-updated=true`.
  2. Add `admin_post_fosse_bluesky_check_handle` handler. Verify nonce + capability. Bust both the verification transient (`fosse_handle_check_<md5(domain . '|' . did)>`) AND the current-handle transient (`fosse_handle_current_<md5(did)>`) so the UI reflects the post-handoff state immediately. Redirect back to FOSSE Setup with `settings-updated=true`.
  3. Register both in `register_hooks()`.
- **Verify**:
  - `composer run-script lint-php` passes.
  - Playground: clicking "Set up" and "Check verification" each redirect cleanly with notices.
- **Depends on**: Task 3

### Task 5: Add DNS TXT fallback display
- **Status**: Not started
- **Files**: `src/Admin/class-bluesky-provider.php`, `src/Admin/assets/css/admin.css`
- **Do**:
  1. Add a `<details>` element inside the pending-state UI labeled "Show DNS fallback."
  2. Render a code block with `_atproto.<domain>` (left) and `did=<did>` (right). Add a copy-to-clipboard button.
  3. Style the code block with monospace font and light background per FOSSE admin conventions.
- **Verify**:
  - `composer run-script lint-php` passes.
  - Playground: details element expands to show TXT record content. Copy button works.
- **Depends on**: Task 3

### Task 6: Unit tests
- **Status**: ✅ Done (Automattic/fosse#97)
- **Files**: `tests/php/Admin/Bluesky_Handle_SetupTest.php`
- **Do**:
  1. Test `serve_atproto_did_well_known`: matches path, ignores other paths, respects filter, returns 404 when not connected.
  2. Test `check_handle_verification`: each status branch via `pre_http_request` intercept. Caching honored.
  3. Test `fetch_current_handle`: happy path returns handle, malformed response returns `null`, HTTP/WP_Error responses return `null`, and caching is honored.
  4. Test domain normalization/eligibility: lowercase host, strip trailing dot, reject path-bound installs, convert IDNs when supported.
  5. Test admin-post handlers: nonce required, capability required, option toggled with autoload disabled, transient busted, redirect target correct.
- **Verify**:
  - `composer run-script test-php` all green.
  - `composer run-script lint-php` clean.
- **Depends on**: Task 5

### Task 6.5: Add Playwright E2E test for well-known route
- **Status**: Not started
- **Files**: `tests/e2e/bluesky-handle.spec.ts`
- **Do**:
  1. New spec that activates a fixture mu-plugin which seeds a fake connected `atmosphere_connection` (with a known DID and access token) so the well-known route has data to serve.
  2. Test 1: `GET /.well-known/atproto-did` returns 200, content-type `text/plain`, body equals the seeded DID with no trailing newline.
  3. Test 2: When the fixture mu-plugin clears the connection option, the same request returns 404.
  4. Test 3: When the `fosse_serve_atproto_did_well_known` filter returns `false`, the request returns 404 (verifies the disable filter works).
- **Verify**:
  - `pnpm exec playwright test tests/e2e/bluesky-handle.spec.ts` all green locally.
- **Depends on**: Task 1

### Task 7: Update SDD documentation
- **Status**: Not started
- **Files**: `sdd/bluesky-handle-setup/requirements.md`, `spec.md`, `plan.md`, `implementation-notes.md`
- **Do**:
  1. Update all four SDD documents to reflect as-built implementation. Add Done markers with PR ref.
- **Verify**:
  - Four files exist. Content matches code.
- **Depends on**: Task 6, Task 6.5
