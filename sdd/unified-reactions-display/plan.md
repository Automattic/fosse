# Implementation Plan: Unified Reactions Display

Based on: sdd/unified-reactions-display/spec.md

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

## Progress

- [x] Task 1: Add `Reactions_Label` skeleton + matching-block PHPUnit case
- [x] Task 2: Add non-matching-block + `register()` idempotency PHPUnit cases
- [x] Task 3: Wire `Reactions_Label::register()` into `fosse.php`
- [x] Task 4: Add `fosse-reactions-seed` e2e mu-plugin
- [x] Task 5: Add `reactions-display` Playwright e2e spec
- [x] Task 6: Run verification, capture `implementation.md`, mark plan complete

## Tasks

### Task 1: Add `Reactions_Label` skeleton + matching-block PHPUnit case

- **Status**: ✅ Done (#40)
- **Files**:
  - `src/class-reactions-label.php` (new)
  - `tests/php/Reactions_LabelTest.php` (new)
- **Do**:
  1. Write a failing PHPUnit test `test_filter_rewrites_activitypub_reactions_block_args` in `tests/php/Reactions_LabelTest.php`. Extend `\WorDBless\BaseTestCase`. Use `#[Before]` to call `Reactions_Label::register()`. Build a minimal `$args` array with `title => 'Fediverse Reactions'`, `description => 'Display Fediverse likes and reposts for your posts.'`, plus a couple of unrelated keys; call `apply_filters( 'register_block_type_args', $args, 'activitypub/reactions' )`; assert `title === 'Social Reactions'`, assert `description` matches the new wording, assert the unrelated keys round-trip unchanged.
  2. Run `composer dump-autoload && composer run-script test-php -- --filter Reactions_Label`. Verify RED — class does not exist.
  3. Implement `src/class-reactions-label.php`: namespace `Automattic\Fosse`, class `Reactions_Label`, `private const BLOCK_NAME = 'activitypub/reactions'`, `private const TITLE = 'Social Reactions'`, `private const DESCRIPTION = 'Display social likes and reposts for your posts.'`, `public static function register(): void` that calls `\add_filter( 'register_block_type_args', array( self::class, 'rewrite_block_args' ), 10, 2 )`, `public static function rewrite_block_args( array $args, string $name ): array` that returns `$args` unchanged when `self::BLOCK_NAME !== $name`, else overlays the new `title` and `description` keys.
  4. Add full PHPDoc on the class and both public methods following the `Long_Form_Strategy` precedent (purpose, params, returns).
  5. Run `composer dump-autoload && composer run-script test-php -- --filter Reactions_Label`. Verify GREEN.
  6. Run `composer run-script lint-php -- src/class-reactions-label.php tests/php/Reactions_LabelTest.php`. Clean.
  7. Commit: `add: Reactions_Label projector for activitypub/reactions block relabel`
- **Verify**: 1 PHPUnit case green; PHPCS clean on both new files.
- **Depends on**: none

### Task 2: Add non-matching-block + `register()` idempotency PHPUnit cases

- **Status**: ✅ Done (#40)
- **Files**:
  - `tests/php/Reactions_LabelTest.php` (modify)
- **Do**:
  1. Write `test_filter_passes_through_unrelated_block_names`: build `$args` with `title => 'Original'`, apply the filter for block name `core/paragraph`, assert the array is returned identically — title untouched.
  2. Write `test_filter_passes_through_when_only_some_keys_present`: pass an `$args` array missing `description` (only `title => 'Fediverse Reactions'`); assert `title` is rewritten, `description` is not added (don't invent keys upstream didn't supply).
  3. Write `test_register_is_idempotent`: matches the `Post_Types` precedent (`tests/php/Post_TypesTest.php`). Call `Reactions_Label::register()` three times, then inspect the global `$wp_filter` for `register_block_type_args` at priority 10 and assert exactly one entry whose callback is `[ Reactions_Label::class, 'rewrite_block_args' ]`.
  4. Run `composer run-script test-php -- --filter Reactions_Label`. Verify GREEN — 4 cases, 4 assertions.
  5. Run `composer run-script lint-php -- tests/php/Reactions_LabelTest.php`. Clean.
  6. Commit: `test: cover Reactions_Label pass-through and idempotency cases`
- **Verify**: 4 PHPUnit cases green; PHPCS clean.
- **Depends on**: Task 1

### Task 3: Wire `Reactions_Label::register()` into `fosse.php`

- **Status**: ✅ Done (#40)
- **Files**:
  - `fosse.php` (modify)
- **Do**:
  1. Add a new `add_action( 'init', static function () { ... } )` block in `fosse.php` immediately after the `Long_Form_Strategy` registration. Match the existing pattern: `class_exists` guard on `\Automattic\Fosse\Reactions_Label::class`, then `\Automattic\Fosse\Reactions_Label::register();`. Include a `/* */` block comment in the same shape as the sibling projectors describing what the relabel does and why.
  2. Run `composer run-script lint-php -- fosse.php`. Clean.
  3. Run the full PHPUnit suite: `composer run-script test-php`. Confirm no regressions; `Reactions_Label` cases still green.
  4. Commit: `add: register Reactions_Label on init in fosse.php`
- **Verify**: PHPCS clean on `fosse.php`; full PHPUnit green.
- **Depends on**: Task 2

### Task 4: Add `fosse-reactions-seed` e2e mu-plugin

- **Status**: ✅ Done (#40)
- **Files**:
  - `tests/e2e/mu-plugins/fosse-reactions-seed.php` (new)
- **Do**:
  1. Read `tests/e2e/mu-plugins/fosse-bsky-capture.php` to confirm conventions (file header, namespace-vs-functions, idempotency strategy, how to gate seeding on a marker option).
  2. Create the new mu-plugin. On a `wp_loaded` hook (or whatever the existing capture plugin uses), check an idempotency marker in options (e.g. `fosse_reactions_seed_done`); return early if already seeded. Otherwise:
     - `wp_insert_post` a published post with title "Reactions test post", content containing the `<!-- wp:activitypub/reactions /-->` block markup (or the modern inner-blocks form — pick whatever a real AP-block insertion looks like; reference any post you can render from the inserter for accuracy).
     - For the inserted post ID, call `wp_insert_comment` 3× to create:
       - 1× `comment_type='like'`, `comment_approved=1`, `comment_parent=0`, with `update_comment_meta` setting `protocol='activitypub'`, `source_id='https://example.test/like-1'`, `_activitypub_remote_actor_id=0` (or a sensible stub).
       - 1× `comment_type='like'`, `comment_approved=1`, `comment_parent=0`, with `protocol='atproto'`, `source_id='at://did:plc:test/app.bsky.feed.like/abc'`, `_atmosphere_author_avatar='https://example.test/avatar-bsky.png'`.
       - 1× `comment_type='repost'`, `comment_approved=1`, `comment_parent=0`, with `protocol='atproto'`, plus matching meta.
     - Set the marker option so subsequent loads no-op.
  3. Confirm the file passes PHPCS: `composer run-script lint-php -- tests/e2e/mu-plugins/fosse-reactions-seed.php`. Note: `tests/e2e/mu-plugins/` may have its own PHPCS scope; if it's excluded, skip but document.
  4. Commit: `tests: add fosse-reactions-seed mu-plugin for unified-reactions e2e`
- **Verify**: Mu-plugin file present; PHPCS clean (or noted as excluded).
- **Depends on**: Task 3

### Task 5: Add `reactions-display` Playwright e2e spec

- **Status**: ✅ Done (#40)
- **Files**:
  - `tests/e2e/reactions-display.spec.ts` (new)
  - `tests/e2e/blueprint.json` (possibly modify — only if the new mu-plugin is not already auto-mounted by the existing blueprint logic)
- **Do**:
  1. Read `playwright.config.ts` and `tests/e2e/blueprint.json` to understand how mu-plugins are mounted into Playground today. If the existing blueprint mounts everything under `tests/e2e/mu-plugins/` automatically, no change needed; otherwise, add a step that mounts `fosse-reactions-seed.php`.
  2. Look at one existing `tests/e2e/*.spec.ts` to confirm conventions (test setup, base URL, how to find the seeded post, login if needed).
  3. Write the spec with two assertions:
     - **Frontend assertion**: navigate to the seeded post's URL; locate the rendered `activitypub/reactions` block; assert the facepile / count UI shows at least one element traceable to a `protocol='activitypub'` comment AND at least one to `protocol='atproto'`. The simplest tracer is the avatar `src` attribute or a data attribute exposed by the rendered comment markup; if neither is present, fall back to counting list items or asserting on rendered count text. Pick the locator the rendered HTML actually exposes.
     - **Relabel assertion**: hit `GET /wp-json/wp/v2/block-types/activitypub/reactions` against the Playground server (no auth needed for read); assert the JSON `title === 'Social Reactions'` and `description` matches the configured wording.
  4. Run `pnpm run test:e2e -- reactions-display`. If the spec fails, the failure tells us which assertion broke; consult the spec's "Contingency" section. Iterate until GREEN.
  5. Commit: `tests: add reactions-display Playwright e2e spec`
- **Verify**: `pnpm run test:e2e` passes for the new spec; existing specs unaffected.
- **Depends on**: Task 4

### Task 6: Run verification, capture `implementation.md`, mark plan complete

- **Status**: ✅ Done (#40)
- **Files**:
  - `sdd/unified-reactions-display/implementation.md` (new)
  - `sdd/unified-reactions-display/plan.md` (modify — Progress checkboxes + per-task Status fields)
- **Do**:
  1. Run the full suite locally: `composer run-script lint-php`, `composer run-script test-php`, `pnpm run lint`, `pnpm run format:check`, `pnpm run test:e2e`. Capture exit codes and the e2e output.
  2. Write `sdd/unified-reactions-display/implementation.md` covering:
     - **Verification result.** Did AP's reactions block display Bluesky rows on Playground? Quote the relevant assertion that proved (or disproved) it.
     - **Deviations from spec.** Anything the implementation did differently from `spec.md` (filter signature, e2e locator strategy, blueprint-mount approach), with a one-line reason for each.
     - **Open follow-ups.** Items uncovered during implementation that are out of v1 but worth a Linear issue (e.g., legacy v1.0.0 fallback if it surfaced, any upstream PR opportunities, replies-display scope).
  3. Update `plan.md`: flip every `- [ ]` to `- [x]` in the Progress block and set every task's `**Status**: Not started` to `**Status**: ✅ Done (<commit-sha>)` referencing the commit that completed it.
  4. Commit: `docs: capture implementation notes + mark unified-reactions-display SDD complete`
- **Verify**: All Progress boxes ticked; implementation.md exists and references real verification output; full local lint/test/e2e suite green.
- **Depends on**: Task 5
