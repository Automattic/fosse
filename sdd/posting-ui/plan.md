# Implementation Plan: FOSSE-Native Posting UI

Based on: `sdd/posting-ui/spec.md`

## Progress

- [ ] Task 1: Add FOSSE Post admin page shell
- [ ] Task 2: Create status-post publishing service
- [ ] Task 3: Add photo upload and alt-text persistence
- [ ] Task 4: Add composer JavaScript, styling, and accessibility polish
- [ ] Task 5: Add send-status panel and retry UI contract
- [ ] Task 6: Add quick-entry / Press This-style path
- [ ] Task 7: Complete verification and SDD updates

## Tasks

### Task 1: Add FOSSE Post admin page shell

- **Status**: Not started
- **Files**:
  - Modify: `src/Admin/class-menu.php`
  - Create: `src/Admin/class-posting-page.php`
  - Create: `src/Admin/templates/posting-page.php`
  - Modify: `tests/php/Admin/MenuTest.php`
  - Create: `tests/php/Admin/Posting_PageTest.php`
- **Do**:
  1. Add `Posting_Page::register_hooks()` to `Menu::register()`.
  2. Add a FOSSE submenu page after Settings:
     - parent slug: `fosse`
     - page title/menu title: `Post`
     - capability: match existing FOSSE admin pages for MVP (`manage_options`) unless the implementation deliberately widens to `publish_posts`.
     - slug: `fosse-post`
     - callback: `Posting_Page::render`
  3. Create `Posting_Page` with:
     - `PUBLISH_ACTION = 'fosse_publish_note'`
     - `RETRY_ACTION = 'fosse_retry_publication'`
     - `register_hooks()`
     - `render()`
     - initial `handle_publish()` that validates capability/nonce and redirects back with a controlled notice until Task 2 wires real publishing.
  4. Create `posting-page.php` with the usable composer shell:
     - textarea `name="fosse_note_text"`
     - file input `name="fosse_photo"` with `accept="image/*"`
     - text input or textarea `name="fosse_photo_alt"`
     - submit button text `Post`
     - hidden `action` and nonce fields
     - `enctype="multipart/form-data"`
  5. Add PHPUnit coverage that the page renders the form, nonce action, textarea, file input, alt input, and submit button.
  6. Add menu tests that the `fosse-post` submenu is registered.
- **Verify**:
  - `composer run-script test-php -- --filter 'Posting_PageTest|MenuTest'`
  - `composer run-script lint-php`
- **Depends on**: none

### Task 2: Create status-post publishing service

- **Status**: Not started
- **Files**:
  - Create: `src/class-posting-service.php`
  - Modify: `src/Admin/class-posting-page.php`
  - Modify: `src/Admin/templates/posting-page.php`
  - Create: `tests/php/Posting_ServiceTest.php`
  - Modify: `tests/php/Admin/Posting_PageTest.php`
- **Do**:
  1. Create `Automattic\Fosse\Posting_Service`.
  2. Add `create_note( array $payload ): int|\WP_Error`.
  3. Validate text:
     - trim whitespace;
     - reject empty text;
     - count grapheme clusters with `grapheme_strlen()` when available, otherwise `mb_strlen()`;
     - reject more than 300 graphemes.
  4. Insert a normal post:
     - `post_type => 'post'`
     - `post_status => 'publish'`
     - `post_author => get_current_user_id()`
     - `post_title => ''`
     - `post_content =>` sanitized paragraph/block content for the note text; escape again only when rendering
  5. Call `set_post_format( $post_id, 'status' )` after insertion.
  6. Replace the initial `Posting_Page::handle_publish()` notice branch with the service call and redirect to `admin.php?page=fosse-post&posted=<post_id>` on success.
  7. Render validation failures through the existing WordPress `settings_errors` transient pattern used by FOSSE admin handlers.
  8. Add PHPUnit coverage:
     - text-only publish creates a `post`;
     - post title remains empty;
     - post status is `publish`;
     - post format is `status`;
     - current user is the author;
     - empty text fails;
     - over-300 text fails;
     - successful handler redirects to `page=fosse-post&posted=<id>`.
- **Verify**:
  - `composer run-script test-php -- --filter 'Posting_ServiceTest|Posting_PageTest'`
  - `composer run-script lint-php`
- **Depends on**: Task 1

### Task 3: Add photo upload and alt-text persistence

