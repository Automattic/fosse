# Implementation Plan: Bluesky Native Publishing

Based on: sdd/bluesky-native-publishing/spec.md

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

## Cross-Repo Note

Two of the seven tasks land in upstream repos (`Automattic/wordpress-activitypub` and `Automattic/wordpress-atmosphere`), **not** FOSSE. Each is opened as its own PR, reviewed, and merged independently. The remaining FOSSE work (bundle refreshes, projector class, e2e, docs) gates on those merges.

Code references throughout the plan link to `trunk` on GitHub. They map to local checkouts at `~/code/<repo-name>` (e.g. `Automattic/wordpress-atmosphere` → `~/code/wordpress-atmosphere`).

Linear:
- Epic: [DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795)
- Upstream Atmosphere: [DOTCOM-16838](https://linear.app/a8c/issue/DOTCOM-16838)
- Upstream AP: [DOTCOM-16839](https://linear.app/a8c/issue/DOTCOM-16839)
- FOSSE projector: [DOTCOM-16840](https://linear.app/a8c/issue/DOTCOM-16840)
- Standard.site records confirmation: [DOTCOM-16809](https://linear.app/a8c/issue/DOTCOM-16809)
- Facet parity: [DOTCOM-16811](https://linear.app/a8c/issue/DOTCOM-16811)
- Decision record: [DOTCOM-16812](https://linear.app/a8c/issue/DOTCOM-16812)

## Progress

- [x] Task 1 [UPSTREAM-AP]: Add `activitypub_post_object_type` filter to AP `Post::get_type()`
- [x] Task 2 [UPSTREAM-AT]: Make Atmosphere `Post` transformer post-format-aware + add filter
- [x] Task 3: Refresh `bundled/atmosphere/` after Task 2 merges
- [x] Task 4: Refresh `bundled/activitypub/` after Task 1 merges
- [ ] Task 5 [FOSSE]: `Object_Type` projector + `fosse_object_type` option
- [ ] Task 6: Playwright e2e — facet parity round-trip on the short-form path
- [x] Task 7: Document upstream-first decision policy

## Tasks

### Task 1 [UPSTREAM-AP]: Add `activitypub_post_object_type` filter to AP `Post::get_type()`
- **Status**: ✅ Done ([wordpress-activitypub#3210](https://github.com/Automattic/wordpress-activitypub/pull/3210), merged 2026-04-21)
- **Repo**: `Automattic/wordpress-activitypub`
- **Linear**: [DOTCOM-16839](https://linear.app/a8c/issue/DOTCOM-16839)
- **Files**:
  - [`includes/transformer/class-post.php`](https://github.com/Automattic/wordpress-activitypub/blob/trunk/includes/transformer/class-post.php)
  - existing transformer test file (location varies)
  - [`readme.txt`](https://github.com/Automattic/wordpress-activitypub/blob/trunk/readme.txt)
- **Do**:
  1. Cut a working branch off `trunk`, e.g. `add/post-object-type-filter`.
  2. Refactor `Post::get_type()` so all branches assign to `$object_type` and the method returns once at the bottom (currently has an early return at the `'wordpress-post-format' !== $post_format_setting` check). Trivially equivalent in behavior to today's shape.
  3. Add the filter immediately before the final return:
     ```php
     /**
      * Filters the ActivityPub object type for a post.
      *
      * @param string  $object_type Computed type ('Note', 'Article', 'Page').
      * @param WP_Post $item        The post.
      */
     return apply_filters( 'activitypub_post_object_type', $object_type, $this->item );
     ```
  4. Add a test confirming the filter overrides `get_type()` end-to-end. Critically: assert that the override propagates to **all five internal call sites** (the activity object's `type` setter, the two content-handling branches at lines 538 / 567, the content-template selection at line 1062, and the preview-generation guard at line 1140). A test that flips the filter to `Note` should produce a wire-format object whose content/attachment fields are also computed under the Note branch — not just a Note-typed object with Article-shaped content.
  5. Update `readme.txt` Changelog (unreleased) with one line documenting the new filter.
  6. Run upstream tests + lint; fix anything.
  7. Push branch, open PR against `Automattic/wordpress-activitypub` `trunk`. PR description: explain the gap (existing `activitypub_transform_set_type` only changes the wire value, not the multiple internal callers); cite the FOSSE SDD as the design source; link to the sibling Atmosphere PR (Task 2) for context.
- **Verify**:
  - All new and existing tests pass; CI green.
  - PR merged to `trunk`.
- **Depends on**: none
- **Handoff**: Note merge SHA in Task 4's commit body.

### Task 2 [UPSTREAM-AT]: Make Atmosphere `Post` transformer post-format-aware + add filter
- **Status**: ✅ Done ([wordpress-atmosphere#29](https://github.com/Automattic/wordpress-atmosphere/pull/29), merged 2026-04-21)
- **Repo**: `Automattic/wordpress-atmosphere`
- **Linear**: [DOTCOM-16838](https://linear.app/a8c/issue/DOTCOM-16838)
- **Files**:
  - [`includes/transformer/class-post.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php)
  - [`includes/transformer/class-base.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-base.php)
  - [`includes/transformer/class-document.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-document.php) (refactor only)
  - `tests/phpunit/tests/transformer/class-test-post.php` (new — file does not exist on trunk; sibling [`class-test-facet.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/tests/phpunit/tests/transformer/class-test-facet.php) shows the convention)
  - [`readme.txt`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/readme.txt)
- **Do**:
  1. Cut a working branch off `trunk`, e.g. `add/short-form-post-format`.
  2. Extract the plain-text rendering helper from `Document::get_text_content()` into a protected method on `Transformer\Base` — e.g. `render_post_content_plain( \WP_Post $post ): string`. Body: `apply_filters( 'the_content', $post->post_content )` → `wp_strip_all_tags` → `html_entity_decode( …, ENT_QUOTES, 'UTF-8' )` → `trim( preg_replace( '/\s+/', ' ', $content ) )`. Replace inlined logic in `Document::get_text_content()` with a call to the new helper. Confirm no behavior change.
  3. In `Post`, add private `is_short_form( \WP_Post $post ): bool`:
     - `true` if `! post_type_supports( $post->post_type, 'title' )` OR `empty( $post->post_title )`.
     - `true` if `get_post_format( $post )` is non-empty.
     - `false` otherwise.
  4. In `Post`, add private `build_short_form_text(): string` that calls `render_post_content_plain()` and clamps with `truncate_text( $text, 300 )`.
  5. In `Post::transform()`, branch on the filtered discriminator:
     ```php
     /**
      * Filters whether the post should be treated as short-form.
      *
      * @param bool     $is_short Whether the post is short-form.
      * @param \WP_Post $post     The post.
      */
     $is_short = apply_filters(
         'atmosphere_is_short_form_post',
         $this->is_short_form( $this->object ),
         $this->object
     );

     if ( $is_short ) {
         $text  = $this->build_short_form_text();
         $embed = null;
     } else {
         $text  = $this->build_text();
         $embed = $this->build_embed();
     }
     // … existing facets / record assembly continues with $text and $embed …
     ```
  6. Write `tests/phpunit/tests/transformer/class-test-post.php` from scratch:
     - `test_long_form_titled_no_format_unchanged()` — byte-identical to current behavior.
     - `test_short_form_when_untitled()`.
     - `test_short_form_when_post_format_set()` (covering `status` and at least one other format like `aside`).
     - `test_short_form_truncates_over_cap()`.
     - `test_filter_can_force_short_form()` — register a filter returning `true`; assert short-form output even on a titled-no-format post.
     - `test_filter_can_force_long_form()` — register a filter returning `false`; assert long-form output even on an untitled post.
  7. Update `readme.txt` Changelog (unreleased) with: "Short-form posts (untitled or with a post format) now publish as native Bluesky posts instead of link cards, matching the ActivityPub plugin's `Note` discriminator. New `atmosphere_is_short_form_post` filter for downstream override."
  8. Run upstream tests + lint; fix anything.
  9. Push branch, open PR against `Automattic/wordpress-atmosphere` `trunk`. PR description: explain mirroring AP's discriminator for cross-network symmetry; cite the FOSSE SDD; note the `Document::get_text_content()` refactor is bundled but separable on request; link to sibling AP PR (Task 1).
- **Verify**:
  - All new tests pass; existing atmosphere tests still pass.
  - PR green; merged to `trunk`.
- **Depends on**: none — runs in parallel with Task 1.
- **Handoff**: Note merge SHA in Task 3's commit body.

### Task 3: Refresh `bundled/atmosphere/` after Task 2 merges
- **Status**: ✅ Done ([#19](https://github.com/Automattic/fosse/pull/19), merged as `c2900c0`; upstream SHA `864b994`)
- **Repo**: `Automattic/fosse`
- **Files**: [`bundled/atmosphere/**`](https://github.com/Automattic/fosse/tree/trunk/bundled/atmosphere)
- **Do**:
  1. Ensure `~/code/wordpress-atmosphere` is on `trunk` at or past the Task-2 merge SHA.
  2. Run `./tools/sync-bundled.sh` (Atmosphere half — script syncs both bundles by default; that's fine, AP-side may also pick up the Task-1 change if Task 4 hasn't run yet).
  3. Sanity check: `grep -n is_short_form bundled/atmosphere/includes/transformer/class-post.php` returns the new method; `grep -n atmosphere_is_short_form_post bundled/atmosphere/includes/transformer/class-post.php` returns the new filter.
  4. Commit: `Bundled atmosphere: pull in post-format-aware short-form path (upstream <SHA>)`.
- **Verify**:
  - `pnpm run test:e2e` and `composer run-script test-php` still pass.
  - PHPCS exclusion on `bundled/` still holds.
- **Depends on**: Task 2 merged upstream.

### Task 4: Refresh `bundled/activitypub/` after Task 1 merges
- **Status**: ✅ Done ([#19](https://github.com/Automattic/fosse/pull/19), merged as `c2900c0`; upstream SHA `b889d6a3`, past tag 8.1.1)
- **Repo**: `Automattic/fosse`
- **Files**: [`bundled/activitypub/**`](https://github.com/Automattic/fosse/tree/trunk/bundled/activitypub)
- **Do**:
  1. Ensure `~/code/wordpress-activitypub` is on `trunk` at or past the Task-1 merge SHA.
  2. Run `./tools/sync-bundled.sh` (or skip if Task 3 already pulled it via the same script invocation).
  3. Sanity check: `grep -n activitypub_post_object_type bundled/activitypub/includes/transformer/class-post.php` returns the new filter.
  4. Commit if not already part of Task 3's commit: `Bundled activitypub: pull in activitypub_post_object_type filter (upstream <SHA>)`.
- **Verify**:
  - Same checks as Task 3.
- **Depends on**: Task 1 merged upstream.

### Task 5 [FOSSE]: `Object_Type` projector + `fosse_object_type` option
- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Linear**: [DOTCOM-16840](https://linear.app/a8c/issue/DOTCOM-16840)
- **Files**:
  - `src/class-object-type.php` (new — WP `class-*.php` filename convention per Jetpack PHPCS; class name stays `Automattic\Fosse\Object_Type`)
  - `tests/php/Object_TypeTest.php` (new)
  - [`fosse.php`](https://github.com/Automattic/fosse/blob/trunk/fosse.php) (modify — register hook on `init`)
- **Do**:
  1. Write failing PHPUnit test `Automattic\Fosse\Tests\Object_TypeTest`:
     - `@before`: call `\Automattic\Fosse\Object_Type::register()`.
     - `test_atmosphere_filter_passes_through_by_default()`: assert `apply_filters( 'atmosphere_is_short_form_post', false, $post )` returns `false` (default option absent).
     - `test_atmosphere_filter_forces_short_form_when_option_note()`: `update_option( 'fosse_object_type', 'note' )`; assert filter returns `true` regardless of input default.
     - `test_atmosphere_filter_passes_through_when_option_wordpress_post_format()`: `update_option( 'fosse_object_type', 'wordpress-post-format' )`; assert filter returns input unchanged.
     - `test_ap_filter_passes_through_by_default()`: `apply_filters( 'activitypub_post_object_type', 'Article', $post )` returns `'Article'`.
     - `test_ap_filter_forces_note_when_option_note()`: option set to `note`; assert filter returns `'Note'` regardless of input.
     - `test_ap_filter_passes_through_when_option_wordpress_post_format()`: option set to `wordpress-post-format`; assert filter returns input unchanged.
  2. Run `composer run-script test-php` → verify failure.
  3. Create `src/class-object-type.php`:
     - Namespace `Automattic\Fosse`.
     - Class `Object_Type` with three public static methods:
       - `register(): void` — `add_filter( 'atmosphere_is_short_form_post', [ self::class, 'filter_atmosphere' ], 10, 2 ); add_filter( 'activitypub_post_object_type', [ self::class, 'filter_ap' ], 10, 2 );`
       - `filter_atmosphere( bool $is_short, \WP_Post $post ): bool` — return `true` if `'note' === \get_option( 'fosse_object_type' )`, otherwise return `$is_short`.
       - `filter_ap( string $type, \WP_Post $post ): string` — return `'Note'` if `'note' === \get_option( 'fosse_object_type' )`, otherwise return `$type`.
     - Follow Jetpack PHPCS: tabs, Yoda, file header, PHPDoc on public methods.
  4. Wire into `fosse.php`: add `add_action( 'init', [ '\Automattic\Fosse\Object_Type', 'register' ] )` after the bundled-bootstrap shim. Default priority 10 — fires before Atmosphere's `transition_post_status` schedules anything.
  5. Run `composer run-script test-php` → verify passes.
  6. Run `composer run-script lint-php` → verify clean.
  7. Commit: `Add cross-network Object_Type projector for fosse_object_type`
- **Verify**:
  - All six new tests pass.
  - No regressions.
  - Manual on Playground: `wp option update fosse_object_type note`; publish a normal titled post; check `?atproto` preview shows the body-as-text shape (no embed); check the AP outbox shows a Note-typed activity.
- **Depends on**: Tasks 3 and 4. (The hooks are no-ops without the bundle refresh — the upstream filters they target won't exist.)

### Task 6: Playwright e2e — facet parity round-trip on the short-form path (DOTCOM-16811)
- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Linear**: [DOTCOM-16811](https://linear.app/a8c/issue/DOTCOM-16811)
- **Files**:
  - `tests/e2e/short-form-facets.spec.ts` (new)
  - `tests/e2e/helpers/atproto.ts` (new, if needed)
  - [`tests/e2e/blueprint.json`](https://github.com/Automattic/fosse/blob/trunk/tests/e2e/blueprint.json) (modify only if a mu-plugin or fixture must be added)
- **Do**:
  1. Decide PDS strategy:
     - **(a) Sandbox PDS** — real network, more realistic, more setup, possible flakiness.
     - **(b) Request interception** (recommended) — mu-plugin filtering [`Atmosphere\API`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/class-api.php) to capture `applyWrites` payload to disk; spec reads and asserts. Fully deterministic.
  2. Implement (b) by default. The mu-plugin: mock `is_connected()` to `true`, set fake `_atproto_connection` option, intercept `Atmosphere\API::apply_writes` to write payload to a known path under `wp_upload_dir()`.
  3. Spec: navigate to `/wp-admin/post-new.php`, fill body `"hello #world @alice.test https://example.com"`, set post format to `Status` via the editor sidebar (or via a post-meta REST helper), publish, fetch the captured JSON, assert.
  4. Assertions on the captured `app.bsky.feed.post` write:
     - `text` exactly equals `"hello #world @alice.test https://example.com"`.
     - No `embed` key.
     - `facets` contains exactly three entries:
       - `app.bsky.richtext.facet#tag` with `tag = "world"` covering `#world`.
       - `app.bsky.richtext.facet#mention` covering `@alice.test`. DID will be `did:web:alice.test` (DNS resolution falls back per [`Facet::resolve_mention`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-facet.php) behavior in test env).
       - `app.bsky.richtext.facet#link` with `uri` matching the URL.
  5. Run `pnpm run test:e2e -g short-form-facets`.
  6. Commit: `E2E: verify short-form post facet round-trip (tag, mention, link)`
- **Verify**:
  - Test passes locally and in CI.
  - Closes [DOTCOM-16811](https://linear.app/a8c/issue/DOTCOM-16811).
  - If any facet feature fails, file a separate upstream issue + PR per defect. No FOSSE-side patches.
- **Depends on**: Task 5.

### Task 7: Document upstream-first decision policy
- **Status**: ✅ Done ([#23](https://github.com/Automattic/fosse/pull/23))
- **Repo**: `Automattic/fosse`
- **Linear**: [DOTCOM-16812](https://linear.app/a8c/issue/DOTCOM-16812)
- **Files**: [`AGENTS.md`](https://github.com/Automattic/fosse/blob/trunk/AGENTS.md)
- **Do**:
  1. Add a new section to `AGENTS.md` titled "Upstream contribution policy" (placed near the bottom, after "Common Pitfalls"):
     - Rule: **post-type-agnostic correctness goes upstream; FOSSE-shape-specific behavior stays in FOSSE.**
     - Worked example: this epic. Atmosphere's short-form discriminator (and AP's `get_type()` filter) are universally useful → upstream. The cross-network projector (`Object_Type`) is specific to FOSSE's "publish once, reach everywhere" model → FOSSE.
     - Cite the SDD: `sdd/bluesky-native-publishing/`.
  2. Externally (not part of the file list, requires explicit approval): post a comment on [DOTCOM-16812](https://linear.app/a8c/issue/DOTCOM-16812) with the same matrix and a link to the SDD. **Ask Kraft before posting.**
  3. Commit: `Docs: capture upstream-first contribution policy`
- **Verify**:
  - `AGENTS.md` renders cleanly.
  - Linear comment posted (after explicit approval).
- **Depends on**: none — parallelizable any time.
