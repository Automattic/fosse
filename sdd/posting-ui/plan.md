# Implementation Plan: Posting UI

Based on: `sdd/posting-ui/spec.md`

## Progress

- [ ] Task 1: Source-URL classifier helper + tests
- [ ] Task 2: Post composer admin page (FOSSE → Post)
- [ ] Task 3: Composer submit handler (quick-note path + reply redirect)
- [ ] Task 4: Admin bar entry
- [ ] Task 5: Bookmarklet generator on Settings page
- [ ] Task 6: e2e coverage
- [ ] Task 7: Atmosphere upstream coordination (file issues, do not block)
- [ ] Task 8: Update SDD implementation notes
- [ ] Task 9: Run verification

## Tasks

### Task 1: Source-URL classifier helper + tests

- **Status**: Not started
- **Files**:
  - Create: `src/Admin/class-source-url-classifier.php`
  - Create: `tests/php/Admin/Source_URL_ClassifierTest.php`
- **Do**:
  1. Add `Automattic\Fosse\Admin\Source_URL_Classifier` with `public static function classify( string $url ): string` returning one of `'activitypub' | 'bluesky' | 'unrecognized'`.
  2. AP detection rules: WebFinger-style URL (`https://<host>/@<handle>`), Mastodon post URL (`https://<host>/@<handle>/<post-id>`), generic ActivityPub URL with `/users/<handle>` or `/notes/<id>` patterns. Conservative — when in doubt, return `'unrecognized'`. Server-side fetching is out of scope; classification is pattern-only.
  3. Bluesky detection rules: `bsky.app/profile/<handle>/post/<rkey>`, `<handle>.bsky.social/post/<rkey>`, `at://did:plc:<did>/app.bsky.feed.post/<rkey>` AT-URI form.
  4. Tests: each rule's positive case, each rule's negative case (similar but not matching URL), `null`/empty input → `'unrecognized'`.
- **Verify**:
  - `composer run-script test-php -- --filter Source_URL_ClassifierTest` passes.
  - `composer run-script lint-php -- src/Admin/class-source-url-classifier.php tests/php/Admin/Source_URL_ClassifierTest.php`
- **Depends on**: none

### Task 2: Post composer admin page (FOSSE → Post)

- **Status**: Not started
- **Files**:
  - Create: `src/Admin/class-post-page.php`
  - Create: `src/Admin/templates/post-page.php`
  - Modify: `src/Admin/class-menu.php` (register `fosse-post` submenu)
  - Create: `tests/php/Admin/Post_PageTest.php`
- **Do**:
  1. Add `Automattic\Fosse\Admin\Post_Page` with `register_hooks()`, `render()`.
  2. Submenu slug: `fosse-post`. Title: `Post`. Capability: `publish_posts`.
  3. Render reads URL params: `source_url` (raw, then validated/sanitized for display), `selection` (sanitized as text, capped at 500 chars).
  4. Template fields: textarea (autofocus), source-URL input (pre-filled from query param), photo file input, alt-text input (only required if photo attached), Publish button.
  5. Form action posts to `admin-post.php` with action `fosse_post_publish` and a nonce.
  6. Tests assert: page renders without fatal at `?page=fosse-post`; URL params populate the form fields; capability check rejects users without `publish_posts`.
- **Verify**:
  - `composer run-script test-php -- --filter Post_PageTest`
  - `composer run-script lint-php`
- **Depends on**: Task 1 (composer can construct compose links if it wants to preview the redirect, though strictly only Task 3 needs this)

### Task 3: Composer submit handler

- **Status**: Not started
- **Files**:
  - Modify: `src/Admin/class-post-page.php` (add `handle_publish()` admin-post action)
  - Modify: `tests/php/Admin/Post_PageTest.php`