- **Status**: Not started
- **Files**:
  - Modify: `src/class-posting-service.php`
  - Modify: `src/Admin/class-posting-page.php`
  - Modify: `src/Admin/templates/posting-page.php`
  - Modify: `tests/php/Posting_ServiceTest.php`
  - Modify: `tests/php/Admin/Posting_PageTest.php`
- **Do**:
  1. Extend `Posting_Service::create_note()` to accept an uploaded file array and alt text.
  2. If no file was uploaded, ignore empty alt text and create a text-only status post.
  3. If a file was uploaded, require non-empty alt text before inserting media.
  4. Load WordPress media helpers inside the service when needed:
     - `wp-admin/includes/file.php`
     - `wp-admin/includes/media.php`
     - `wp-admin/includes/image.php`
  5. Use `media_handle_upload( 'fosse_photo', $post_id )` or an equivalent testable wrapper to create the attachment.
  6. Validate that the attachment mime type is an image.
  7. Store the alt text with `update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text )`.
  8. Set the attachment as featured image with `set_post_thumbnail( $post_id, $attachment_id )`.
  9. Update post content so the note text remains first and the uploaded image block follows it.
  10. Add PHPUnit coverage:
      - image upload without alt text fails;
      - image upload with alt text stores `_wp_attachment_image_alt`;
      - uploaded attachment is parented to the post;
      - `_thumbnail_id` points to the attachment;
      - post content contains the image block or attachment reference.
- **Verify**:
  - `composer run-script test-php -- --filter 'Posting_ServiceTest|Posting_PageTest'`
  - `composer run-script lint-php`
- **Depends on**: Task 2

### Task 4: Add composer JavaScript, styling, and accessibility polish

- **Status**: Not started
- **Files**:
  - Modify: `src/Admin/class-menu.php`
  - Create: `src/Admin/assets/js/composer.js`
  - Modify: `src/Admin/assets/css/admin.css`
  - Modify: `src/Admin/templates/posting-page.php`
  - Create: `tests/js/posting-composer.test.js`
  - Create: `tests/e2e/posting-ui.spec.ts`
- **Do**:
  1. Enqueue `fosse-composer` script only on the FOSSE Post admin screen.
  2. Keep the JavaScript build-free, following `wizard-appearance.js`.
  3. Add a live 300-grapheme counter using `Intl.Segmenter` when available and a conservative fallback otherwise.
  4. Disable the submit button client-side when the note is empty, over 300 graphemes, or a photo is selected without alt text. Server validation from Tasks 2 and 3 remains authoritative.
  5. Show an image preview after file selection.
  6. Make the alt-text control visible and focusable when a photo is selected. Keep it present in non-JavaScript markup.
  7. Add `fosse-composer` prefixed CSS for compact layout, preview, validation hints, status rows, and narrow admin widths. Do not introduce a one-hue decorative palette or nested cards.
  8. Add Jest coverage for grapheme counting and photo/alt UI state.
  9. Add Playwright coverage:
     - FOSSE Post page loads without fatal errors;
     - text-only post can be submitted;
     - over-limit text blocks submit and shows validation;
     - selecting an image exposes alt text;
     - image without alt text shows validation;
     - mobile viewport has no horizontal overflow.
- **Verify**:
  - `pnpm test -- posting-composer`
  - `pnpm run format:check`
  - `pnpm run lint`
  - `pnpm exec playwright test tests/e2e/posting-ui.spec.ts`
  - `composer run-script lint-php`
- **Depends on**: Task 3

### Task 5: Add send-status panel and retry UI contract

- **Status**: Not started
- **Files**:
  - Modify: `src/Admin/class-posting-page.php`
  - Modify: `src/Admin/templates/posting-page.php`
  - Create: `tests/e2e/mu-plugins/fosse-publication-status-seed.php`
  - Modify: `tests/php/Admin/Posting_PageTest.php`
  - Modify: `tests/e2e/posting-ui.spec.ts`
