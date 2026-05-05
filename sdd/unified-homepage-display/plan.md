# Implementation Plan: Unified Homepage / Display

Based on: `sdd/unified-homepage-display/spec.md`

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## Progress

- [ ] Task 1: Add `Homepage_Stream` query/classification helpers
- [ ] Task 2: Add rendering helpers and PHP output coverage
- [ ] Task 3: Register the `fosse/unified-homepage-stream` block and editor assets
- [ ] Task 4: Add block CSS and visual consistency coverage
- [ ] Task 5: Close the single-post reactions label fallback gap
- [ ] Task 6: Add deterministic homepage/replies e2e seeding
- [ ] Task 7: Add Playwright coverage for homepage stream and single-post reactions/replies
- [ ] Task 8: Run verification and update SDD status

## Tasks

### Task 1: Add `Homepage_Stream` query/classification helpers

- **Status**: Not started
- **Linear**: [DOTCOM-16818](https://linear.app/a8c/issue/DOTCOM-16818), [DOTCOM-16820](https://linear.app/a8c/issue/DOTCOM-16820)
- **Files**:
  - Create: `src/class-homepage-stream.php`
  - Create: `tests/php/Homepage_StreamTest.php`
- **Do**:
  - Create `Automattic\Fosse\Homepage_Stream` with constants:
    - `BLOCK_NAME = 'fosse/unified-homepage-stream'`
    - `DEFAULT_POSTS_PER_PAGE = 10`
    - `MAX_POSTS_PER_PAGE = 50`
    - `DEFAULT_MEDIA_LIMIT = 4`
    - `MAX_MEDIA_LIMIT = 6`
    - `PHOTO_CAPTION_MAX_CHARS = 280`
  - Add `public static function build_query_args( array $attributes ): array`.
    - Clamp `postsPerPage` to `1..50`.
    - Return a `WP_Query` args array with `post_type => 'post'`, `post_status => 'publish'`, `orderby => 'date'`, `order => 'DESC'`, `ignore_sticky_posts => true`, and `no_found_rows => true`.
  - Add `public static function get_post_images( int $post_id, int $limit ): array`.
    - Include the featured image first when present.
    - Include image attachments returned by `get_attached_media( 'image', $post_id )`.
    - Deduplicate by attachment ID.
    - Clamp `limit` to `0..6`.
  - Add `public static function classify_post( \WP_Post $post ): string`.
    - Return `'photo'` when `get_post_format( $post )` is `'image'`.
    - Return `'photo'` when `get_post_images( $post->ID, 1 )` returns an image and the stripped, rendered post content is at most 280 characters.
    - Return `'note'` when `get_post_format( $post )` is `'status'` or `get_the_title( $post )` is empty after trimming.
    - Return `'article'` otherwise.
  - Add focused PHPUnit tests:
    - `test_build_query_args_queries_published_standard_posts_only`
    - `test_build_query_args_clamps_posts_per_page`
    - `test_status_format_classifies_as_note`
    - `test_untitled_post_classifies_as_note`
    - `test_image_format_classifies_as_photo`
    - `test_short_caption_with_attached_image_classifies_as_photo`
    - `test_long_article_with_attached_image_stays_article`
    - `test_titled_default_post_classifies_as_article`
    - `test_get_post_images_orders_featured_image_first_and_dedupes`
  - Use WorDBless factories or `wp_insert_post()` plus `set_post_format()` in the test setup. For image tests, create attachment posts with `post_parent` set to the seeded post and set one attachment as `_thumbnail_id`. Reset inserted posts, attachments, thumbnail meta, and post formats after each test.
- **Verify**:
  - `composer dump-autoload`
  - `composer run-script test-php -- --filter Homepage_Stream`
  - `composer run-script lint-php -- src/class-homepage-stream.php tests/php/Homepage_StreamTest.php`
- **Depends on**: none

### Task 2: Add rendering helpers and PHP output coverage

- **Status**: Not started
- **Linear**: [DOTCOM-16818](https://linear.app/a8c/issue/DOTCOM-16818), [DOTCOM-16820](https://linear.app/a8c/issue/DOTCOM-16820)
- **Files**:
  - Modify: `src/class-homepage-stream.php`
  - Modify: `tests/php/Homepage_StreamTest.php`
- **Do**:
  - Add `public static function render( array $attributes = array(), string $content = '', ?\WP_Block $block = null ): string`.
    - Run `new \WP_Query( self::build_query_args( $attributes ) )`.
    - Render an empty-state paragraph when there are no posts: "No posts found."
    - Wrap output in `get_block_wrapper_attributes( array( 'class' => 'fosse-stream' ) )`.
    - Render each item with `render_item( \WP_Post $post, array $attributes ): string`.
    - Call `wp_reset_postdata()` after the query loop.
  - Add private helpers inside the class:
    - `render_item()`
    - `render_media()`
    - `render_article_content()`
    - `render_note_content()`
    - `render_photo_content()`
  - Output expectations:
    - Every item is `<article class="fosse-stream__item is-article|is-note|is-photo">`.
    - Article items include `.fosse-stream__title` and `.fosse-stream__excerpt` when `showExcerpt` is true.
    - Note items include `.fosse-stream__content` and do not invent `.fosse-stream__title`.
    - Photo items include `.fosse-stream__media` before text.
    - All titles/permalinks/dates are escaped with WordPress helpers.
  - Add PHPUnit tests:
    - `test_render_outputs_article_note_and_photo_classes`
    - `test_render_note_does_not_invent_title`
    - `test_render_outputs_empty_state_when_no_posts_exist`
    - `test_render_respects_show_media_false`
    - `test_render_respects_show_excerpt_false`
- **Verify**:
  - `composer run-script test-php -- --filter Homepage_Stream`
  - `composer run-script lint-php -- src/class-homepage-stream.php tests/php/Homepage_StreamTest.php`
- **Depends on**: Task 1

### Task 3: Register the `fosse/unified-homepage-stream` block and editor assets

- **Status**: Not started
- **Linear**: [DOTCOM-16818](https://linear.app/a8c/issue/DOTCOM-16818)
- **Files**:
  - Modify: `src/class-homepage-stream.php`
  - Create: `src/Blocks/unified-homepage-stream/block.json`
  - Create: `src/Blocks/unified-homepage-stream/editor.js`
  - Modify: `fosse.php`
  - Modify: `tests/php/Homepage_StreamTest.php`
- **Do**:
  - Add `public static function register(): void` to `Homepage_Stream`.
    - Register an editor script handle, `fosse-unified-homepage-stream-editor`, using `plugins_url( 'Blocks/unified-homepage-stream/editor.js', __FILE__ )` from `src/class-homepage-stream.php`.
    - Script dependencies: `wp-blocks`, `wp-block-editor`, `wp-components`, `wp-element`, `wp-i18n`, `wp-server-side-render`.
    - Call `register_block_type_from_metadata( __DIR__ . '/Blocks/unified-homepage-stream', array( 'render_callback' => array( self::class, 'render' ), 'editor_script' => 'fosse-unified-homepage-stream-editor' ) )`.
  - Create `block.json` with:
    - `apiVersion: 3`
    - `name: "fosse/unified-homepage-stream"`
    - `title: "Social Web Stream"`
    - `category: "widgets"`
    - `icon: "admin-post"`
    - `description: "Display long-form posts, notes, and photos in one chronological stream."`
    - attributes from `spec.md`
    - `supports.html: false`
    - `supports.align: [ "wide", "full" ]`
    - `style: "file:./style.css"`
  - Create `editor.js` as unbuilt JS using WordPress globals.
    - Register the block type with the same attributes.
    - Render inspector controls for `postsPerPage`, `showExcerpt`, `showMedia`, `mediaLimit`, and `showDates`.
    - Render `wp.serverSideRender` for preview.
    - Use `wp.i18n.__()` with text domain `fosse`.
  - Wire registration into `fosse.php` using the existing `init` + `class_exists` guard pattern.
  - Add PHPUnit coverage:
    - `test_register_registers_block_type`
    - `test_register_is_idempotent`
- **Verify**:
  - `composer dump-autoload`
  - `composer run-script test-php -- --filter Homepage_Stream`
  - `composer run-script lint-php -- src/class-homepage-stream.php tests/php/Homepage_StreamTest.php fosse.php`
  - `pnpm run lint -- src/Blocks/unified-homepage-stream/editor.js`
  - `pnpm run format:check -- src/Blocks/unified-homepage-stream/block.json src/Blocks/unified-homepage-stream/editor.js`
- **Depends on**: Task 2

### Task 4: Add block CSS and visual consistency coverage

- **Status**: Not started
- **Linear**: [DOTCOM-16820](https://linear.app/a8c/issue/DOTCOM-16820)
- **Files**:
  - Create: `src/Blocks/unified-homepage-stream/style.css`
  - Modify: `tests/php/Homepage_StreamTest.php`
- **Do**:
  - Add scoped CSS for:
    - `.wp-block-fosse-unified-homepage-stream`
    - `.fosse-stream`
    - `.fosse-stream__item`
    - `.fosse-stream__item.is-article`
    - `.fosse-stream__item.is-note`
    - `.fosse-stream__item.is-photo`
    - `.fosse-stream__media`
    - `.fosse-stream__title`
    - `.fosse-stream__content`
    - `.fosse-stream__meta`
  - Use theme-aware values:
    - `max-width: var(--wp--style--global--content-size, 720px)`
    - `color: inherit`
    - `border-color: color-mix(...)` only with a fallback, or use `currentColor` opacity where safer.
  - Use stable media dimensions:
    - media grid images use `aspect-ratio`, `object-fit: cover`, and `max-width: 100%`.
    - mobile layout avoids fixed widths.
  - Add a PHPUnit assertion that `block.json` references `file:./style.css` so a future refactor does not drop frontend styles.
- **Verify**:
  - `pnpm run format:check -- src/Blocks/unified-homepage-stream/style.css`
  - `pnpm run lint -- src/Blocks/unified-homepage-stream/editor.js`
  - `composer run-script test-php -- --filter Homepage_Stream`
- **Depends on**: Task 3

### Task 5: Close the single-post reactions label fallback gap

- **Status**: Not started
- **Linear**: [DOTCOM-16819](https://linear.app/a8c/issue/DOTCOM-16819)
- **Files**:
  - Modify: `src/class-reactions-label.php`
  - Modify: `tests/php/Reactions_LabelTest.php`
- **Do**:
  - Add a block-render-scoped fallback rewrite to `Reactions_Label::register()`:
    - Hook `render_block_activitypub/reactions`.
    - Callback signature accepts the rendered block content and parsed block array.
    - Replace only the legacy visible fallback heading text `Fediverse Reactions` with `Social Reactions`.
    - Do not use a broad `gettext` filter.
  - Add `public static function rewrite_rendered_block( string $block_content, array $block ): string`.
  - Add PHPUnit tests:
    - `test_render_rewrite_relabels_legacy_fallback_heading`
    - `test_render_rewrite_does_not_touch_unrelated_text_when_no_legacy_heading`
    - `test_render_rewrite_uses_fosse_translation`
    - extend the idempotency test so both `register_block_type_args` and `render_block_activitypub/reactions` have exactly one callback.
  - Keep the existing metadata relabel tests intact.
- **Verify**:
  - `composer run-script test-php -- --filter Reactions_Label`
  - `composer run-script lint-php -- src/class-reactions-label.php tests/php/Reactions_LabelTest.php`
- **Depends on**: completed `sdd/unified-reactions-display/`

### Task 6: Add deterministic homepage/replies e2e seeding

- **Status**: Not started
- **Linear**: [DOTCOM-16818](https://linear.app/a8c/issue/DOTCOM-16818), [DOTCOM-16819](https://linear.app/a8c/issue/DOTCOM-16819)
- **Files**:
  - Create: `tests/e2e/mu-plugins/fosse-homepage-stream-seed.php`
  - Modify: `tests/e2e/blueprint.json`
- **Do**:
  - Create a test-only mu-plugin gated by `FOSSE_E2E`.
  - Register REST route `POST /wp-json/fosse-e2e/v1/homepage-stream-seed` with `manage_options` permission.
  - Seed exactly four published posts:
    - Article: title `FOSSE E2E Article`, no post format, content long enough to produce an excerpt.
    - Note: title empty, `post_format=status`, body `FOSSE E2E status note`.
    - Photo-forward: title `FOSSE E2E Photo`, `post_format=image`, one attached image.
    - Note with photo: title empty, `post_format=status`, body `FOSSE E2E photo note`, one attached image.
  - Create image attachments using generated small PNG files written through WordPress upload APIs, with alt text:
    - `FOSSE e2e photo`
    - `FOSSE e2e note photo`
  - Create or update a page titled `FOSSE E2E Stream Page` with content `<!-- wp:fosse/unified-homepage-stream {"postsPerPage":4} /-->`.
  - On the article post, seed:
    - one approved AP like comment (`comment_type='like'`, `protocol='activitypub'`)
    - one approved AT like comment (`comment_type='like'`, `protocol='atproto'`)
    - one approved AT repost comment (`comment_type='repost'`, `protocol='atproto'`)
    - one approved AP reply (`comment_type='comment'`, `protocol='activitypub'`, author `Reply via Mastodon`)
    - one approved AT reply (`comment_type='comment'`, `protocol='atproto'`, author `Reply via Bluesky`)
  - Make seeding idempotent:
    - Store seeded object IDs in option `fosse_e2e_homepage_stream_seed`.
    - On re-run, delete prior seeded comments/attachments/posts from that option before recreating.
  - Return JSON containing stream page URL, post IDs, post URLs, and comment IDs.
  - Add a `cp` step to `tests/e2e/blueprint.json` copying the new mu-plugin into `wp-content/mu-plugins/`.
- **Verify**:
  - `composer run-script lint-php -- tests/e2e/mu-plugins/fosse-homepage-stream-seed.php` if the file is in PHPCS scope; otherwise note that e2e mu-plugins are outside `.phpcs.xml.dist` and rely on review plus Playwright.
  - `pnpm run format:check -- tests/e2e/blueprint.json`
- **Depends on**: Task 3

### Task 7: Add Playwright coverage for homepage stream and single-post reactions/replies

- **Status**: Not started
- **Linear**: [DOTCOM-16818](https://linear.app/a8c/issue/DOTCOM-16818), [DOTCOM-16819](https://linear.app/a8c/issue/DOTCOM-16819), [DOTCOM-16820](https://linear.app/a8c/issue/DOTCOM-16820)
- **Files**:
  - Create: `tests/e2e/homepage-stream.spec.ts`
- **Do**:
  - In the spec, open `/wp-admin/post-new.php` and wait for `window.wpApiSettings.nonce`, matching existing e2e conventions.
  - POST to `/wp-json/fosse-e2e/v1/homepage-stream-seed`.
  - Visit the returned stream page URL.
  - Assert:
    - `.wp-block-fosse-unified-homepage-stream.fosse-stream` is visible.
    - exactly four `.fosse-stream__item` elements render.
    - items are in reverse chronological order using their seeded titles/body markers.
    - one item has `.is-article`, two have `.is-note`, one has `.is-photo`.
    - image alt text for both seeded images is present.
    - visible homepage text does not include `ActivityPub`, `Fediverse`, or `Bluesky`.
  - Set viewport to `390x844`; assert `document.documentElement.scrollWidth <= document.documentElement.clientWidth`.
  - Visit the seeded article single-post URL.
  - Assert:
    - `Social Reactions` is visible.
    - `Fediverse Reactions` is not visible.
    - like count aggregates to `2`.
    - repost count is `1`.
    - `Reply via Mastodon` and `Reply via Bluesky` are visible in comments.
  - Add a second test that posts to the seed endpoint twice and asserts the stream item count and reaction/reply counts do not duplicate.
- **Verify**:
  - `pnpm run test:e2e -- homepage-stream`
  - `pnpm run lint -- tests/e2e/homepage-stream.spec.ts`
  - `pnpm run format:check -- tests/e2e/homepage-stream.spec.ts`
- **Depends on**: Tasks 4, 5, 6

### Task 8: Run verification and update SDD status

- **Status**: Not started
- **Linear**: [DOTCOM-16797](https://linear.app/a8c/issue/DOTCOM-16797)
- **Files**:
  - Modify: `sdd/unified-homepage-display/plan.md`
- **Do**:
  - Run local verification:
    - `composer run-script lint-php`
    - `composer run-script test-php`
    - `pnpm run lint`
    - `pnpm run format:check`
    - `pnpm run test:e2e -- homepage-stream reactions-display`
  - Update this plan:
    - Check each completed Progress item.
    - Replace each completed task's status with the AGENTS.md Done status value and a commit or PR reference.
  - Do not mark a task done until its Verify commands pass or the implementation notes document the exact skipped reason.
- **Verify**:
  - `rg -n "Status\\*\\*: Not started|\\[ \\]" sdd/unified-homepage-display/plan.md` only shows intentionally unfinished work.
  - All SDD status fields and Progress checklist entries agree.
- **Depends on**: Task 7