- **Do**:
  1. `Post_Page::handle_publish()` hooked on `admin_post_fosse_post_publish`.
  2. Verify nonce + capability.
  3. If `source_url` is non-empty, classify via Task 1's helper:
     - `'activitypub'`: `wp_safe_redirect( admin_url( 'post-new.php?post_format=status&in_reply_to=' . rawurlencode( $source_url ) ) )`. Bundled AP takes over.
     - `'bluesky'`: same redirect target but to the URL the upstream Atmosphere PR will define. Until upstream lands, surface a notice via `add_settings_error()` and re-render the composer (don't redirect to a broken target).
     - `'unrecognized'`: surface notice "Source URL not recognized as a federated post" and proceed with quick-note path (treat as no source URL).
  4. Quick-note path:
     - Validate text non-empty OR photo attached.
     - If photo: validate alt-text non-empty.
     - `wp_insert_post()` with `post_format='status'`, content = paragraph block wrapping the text.
     - If photo: `media_handle_upload()`, `set_post_thumbnail()`, `update_post_meta('_wp_attachment_image_alt', alt_text)` on the attachment.
     - `wp_safe_redirect( get_permalink( $post_id ) )`.
  5. Validation failures: `add_settings_error()`, `wp_safe_redirect( admin_url( 'admin.php?page=fosse-post&...preserved-fields' ) )`.
  6. Tests: each path has a positive case + a validation-failure case.
- **Verify**:
  - `composer run-script test-php -- --filter Post_PageTest`
  - `composer run-script lint-php`
- **Depends on**: Task 2

### Task 4: Admin bar entry

- **Status**: Not started
- **Files**:
  - Modify: `src/Admin/class-menu.php` OR create `src/Admin/class-admin-bar.php`
  - Modify: `tests/php/Admin/MenuTest.php` OR new `Admin_BarTest.php`
- **Do**:
  1. Hook `admin_bar_menu` at default priority.
  2. Add a node: `id='fosse-post'`, `title='Post'`, `href=admin_url('admin.php?page=fosse-post')`, `parent=null` (top-level admin bar).
  3. Capability gate: `publish_posts`.
  4. Test: node present in admin bar HTML for users with `publish_posts`, absent for users without.
- **Verify**:
  - `composer run-script test-php -- --filter '(Menu|Admin_Bar)Test'`
  - `composer run-script lint-php`
- **Depends on**: Task 2

### Task 5: Bookmarklet generator on Settings page

- **Status**: Not started
- **Files**:
  - Modify: `src/Admin/templates/setup-page.php` (add a "Bookmarklet" section)
  - Modify: `src/Admin/class-setup-page.php` if helper logic needed (URL escaping, etc.)
  - Create: `tests/php/Admin/Bookmarklet_RenderTest.php`
- **Do**:
  1. Add a Bookmarklet section to the FOSSE Settings page, below the General/Federation sections.
  2. Render the bookmarklet snippet as the `href` of an anchor tag styled like a button: `<a href="javascript:..." class="button">FOSSE: Post this</a>` plus a "Drag this link to your bookmarks bar" hint.
  3. The bookmarklet body interpolates the current site URL (`admin_url('admin.php?page=fosse-post')`). The user-facing URL never includes auth — the bookmark just constructs the wp-admin URL.
  4. Add a copy-to-clipboard fallback below the link for browsers / contexts where dragging a `javascript:` URL doesn't work.
  5. Tests: the bookmarklet snippet contains the current admin URL; the snippet contains `encodeURIComponent(location.href)`; capability gate (`publish_posts`).
- **Verify**:
  - `composer run-script test-php -- --filter Bookmarklet_RenderTest`
  - `composer run-script lint-php`
- **Depends on**: Task 2 (the bookmarklet target URL points at the composer page)

### Task 6: e2e coverage

- **Status**: Not started
- **Files**:
  - Create: `tests/e2e/posting-ui.spec.ts`
- **Do**:
  1. Smoke: log in as admin, navigate via admin bar to FOSSE → Post, type text, click Publish, assert redirect to a published post permalink.
  2. Validation: submit empty form → assert error notice; submit with image but no alt-text → assert error notice with text + image preserved.
  3. Reply path: navigate to FOSSE → Post with `?source_url=<mastodon-test-url>`, click Publish, assert redirect to `post-new.php?post_format=status&in_reply_to=<url>` (don't need to verify AP's reply-block insertion in this spec; that's bundled AP's responsibility).
  4. Bluesky reply fallback: navigate with a `bsky.app` URL, click Publish, assert the "Bluesky reply support requires Atmosphere pre-release" notice surfaces and the composer re-renders.
  5. Bookmarklet snippet present on Settings page with the current site URL.
- **Verify**:
  - `pnpm exec playwright test tests/e2e/posting-ui.spec.ts`
- **Depends on**: Tasks 2, 3, 4, 5

### Task 7: Atmosphere upstream coordination

- **Status**: Not started
- **Files**: none in this repo
- **Do**:
  1. File three issues against `Automattic/wordpress-atmosphere` (or whichever the canonical Atmosphere upstream repo is):
     - "Add `atmosphere/reply` block (parity with `activitypub/reply`)"
     - "Add reply-intent JS plugin handling `?in_reply_to=<bsky-url>`"
     - "Add pre-publish federation sidebar panel"
  2. Each issue body should reference this SDD and explain that FOSSE consumes the result via `tools/sync-bundled.sh`.
  3. Add a note to `sdd/posting-ui/implementation-notes.md` (created in Task 8) tracking the upstream issue numbers and their states.
- **Verify**:
  - Three issues exist on the Atmosphere repo. Track in implementation-notes.md.
- **Depends on**: spec landed; can be done in parallel with implementation. Not a blocker for this PR landing.

### Task 8: Update SDD implementation notes

- **Status**: Not started
- **Files**:
  - Create: `sdd/posting-ui/implementation-notes.md`
  - Modify: `sdd/posting-ui/plan.md`
- **Do**:
  1. Record any implementation deviations.
  2. Track the Atmosphere upstream issue numbers from Task 7 and their status.
  3. Update task statuses in this plan as each ships.
- **Verify**:
  - implementation-notes.md exists.
  - Plan checklist sync.
- **Depends on**: implementation tasks as they complete

### Task 9: Run verification

- **Status**: Not started
- **Files**: none
- **Do**:
  1. Targeted PHPUnit while developing:
     - `composer run-script test-php -- --filter Source_URL_ClassifierTest`
     - `composer run-script test-php -- --filter Post_PageTest`
     - `composer run-script test-php -- --filter Bookmarklet_RenderTest`
  2. Broader: `composer run-script test-php`.
  3. Lint: `composer run-script lint-php`, `pnpm run format:check`, `pnpm run lint`.
  4. e2e: `pnpm exec playwright test tests/e2e/posting-ui.spec.ts`.
- **Verify**:
  - All commands pass, or failures recorded in implementation-notes.md.
- **Depends on**: Tasks 1-8