- **Do**:
  1. Add a result panel when `posted=<post_id>` is present and the current user can edit/read that post.
  2. Fetch statuses through the data-consistency contract defined in `spec.md`:
     - Prefer `Automattic\Fosse\Send_Status::for_post( $post_id )` when the class exists.
     - Apply the `fosse_send_status_for_post` filter as the test/extension seam.
  3. Render ActivityPub and Bluesky rows from the `activitypub` and `atproto` status keys. If no backend data is available, render both as `unknown` and no retry buttons.
  4. For each status, show provider name, state label, message/error text, update time, remote link when present, next retry time when present, and retry button when `can_retry` is true and state is `failed`.
  5. Add `Posting_Page::handle_retry_publication()`:
     - verify capability and nonce;
     - sanitize `post_id` and `network`;
     - allow only `activitypub` and `atproto`;
     - call `do_action( 'fosse_retry_publication', $post_id, $network )`;
     - redirect back to the posted result screen.
  6. Add a test-only mu-plugin for E2E that supplies fake provider statuses through the filter and records retry action calls.
  7. Add PHPUnit coverage for unknown, sent, failed, and retry-visible states.
  8. Add Playwright coverage for sent/failed rows and retry button submission.
  9. Revisit this task when the data-consistency implementation lands and record any shipped API deviation in implementation notes.
- **Verify**:
  - `composer run-script test-php -- --filter 'Posting_PageTest'`
  - `composer run-script lint-php`
  - `pnpm exec playwright test tests/e2e/posting-ui.spec.ts`
- **Depends on**: Task 4, `sdd/data-consistency-sync/` for completion of DOTCOM-16805

### Task 6: Add quick-entry / Press This-style path

- **Status**: Not started
- **Files**:
  - Modify: `src/Admin/class-posting-page.php`
  - Modify: `src/Admin/templates/posting-page.php`
  - Modify: `src/Admin/assets/js/composer.js`
  - Modify: `src/Admin/assets/css/admin.css`
  - Modify: `tests/php/Admin/Posting_PageTest.php`
  - Modify: `tests/e2e/posting-ui.spec.ts`
- **Do**:
  1. Support `admin.php?page=fosse-post&quick=1` as compact mode using the same template and submit handler.
  2. Read GET parameters only for prefill:
     - `text`
     - `url`
     - `title`
  3. Sanitize prefill values and place them in the editable textarea. If `url` is present, append it to the text on its own line.
  4. Render a bookmarklet link from the normal FOSSE Post page that opens the quick composer with `document.title`, `location.href`, and selected text.
  5. Keep all publish validation in `Posting_Service`; quick-entry must not have a second validation path.
  6. Add PHPUnit coverage that prefill values are escaped and that quick mode still renders the same nonce/action.
  7. Add Playwright coverage:
     - quick URL pre-fills selected text and URL;
     - compact mode works at mobile viewport width;
     - submitting quick-entry creates a `post_format = status` post.
- **Verify**:
  - `composer run-script test-php -- --filter 'Posting_PageTest|Posting_ServiceTest'`
  - `composer run-script lint-php`
  - `pnpm run format:check`
  - `pnpm run lint`
  - `pnpm exec playwright test tests/e2e/posting-ui.spec.ts`
- **Depends on**: Task 5 for final ordering, though prefill can be developed earlier if the core composer service is stable

### Task 7: Complete verification and SDD updates

- **Status**: Not started
- **Files**:
  - Modify: `sdd/posting-ui/requirements.md`
  - Modify: `sdd/posting-ui/spec.md`
  - Modify: `sdd/posting-ui/plan.md`
  - Create if needed: `sdd/posting-ui/implementation-notes.md`
- **Do**:
  1. Run the full local verification suite:
     - `composer run-script lint-php`
     - `composer run-script test-php`
     - `pnpm run format:check`
     - `pnpm run lint`
     - `pnpm test`
     - `pnpm exec playwright test tests/e2e/posting-ui.spec.ts`
  2. Manually smoke test in Playground:
     - text-only post;
     - photo post with alt text;
     - photo post without alt text rejection;
     - post-publish status panel;
     - retry button with seeded failed status;
     - quick-entry prefill on desktop and mobile widths.
  3. Update this plan's Progress checklist and each task Status with the final PR/commit reference.
  4. Record implementation deviations in `implementation-notes.md` only if the implementation differs materially from `spec.md`.
  5. If `sdd/data-consistency-sync/` chose a different status/retry API, update `spec.md` and this plan to match the shipped contract.
- **Verify**:
  - All commands above pass or documented failures have linked follow-up issues.
  - `sdd/posting-ui/requirements.md`, `spec.md`, and `plan.md` describe the shipped behavior accurately.
- **Depends on**: Tasks 1-6
