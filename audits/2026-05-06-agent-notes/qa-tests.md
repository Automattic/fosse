# Agent C QA/Test Audit Notes

Checkout: `1437a14f3b3022050ba6625d31497828f581c07c` (`origin/trunk` target).

Scope reviewed: PHPUnit/Jest/Playwright tests, e2e mu-plugins, `phpunit.xml.dist`, `jest.config.js`, `playwright.config.ts`, and CI workflows. Bundled plugin code was not used for direct findings.

## Bad Existing Tests

### High: `status-page.spec.ts` cleanup is wired through the wrong page fixture

`tests/e2e/status-page.spec.ts:30-40` creates a raw `browser.newPage()` in `afterAll()` and then navigates to `'/wp-admin/post-new.php'`. The shared helper documents that `browser.newPage()` does not inherit Playwright's `baseURL` and therefore must be passed explicitly (`tests/e2e/test-helpers.ts:50-64`). This cleanup is the only thing resetting the long Bluesky connection seeded by the status-page tests (`tests/e2e/status-page.spec.ts:50-58`), so a cleanup failure can leave `atmosphere_connection` dirty for later specs or a reused local Playground server.

Proposed test/fix target:

- Replace the local `setBlueskyState` helper in `tests/e2e/status-page.spec.ts` with `resetBlueskyState( browser, testInfo.project.use.baseURL )`, mirroring `tests/e2e/bluesky-provider.spec.ts:10-18`.
- Add a small regression assertion in `tests/e2e/status-page.spec.ts` or `tests/e2e/test-helpers.ts` that hook-scope cleanup uses an absolute/baseURL-backed admin page before calling `/wp-json/fosse-e2e/v1/bluesky-state`.

### Medium: Jest is a harness smoke test, not coverage of the shipped admin JS

`tests/js/fosse.test.js:1-5` only asserts `true === true`, while `jest.config.js:3-6` points Jest at `tests/js/**/*.test.js`. The only shipped JS file, `src/Admin/assets/js/wizard-appearance.js`, contains the wizard actor-mode preview and site-handle visibility behavior (`src/Admin/assets/js/wizard-appearance.js:30-75`), but none of that behavior is unit-tested. The Playwright wizard test covers one happy interaction (`tests/e2e/onboarding-wizard.spec.ts:89-165`), but Jest does not protect DOMContentLoaded timing, no-radio no-op behavior, initial stale server markup correction, or blog-handle toggling in isolation.

Proposed tests:

- Replace `tests/js/fosse.test.js` with `tests/js/wizard-appearance.test.js`.
- Load `src/Admin/assets/js/wizard-appearance.js` into jsdom and assert:
  - on init, only the checked `.fosse-mode-card__input` preview lacks `is-hidden`;
  - changing to `blog` or `actor_blog` reveals `[data-fosse-when="includes-blog"]`;
  - changing back to `actor` hides the site-handle row;
  - the script no-ops without radios and does not throw;
  - both `document.readyState === 'loading'` and already-loaded paths initialize.

### Medium: Facet/link-card e2e specs validate transformed records, not the real publish path

`tests/e2e/mu-plugins/fosse-bsky-capture.php:4-9` says it dumps records "without standing up a real PDS or OAuth connection", and the implementation hooks `transition_post_status` before Atmosphere's publisher (`tests/e2e/mu-plugins/fosse-bsky-capture.php:19-45`) then writes JSON to uploads (`tests/e2e/mu-plugins/fosse-bsky-capture.php:47-57`). `tests/e2e/short-form-facets.spec.ts:86-103` and `tests/e2e/long-form-link-card.spec.ts:91-105` then poll that capture file. These tests are useful transformer/projector tests, but they can pass if the actual publish path stops firing, if the PDS write is skipped, if connection gating breaks, or if record ordering/atomic write behavior changes.

Proposed tests:

- Keep the capture specs, but relabel them as transformer/projector e2e guards.
- Add a `tests/e2e/bluesky-publish-path.spec.ts` that seeds a connected `atmosphere_connection`, intercepts PDS requests through a test-only `pre_http_request` mu-plugin recorder, publishes a post via REST, and asserts the real publish flow attempted both the `app.bsky.feed.post` and `site.standard.document` writes.
- Add a sad-path variant with disconnected Bluesky state and assert no PDS write is attempted.

### Low: `test_handle_connect_strips_leading_at()` can pass without proving normalization

