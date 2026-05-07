# FOSSE Plugin Audit Report

Date: 2026-05-06  
Target: `origin/trunk` at `1437a14f3b3022050ba6625d31497828f581c07c`  
Scope: FOSSE plugin source, admin UI, tests, workflows, and Blurt integration feasibility. Bundled `activitypub/` and `atmosphere/` were not reviewed as owned code; they were inspected only as integration-contract references.

## Inputs

-   Audit plan: `audits/2026-05-06-fosse-plugin-audit-plan.md`
-   Agent notes:
    -   `audits/2026-05-06-agent-notes/security.md`
    -   `audits/2026-05-06-agent-notes/wp-standards-ux.md`
    -   `audits/2026-05-06-agent-notes/qa-tests.md`
    -   `audits/2026-05-06-agent-notes/federation-surface.md`
    -   `audits/2026-05-06-agent-notes/photoblog-blurt.md`
-   External protocol references used:
    -   WordPress `settings_errors()` reference: https://developer.wordpress.org/reference/functions/settings_errors/
    -   WordPress `add_settings_error()` reference: https://developer.wordpress.org/reference/functions/add_settings_error/
    -   Pixelfed ActivityPub docs: https://pixelfed.github.io/docs-next/spec/ActivityPub.html
    -   AT Protocol `app.bsky.feed.post` lexicon: https://raw.githubusercontent.com/bluesky-social/atproto/main/lexicons/app/bsky/feed/post.json
    -   AT Protocol `app.bsky.embed.images` lexicon: https://raw.githubusercontent.com/bluesky-social/atproto/main/lexicons/app/bsky/embed/images.json

## Executive Summary

No confirmed capability/nonce bypass, open redirect, token leakage, public well-known injection, bundled-loader path injection, or GitHub Actions privilege escalation was found in scoped FOSSE code.

The highest-priority issues are:

1. Bluesky/OAuth error notices can store remote/upstream error text and render it through WordPress `settings_errors()` without escaping.
2. The `status-page.spec.ts` e2e cleanup uses a raw Playwright page without `baseURL`, contradicting the shared helper and risking leaked Bluesky state.
3. The onboarding wizard content save path does not filter nested arrays before `sanitize_text_field()`, unlike the hardened Settings path.
4. Cross-network shape settings can diverge because FOSSE leaves ActivityPub's native object-type option active when `fosse_object_type` is unset.
5. FOSSE overrides Atmosphere's native long-form setting while leaving the native Atmosphere UI reachable.
6. Blurt is close to a good local photoblog UI, but Blurt + current FOSSE is not yet a reliable Pixelfed-facing photoblog because Blurt parented galleries are not visible to ActivityPub attachment extraction, and FOSSE does not expose a photoblog/Note/media-readiness mode.

Runtime verification could not be completed in this sandbox because `php`, `composer`, `pnpm`, `npm`, and `corepack` are not available on PATH, and `vendor/`, `tools/vendor/`, and `node_modules/` are absent.

## Confirmed Findings

### P1/P2: Bluesky/OAuth error notices can render unescaped error text

Classification: security / escaping bug. Exploitability depends on whether upstream OAuth/PDS/publication error messages can carry attacker-controlled HTML, but FOSSE currently treats those messages as trusted.

Evidence:

-   `src/Admin/class-bluesky-provider.php:547` passes `$auth_url->get_error_message()` directly to `redirect_with_notice()`.
-   `src/Admin/class-bluesky-provider.php:725` passes OAuth callback `WP_Error` text directly to `redirect_with_notice()`.
-   `src/Admin/class-bluesky-provider.php:755-759` interpolates publication setup error text directly into the notice message.
-   `src/Admin/class-bluesky-provider.php:787-789` stores the message with `add_settings_error( 'atmosphere', ... )`.
-   `src/Admin/class-bluesky-provider.php:183` renders that group with `settings_errors( 'atmosphere' )`.
-   WordPress documents `settings_errors()` as outputting the stored `message` field inside the notice markup, while escaping only the code/type attributes.
-   The FOSSE wizard path renders the same `atmosphere` notice group with `esc_html()` at `src/Admin/class-onboarding-wizard.php:1254-1264`, so there is already a safe local precedent.

Impact:

An OAuth/PDS/publication failure message containing HTML can become wp-admin markup on the FOSSE Settings page. This is especially sensitive because Bluesky connect handles can point at user-controlled domains/PDS infrastructure.

Recommendation:

-   Store escaped/plain text in `redirect_with_notice()` before `add_settings_error()`, or stop using `settings_errors()` for this group and render notices through a FOSSE-owned escaped renderer.
-   Add PHPUnit coverage where `Atmosphere\OAuth\Client::authorize()`, `handle_callback()`, and `Publisher::sync_publication()` return `WP_Error( ..., '<img onerror=...>' )`, then assert the Settings page output escapes it.

### P2: `status-page.spec.ts` afterAll cleanup uses a page without `baseURL`

Classification: broken / flaky test harness.

Evidence:

-   `tests/e2e/status-page.spec.ts:30-32` calls `browser.newPage()` and then `page.goto( '/wp-admin/post-new.php' )`.
-   The shared helper explicitly documents that `browser.newPage()` does not inherit project `baseURL` and that relative `page.goto()` fails without it (`tests/e2e/test-helpers.ts:50-64`).
-   The same cleanup is supposed to reset `atmosphere_connection` after the status-page tests (`tests/e2e/status-page.spec.ts:36-39`).

Impact:

If the cleanup fails, connected Bluesky state can leak into later specs or into a reused local Playground server. That undermines the reliability of the suite and can mask or create wizard/status failures.

Recommendation:

-   Replace the local cleanup with `resetBlueskyState( browser, testInfo.project.use.baseURL )`, mirroring `tests/e2e/bluesky-provider.spec.ts`.
-   Remove the duplicate local Bluesky state helper or make it share the same baseURL-safe page creation.

### P2: Onboarding content save can warn on nested post-type payloads

Classification: input-shape hardening bug and missing sad-path test.

Evidence:

-   `src/Admin/class-onboarding-wizard.php:303-306` casts `$_POST['activitypub_support_post_types']` to an array and maps `sanitize_text_field()` over it directly.
-   `src/Admin/class-setup-page.php:135-145` has the hardened version of the same operation: it filters to strings before calling `sanitize_text_field()` to avoid nested-array warnings.
-   The QA notes found Settings tests for nested-array/non-array payloads, but not equivalent wizard content-step coverage.

Impact:

A malformed admin POST such as `activitypub_support_post_types[0][]=post` can trigger an array-to-string warning in the wizard path. PHPUnit is configured to fail on warnings, and production should not emit avoidable warnings from malformed input even behind nonce/capability checks.

Recommendation:

-   Mirror `Setup_Page::save_general_settings()` by filtering to `is_string` before `sanitize_text_field()`.
-   Add `Onboarding_WizardTest` coverage for nested arrays, scalar payloads, invalid-only input, no option overwrite, and redirect to `step=content&error=empty_post_types`.

### P2: ActivityPub native object-type settings can desync AP and Bluesky shape

Classification: cross-network integration defect.

Evidence:

-   FOSSE registers both network projectors at `src/class-object-type.php:50-52`.
-   FOSSE only forces both networks when `get_option( 'fosse_object_type' ) === 'note'`; otherwise it passes upstream values through (`src/class-object-type.php:66-93`).
-   ActivityPub computes type from `activitypub_object_type` before FOSSE's filter runs (`bundled/activitypub/includes/transformer/class-post.php:453-483`).
-   Atmosphere decides short/long form independently from title support/title/post format (`bundled/atmosphere/includes/transformer/class-post.php:112-133`).
-   FOSSE leaves direct native settings access available and links to advanced ActivityPub settings (`src/Admin/class-ap-provider.php:181-184`).

Impact:

On an upgraded site or a power-user visit to ActivityPub settings, `activitypub_object_type=note` can make AP publish as `Note` while Bluesky still treats the same titled post as long-form unless hidden `fosse_object_type` is also set. That breaks the FOSSE promise that one publishing policy applies across networks.

Recommendation:

-   Make one source canonical. Either mirror/repair ActivityPub's option into `fosse_object_type`, or treat the AP option as the fallback input when `fosse_object_type` is unset.
-   Add a visible FOSSE setting/status row for the effective object-shape policy.
-   Add regression coverage for `activitypub_object_type=note` with `fosse_object_type` unset.

### P2: FOSSE overrides Atmosphere's long-form setting while the native UI remains reachable

Classification: cross-network integration/UI defect.

Evidence:

-   Atmosphere seeds `atmosphere_long_form_composition` from its option at priority 1 (`bundled/atmosphere/includes/class-atmosphere.php:70-75`, `bundled/atmosphere/includes/class-atmosphere.php:683-701`).
-   FOSSE registers later at priority 10 (`src/class-long-form-strategy.php:75-76`).
-   FOSSE ignores the incoming strategy and returns hidden `fosse_long_form_strategy` or default `teaser-thread` (`src/class-long-form-strategy.php:96-105`).
-   FOSSE Settings renders post types and actor mode only (`src/Admin/templates/setup-page.php:52-170`), and saves only `activitypub_support_post_types` in the general section (`src/Admin/class-setup-page.php:134-148`).

Impact:

A user can save Atmosphere's native long-form setting and still have FOSSE publish long-form posts using FOSSE's hidden default. The visible native control becomes misleading under FOSSE.

Recommendation:

-   Reuse Atmosphere's option as the canonical storage key, or expose the FOSSE-owned long-form strategy in FOSSE and suppress/redirect the native Atmosphere control when bundled under FOSSE.
-   Add status visibility for the effective strategy and its source.

## Security Review

Confirmed issue: the notice escaping bug above.

High-risk vectors checked with no confirmed finding:

-   Settings save CSRF/capability bypass: FOSSE Settings checks `manage_options` and nonce before writes (`src/Admin/class-setup-page.php:78-99`).
-   Wizard save CSRF/capability bypass: wizard save checks capability and nonce before step writes (`src/Admin/class-onboarding-wizard.php:225-322`).
-   Bluesky connect/disconnect/auto-publish bypass: handlers check `manage_options` and nonces (`src/Admin/class-bluesky-provider.php:511-553`, `562-587`, `602-615`).
-   OAuth callback state confusion: callback requires page/code/state, capability, and consumes return context only on state match (`src/Admin/class-bluesky-provider.php:686-725`, `887-908`).
-   Open redirect: FOSSE uses `wp_safe_redirect()` for internal redirects; the one `wp_redirect()` is the intentional OAuth handoff after capability/nonce/handle checks (`src/Admin/class-bluesky-provider.php:511-553`).
-   Public `/.well-known/atproto-did` injection: handler path-matches strictly, serves `text/plain`, requires connection, and validates DID syntax before echoing (`src/Admin/class-bluesky-provider.php:388-469`).
-   Workflow privilege escalation: test/lint/e2e workflows use `pull_request` with read permissions; build uses read-only artifact production before write-scoped release publication.

## WordPress Coding, Accessibility, And Admin UX

Standards/accessibility gaps to address after the confirmed escaping issue:

-   Raw fixed class/attribute fragments are echoed in admin templates and provider cards. They are not user-input bugs today, but WPCS-style code should build full strings and pass through `esc_attr()`.
-   `src/Admin/class-bluesky-provider.php:672` contains inline form styling despite `src/Admin/assets/css/admin.css` being enqueued for FOSSE screens.
-   Wizard radio/checkbox groups are card-based `div` structures without semantic `fieldset`/`legend`, unlike the Settings page (`src/Admin/class-onboarding-wizard.php:862-880`, `998-1020`, `1143-1188`; compare `src/Admin/templates/setup-page.php:61-77`, `100-165`).
-   Status/review key-value tables use `td` label cells instead of `th scope="row"`.
-   Decorative Dashicons in wizard cards are not marked `aria-hidden` (`src/Admin/class-onboarding-wizard.php:876-878`, `1008-1016`, `1432-1434`).
-   Small wizard text misses normal-text contrast targets: `#949494` and `#4ab866` on white for 12px progress labels, and `#757575` on `#f0f0f0` for 12px hints (`src/Admin/assets/css/admin.css:169-184`, `276-287`).

Modern WP/product design gaps:

-   Settings and Status do not show effective `fosse_object_type` or `fosse_long_form_strategy`, even though those hidden options materially change federation shape.
-   Token error recovery is clearer on Status than Settings. Settings shows token health but only offers disconnect; Status gives stronger recovery copy and a link (`src/Admin/class-bluesky-provider.php:238-248`, `317-325`).
-   Status page computes Bluesky token health through `get_status()` and can call it more than once during render (`src/Admin/class-bluesky-provider.php:105-123`; `src/Admin/templates/status-page.php:17-20`, `46-50`).
-   State-changing wizard actions are nonced GET links. This is not a CSRF finding, but POST buttons/forms better match admin UX expectations for mutation.
-   Unavailable-backend copy tells users to install/activate ActivityPub/Atmosphere, but FOSSE bundles them; a diagnostics panel should identify which class/function failed to load.

## QA And Test Quality

Bad tests / weak tests:

-   `status-page.spec.ts` cleanup is broken as described above.
-   `tests/js/fosse.test.js` is only a smoke test and does not exercise shipped `src/Admin/assets/js/wizard-appearance.js`.
-   Bluesky facet/link-card e2e specs use a capture mu-plugin that records transformed records before standing up real OAuth/PDS publication. Useful, but those tests should be labeled as transformer/projector coverage, not proof that the publish path attempts network writes.
-   `test_handle_connect_strips_leading_at()` only asserts the captured URL does not contain `@alice`; it should also assert that the normalized handle appears and `%40alice` does not.

Missing coverage:

-   Public HTTP e2e coverage for `/.well-known/atproto-did`, including connected, disconnected, encoded/lookalike paths, trailing slash, and opt-out filter behavior.
-   Wizard content invalid-shape tests equivalent to Settings nested-array/non-array tests.
-   Bad-nonce coverage for unified Settings save.
-   Test-only e2e REST endpoints should have unauthenticated and no-nonce rejection tests.
-   Valid-handle Bluesky connect with network/PDS `WP_Error` should assert redirect, notice, and no stale OAuth return transient.
-   E2E state isolation needs a global cleanup/baseline fixture for `atmosphere_connection`, `atmosphere_auto_publish`, `fosse_object_type`, wizard completion/destination options, and capture artifacts.
-   Add optional PHPUnit random-order CI and a documented e2e isolation stress command once order assumptions are cleaned up.

Recommended additions for protocol alignment:

-   ActivityPub media object test: create a post with multiple image attachments, alt text, tags, and optional content warning; assert outgoing object type, ordered `Image` attachments, `name`/alt, `Hashtag` tags, and `sensitive`/`summary`.
-   Bluesky image path test once upstream Atmosphere supports `app.bsky.embed.images`: assert image count, alt, size fallback, and failure behavior.
-   Real publish-path e2e test with a test-only `pre_http_request` recorder so FOSSE can detect that Atmosphere attempted the expected PDS writes.

## Federation And Network Semantics

Positive alignment:

-   FOSSE has a good primitive for cross-network short-form policy: `fosse_object_type=note` forces ActivityPub `Note` and Atmosphere short-form (`src/class-object-type.php:66-93`).
-   Post type selection is intentionally unified through ActivityPub's `activitypub_support_post_types` and projected into Atmosphere (`src/class-post-types.php:11-23`, `69-75`).
-   Reactions label work appears scoped correctly: FOSSE relabels the ActivityPub reactions block while the bundled render path counts approved top-level comments across protocols.

Surface areas to adopt/work on:

-   Add a FOSSE-specific `fosse_syncable_post_types` filter after AP-option projection. Current projection discards Atmosphere's own option and `add_post_type_support( 'atmosphere' )` merge (`src/class-post-types.php:69-75`; bundled reference `bundled/atmosphere/includes/class-post-types.php:25-47`).
-   Add FOSSE-visible object-shape and long-form policy UI/status.
-   Decide cross-network privacy policy. ActivityPub has audience/visibility semantics, while Bluesky publication is essentially public once WordPress status and Atmosphere gates pass.
-   Surface Bluesky publish/update/delete failures and pending repair/orphan state in FOSSE status once upstream Atmosphere exposes durable APIs.
-   Decide how FOSSE should present Bluesky replies in WordPress comments, including protocol badges and reply-back limitations.
-   Finish domain-handle setup UX beyond the served `/.well-known/atproto-did` route.

AT Protocol current-state note:

-   Current `app.bsky.feed.post` lexicon includes an `embed` union that can contain `app.bsky.embed.images`; current `app.bsky.embed.images` requires images with alt text and caps the array at four images. FOSSE/Atmosphere currently do not produce that image embed path.

Pixelfed current-state note:

-   Pixelfed's ActivityPub docs describe `Create.Note` objects becoming statuses and show image attachments on `Note` objects, with `sensitive`/`summary` used for content warnings. This makes `Note` plus ordered `Image` attachments with alt text the right target shape for a Pixelfed-oriented FOSSE/Blurt photoblog.

## Photoblog And Blurt Assessment

Blurt strengths:

-   The compose dialog supports up to four images (`/Users/kraft/code/wpcom-a8c-themes/blurt/footer.php:81-105`).
-   Blurt has per-image alt and caption editing (`/Users/kraft/code/wpcom-a8c-themes/blurt/js/blurt.js:2471-2577`; `/Users/kraft/code/wpcom-a8c-themes/blurt/functions.php:4255-4285`).
-   Blurt stores gallery order through `menu_order` and retrieves parented attachments for local galleries (`/Users/kraft/code/wpcom-a8c-themes/blurt/functions.php:4295-4365`).
-   Blurt sets `activitypub_max_image_attachments=4` when enabling federation (`/Users/kraft/code/wpcom-a8c-themes/blurt/functions.php:525-537`), which matches common four-image album expectations.

Blurt + FOSSE blockers for a Pixelfed-ready photoblog:

-   Blurt rejects photo-only posts in both JS and PHP (`/Users/kraft/code/wpcom-a8c-themes/blurt/js/blurt.js:2224-2229`; `/Users/kraft/code/wpcom-a8c-themes/blurt/functions.php:2386-2390`).
-   Blurt creates normal posts with generated titles (`/Users/kraft/code/wpcom-a8c-themes/blurt/functions.php:2398-2406`, `3477-3503`), which pushes ActivityPub's default discriminator toward `Article` unless FOSSE note mode/post format intervenes.
-   Blurt attaches uploaded images as child media via `post_parent` and `menu_order` (`/Users/kraft/code/wpcom-a8c-themes/blurt/functions.php:2422-2425`, `4295-4344`).
-   ActivityPub attachment extraction uses featured image, enclosures, blocks, inline HTML images, and `activitypub_attachment_ids`; it does not automatically query Blurt-style parented galleries (`bundled/activitypub/includes/transformer/class-post.php:371-440`).
-   FOSSE does not expose a photoblog mode, object type setting, media readiness check, alt text readiness, or content warning/sensitive control.
-   Atmosphere currently emits text-only short-form records or external link cards with at most a thumbnail; no `app.bsky.embed.images` support exists in the bundled path (`bundled/atmosphere/includes/transformer/class-post.php:112-190`, `255-325`).

Practical first working path:

1. In Blurt federation enablement, set `fosse_object_type=note` and keep the four-image AP cap.
2. Bridge Blurt parented media into ActivityPub via `activitypub_attachment_ids` in `menu_order` order, or upstream a careful child-attachment extraction primitive to ActivityPub.
3. Allow image-only posts and avoid auto-generated titles for empty-caption photo posts, or force Note mode for Blurt-authored posts.
4. Add missing-alt warnings before publish.
5. Add a CW/sensitive control that writes `activitypub_content_warning`.
6. Add FOSSE/Blurt tests for Pixelfed target shape: `Note`, ordered image attachments, alt text, tags, and sensitive summary.
7. Upstream Atmosphere image embed support for coherent Bluesky photoblogging.

## Verification Attempted

Commands attempted:

-   `composer run-script test-php`
-   `composer run-script lint-php`
-   `pnpm test`
-   `pnpm run lint`
-   `pnpm run format:check`
-   `pnpm run test:e2e`

Result:

-   All PHP/composer commands failed because `composer` and `php` are not available on PATH.
-   All pnpm commands failed because `pnpm`, `npm`, and `corepack` are not available on PATH.
-   Dependency directories are absent: `vendor/`, `tools/vendor/`, and `node_modules/`.

This audit is therefore static plus source-contract review. The identified issues should be validated again after a working PHP/Composer/pnpm environment is available.

## Recommended Backlog

Immediate:

1. Escape or safely render Bluesky/OAuth settings notices.
2. Fix `status-page.spec.ts` cleanup to use `resetBlueskyState()` with baseURL.
3. Harden onboarding content post-type sanitization and add malformed-payload tests.
4. Add bad-nonce coverage for Settings save.

Next:

5. Make object-type policy canonical and visible in FOSSE.
6. Make long-form strategy canonical and visible, or suppress misleading native Atmosphere controls.
7. Add public `/.well-known/atproto-did` e2e lifecycle coverage.
8. Replace JS smoke test with real wizard JS tests.
9. Add e2e state reset/baseline infrastructure.

Photoblog:

10. Add a FOSSE/Blurt photoblog mode or compatibility layer that forces Note shape and bridges Blurt parented galleries to ActivityPub attachments.
11. Add Pixelfed-shape tests for image attachments, alt, order, tags, and content warnings.
12. Pursue upstream Atmosphere `app.bsky.embed.images` support and then add FOSSE tests against it.

Product/status:

13. Surface effective object type, long-form strategy, media readiness, token health, publish failures, and pending repair state in Status.
14. Add diagnostics for bundled backend availability.
15. Decide and document cross-network privacy behavior.