`tests/php/Admin/Bluesky_ProviderTest.php:1382-1416` intercepts the first HTTP URL and only asserts it does not contain `@alice`. That would still pass if the authorize path called the wrong endpoint, omitted the handle, or mangled it into another string that lacks `@alice`.

Proposed test:

- In `tests/php/Admin/Bluesky_ProviderTest.php`, strengthen the assertion to require the captured authorize/resolve URL to contain the normalized `alice.bsky.social` handle and not contain either raw `@alice` or encoded `%40alice`.

## Missing Coverage Gaps

### High: The public `/.well-known/atproto-did` lifecycle is not exercised

The PHPUnit coverage calls the private helper through reflection (`tests/php/Admin/Bluesky_ProviderTest.php:1724-1732`) and verifies helper-level branches (`tests/php/Admin/Bluesky_ProviderTest.php:438-549`). The public method that reads `$_SERVER['REQUEST_URI']`, sends status/content headers, echoes the DID, and exits lives in `src/Admin/class-bluesky-provider.php:388-408`, but no e2e test hits `/.well-known/atproto-did` over HTTP. The suppression tests only call `maybe_suppress_atmosphere_well_known()` directly with a synthetic query var (`tests/php/Admin/Bluesky_ProviderTest.php:554-602`).

Proposed tests:

- Add `tests/e2e/atproto-did-well-known.spec.ts`.
- Scenarios:
  - connected Bluesky state returns HTTP 200, `Content-Type: text/plain`, and exactly the stored DID body;
  - disconnected state returns HTTP 404 with no DID body;
  - encoded/lookalike paths such as `/.well-known/%61tproto-did`, `/.well-known/atproto-did/`, and `/.well-known/atproto-did%0A` do not return the DID;
  - an opt-out mu-plugin filter for `fosse_serve_atproto_did_well_known` yields 404 and confirms the bundled Atmosphere handler does not still serve the route.

### High: Wizard content-save invalid-shape coverage is weaker than Settings save coverage

`tests/php/Admin/Setup_PageTest.php:208-265` explicitly covers nested-array and non-array `activitypub_support_post_types` payloads. The wizard content-save tests cover a normal array, an invalid string element, and an empty array (`tests/php/Admin/Onboarding_WizardTest.php:233-265`, `tests/php/Admin/Onboarding_WizardTest.php:366-394`), but not nested arrays or scalar payloads. The production wizard path applies `array_map( 'sanitize_text_field', wp_unslash( (array) ... ) )` directly (`src/Admin/class-onboarding-wizard.php:303-306`), so a nested-array payload is exactly the kind of warning that the Settings tests already guard against elsewhere.

Proposed tests:

- Add `test_handle_save_content_drops_nested_array_post_types()` to `tests/php/Admin/Onboarding_WizardTest.php`, mirroring `Setup_PageTest`.
- Add `test_handle_save_content_handles_non_array_post_types()` for scalar `activitypub_support_post_types`.
- Assert no option overwrite with invalid-only input, no PHP warning, and redirect back to `step=content&error=empty_post_types`.

### Medium: E2E state isolation depends on serialization and best-effort teardown

`playwright.config.ts:8-11` hard-codes `fullyParallel: false` and `workers: 1`, while `playwright.config.ts:25-29` reuses an existing local Playground server outside CI. The specs and mu-plugins mutate global WordPress options: `fosse_object_type` (`tests/e2e/mu-plugins/fosse-bsky-capture.php:91-98`), `atmosphere_connection` (`tests/e2e/mu-plugins/fosse-bsky-capture.php:149-166`), and `atmosphere_auto_publish` (`tests/e2e/mu-plugins/fosse-bsky-capture.php:168-170`). Some specs reset at their own entry points, but `tests/e2e/long-form-link-card.spec.ts:42-52` switches `fosse_object_type` to `wordpress-post-format` without an after cleanup. That leaves the suite safe only under current ordering plus later self-resetting specs, and it leaks into subsequent local runs when the server is reused.

Proposed tests/infrastructure:

- Add a global e2e cleanup helper that resets `atmosphere_connection`, `atmosphere_auto_publish`, `fosse_object_type`, wizard completion/destination options, and e2e capture artifacts before each test file.
- Add a first-test baseline assertion that `/wp-json/fosse-e2e/v1/object-type` or a small state endpoint reports `fosse_object_type=note` and disconnected Bluesky before a spec mutates state.
- Add `afterEach` cleanup to `long-form-link-card.spec.ts` to restore `fosse_object_type=note`.

### Medium: Unified Settings bad-nonce behavior is not tested

`tests/php/Admin/Setup_PageTest.php:342-370` verifies non-admin rejection, but the suite does not include a bad-nonce test for `Setup_Page::handle_save()`. The handler performs `check_admin_referer( self::SAVE_ACTION )` before option writes (`src/Admin/class-setup-page.php:78-84`), and the wizard/Bluesky handlers have explicit nonce coverage (`tests/php/Admin/Onboarding_WizardTest.php:1431-1523`, `tests/php/Admin/Bluesky_ProviderTest.php:1327-1364`, `tests/php/Admin/Bluesky_ProviderTest.php:1674-1692`).

Proposed test:

- Add `test_handle_save_rejects_bad_nonce()` to `tests/php/Admin/Setup_PageTest.php`.
- Seed an existing `activitypub_actor_mode`, submit a tampered `_wpnonce`, trap `wp_die`, and assert `activitypub_actor_mode`, `activitypub_support_post_types`, and `atmosphere_auto_publish` are unchanged.

### Medium: Test-only REST mutation endpoints have no permission/nonce coverage

The e2e mu-plugins expose state-changing endpoints guarded by `current_user_can( 'manage_options' )`: object type and Bluesky state (`tests/e2e/mu-plugins/fosse-bsky-capture.php:77-98`, `tests/e2e/mu-plugins/fosse-bsky-capture.php:119-178`) and reaction seeding (`tests/e2e/mu-plugins/fosse-reactions-seed.php:48-75`). Current specs only exercise the happy path with an admin nonce (`tests/e2e/reactions-display.spec.ts:28-45`, `tests/e2e/test-helpers.ts:27-43`). If a future blueprint/session change accidentally exposes these endpoints without auth, the suite would not catch it.

Proposed tests:

- Add `tests/e2e/e2e-helper-permissions.spec.ts`.
- Use an unauthenticated request context, POST to `/wp-json/fosse-e2e/v1/object-type`, `/wp-json/fosse-e2e/v1/bluesky-state`, and `/wp-json/fosse-e2e/v1/seed-reactions`, and assert 401/403.
- Also POST from an authenticated page without `X-WP-Nonce` and assert WordPress rejects the request.

### Medium: Valid-handle network failure on connect is not directly covered

Invalid handles assert no network call is made (`tests/php/Admin/Bluesky_ProviderTest.php:941-983`), and the OAuth callback path has token/PDS success and failure stubs (`tests/php/Admin/Bluesky_ProviderTest.php:741-880`, `tests/php/Admin/Bluesky_ProviderTest.php:1808-1888`). There is no test for the user-facing behavior when a syntactically valid handle reaches `Atmosphere\OAuth\Client::authorize()` and that authorize step returns a `WP_Error` because DNS/PDS/network is absent. The source branch redirects with the upstream error (`src/Admin/class-bluesky-provider.php:544-548`), but the suite does not assert the notice, redirect target, or absence of a wizard return transient for this failure mode.

Proposed test:

- Add `test_handle_connect_surfaces_authorize_wp_error_for_valid_handle()` in `tests/php/Admin/Bluesky_ProviderTest.php`.
- Submit `alice.bsky.social`, intercept `pre_http_request` to return `WP_Error`, trap redirect, assert an `error` settings notice under `atmosphere`, assert redirect goes back to the setup or wizard context as submitted, and assert no OAuth return-context transient is remembered.

### Low: CI does not actively shake out order assumptions

`phpunit.xml.dist:6` fixes `executionOrder="depends"` and the GitHub PHPUnit job simply runs `composer run-script test-php` (`.github/workflows/tests.yml:71-72`). The suite contains many global option/filter resets, and the e2e suite is intentionally serialized (`playwright.config.ts:8-11`). That is understandable for a WordPress plugin, but it means CI is not trying to expose hidden ordering assumptions.

Proposed CI/test additions:

- Add an optional or scheduled PHPUnit job with random order, for example `composer run-script test-php -- --order-by=random`, once current order dependencies are removed.
- Add a local-only documented command for `pnpm run test:e2e -- --workers=2` as an isolation stress test, even if the canonical CI job remains single-worker.

