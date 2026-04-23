# Implementation Plan: Long-Form Bluesky Strategy (Option 5 — teaser mini-thread)

Based on: [sdd/long-form-bluesky-strategy/spec.md](./spec.md)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

## Cross-Repo Note

The upstream work lands in `Automattic/wordpress-atmosphere` as **one PR** (composition + Publisher redesign ship together — the Publisher branch needs both composition methods to exist). FOSSE then consumes via `tools/sync-bundled.sh` and adds its own thin projector.

Code references link to `trunk` on GitHub. They map to local checkouts at `~/code/<repo-name>` (e.g. `Automattic/wordpress-atmosphere` → `~/code/wordpress-atmosphere`).

Linear:

- Parent epic: [DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795)
- This work: [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810) — long-form text strategy for Bluesky (Todo after the 2026-04-23 decision)
- Follow-on v2 renderer: [DOTCOM-16827](https://linear.app/a8c/issue/DOTCOM-16827)
- Adjacent niche-ecosystem work: [DOTCOM-16859](https://linear.app/a8c/issue/DOTCOM-16859)

Decision context:

- RFC: [How should FOSSE publish long-form posts to Bluesky?](https://fossep2.wordpress.com/2026/04/22/rfc-how-should-fosse-publish-long-form-posts-to-bluesky/) — originally proposed Option 2 (truncate + link) as v1.
- Call notes: [Bluesky Intro (Jim Ray)](https://fossep2.wordpress.com/2026/04/23/call-notes-bluesky-intro-jim-ray/) — `standard.site` native rendering is S-tier on Bluesky's roadmap but months out and multi-iteration. Not a v1 gate.
- Decision comment: [https://fossep2.wordpress.com/2026/04/22/rfc-how-should-fosse-publish-long-form-posts-to-bluesky/#comment-27](https://fossep2.wordpress.com/2026/04/22/rfc-how-should-fosse-publish-long-form-posts-to-bluesky/#comment-27) — v1 is Option 5 (short thread + link), Option 3 stays the long-term target.

## Progress

- [ ] Task 1 [UPSTREAM-AT]: Composition helpers + `atmosphere_long_form_composition` filter + Publisher thread redesign (one PR)
- [ ] Task 2 [FOSSE]: Refresh `bundled/atmosphere/` after Task 1 merges
- [ ] Task 3 [FOSSE]: `Long_Form_Strategy` projector + `fosse_long_form_strategy` option
- [ ] Task 4 [FOSSE]: Extend e2e capture helper + teaser-thread Playwright spec
- [ ] Task 5 [FOSSE]: Changelog + AGENTS.md note

## Tasks

### Task 1 [UPSTREAM-AT]: Composition helpers + `atmosphere_long_form_composition` filter + Publisher thread redesign

- **Status**: Not started
- **Repo**: `Automattic/wordpress-atmosphere`
- **Linear**: [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810)
- **Files**:
  - [`includes/transformer/class-post.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php) — add `build_truncate_link_text()` and `build_teaser_thread()`; leave existing `build_text()` + `build_embed()` path intact for the `'link-card'` strategy.
  - [`includes/class-publisher.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/class-publisher.php) — switch on strategy; sequential-writes-with-rollback for `'teaser-thread'`; ordered-array meta storage; single-value backwards-compat.
  - `includes/class-post-meta.php` or wherever meta keys live — add `META_THREAD_URIS` and `META_THREAD_TIDS` constants (new file if there isn't one today; otherwise extend the existing location). Search the repo for how `META_URI` / `META_TID` are defined on `Post` and follow the same pattern.
  - `tests/phpunit/tests/transformer/class-test-post.php` — extend with new composition tests.
  - `tests/phpunit/tests/class-test-publisher.php` — new file (sibling tests likely don't exist for `Publisher` today; use `class-test-facet.php` layout as convention reference).
  - [`readme.txt`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/readme.txt) — changelog.
- **Do**:
  1. Cut a working branch off `trunk`, e.g. `add/long-form-teaser-thread`.
  2. **Commit 1 — composition methods + `atmosphere_long_form_composition` filter, with `'link-card'` default unchanged:**
     1. In `includes/transformer/class-post.php`, add private method `build_truncate_link_text(): string`:
        - Calls the shared plain-text helper `Transformer\Base::render_post_content_plain( $this->object )` (from DOTCOM-16838).
        - Computes a budget = 300 − `len( "\n\n" )` − `len( $permalink )`; clamp with `truncate_text( $text, $budget )`.
        - Returns `$text . "\n\n" . $permalink`.
     2. In the same file, add private method `build_teaser_thread(): array` returning an ordered array of post-text strings:
        - Compute the hook: `render_post_content_plain( $this->object )` truncated to 280 graphemes at the last word boundary that fits (prefer a helper `Transformer\Base::truncate_at_word_boundary( $text, $max )` if one exists; otherwise a regex over `\s` works — add the helper inline if needed, but keep the boundary behavior tested).
        - Compute the CTA: `sprintf( __( 'Continue reading: %s', 'atmosphere' ), $permalink )`.
        - Return `array( $hook, $cta )`.
        - Apply `apply_filters( 'atmosphere_teaser_thread_posts', array( $hook, $cta ), $this->object )` on the return value.
     3. In `Post::transform()`, extract the existing long-form `build_text()` + `build_embed()` path into a private method `build_link_card_post(): array` that returns `[ 'text' => …, 'embed' => … ]`. Don't change behavior — this is a pure refactor.
     4. Add a public entry point for Publisher to ask "what records should I write?" — `transform_long_form_records(): array` returning an ordered array of `[ 'text' => string, 'embed' => ?array, 'facets' => array ]` entries. For `'link-card'` and `'truncate-link'` this is a 1-element array; for `'teaser-thread'` it's a 2-element array.
        - Method body: `$strategy = apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->object );` then `switch ($strategy)`:
          - `'teaser-thread'`: build the thread texts via `build_teaser_thread()`, run `Facet::extract()` over each, return the array.
          - `'truncate-link'`: single entry with `build_truncate_link_text()` + facets + `embed = null`.
          - `'link-card'` (and any unknown value): single entry built from `build_link_card_post()`.
     5. In `readme.txt` Changelog (unreleased), document the new filter + composition methods. Default behavior unchanged — flag "no visible change unless a consumer hooks `atmosphere_long_form_composition`."
     6. Run `composer test` / upstream test suite.
     7. Commit: `Add atmosphere_long_form_composition filter and teaser-thread + truncate-link composition methods`.
  3. **Commit 2 — tests for composition methods:**
     1. In `tests/phpunit/tests/transformer/class-test-post.php`, add:
        - `test_long_form_default_is_link_card()` — no filter override; `transform_long_form_records()` returns a 1-entry array with the same text + embed shape as today's `transform()` (byte-identical regression guard).
        - `test_long_form_truncate_link_filter()` — register filter returning `'truncate-link'`; assert 1-entry array with text = body (truncated) + `\n\n` + permalink, `embed === null`, at least one link facet covering the permalink.
        - `test_long_form_teaser_thread_filter_default_two_posts()` — register filter returning `'teaser-thread'`; assert 2-entry array; first entry text is body hook (≤ 280 graphemes) with no permalink; second entry text matches `/^Continue reading: /` + permalink; both entries have facets applied.
        - `test_long_form_teaser_thread_posts_filter_can_extend_to_three()` — register `'teaser-thread'` composition + an `atmosphere_teaser_thread_posts` filter that returns a 3-entry array; assert the returned records match the filter's output.
        - `test_long_form_unknown_strategy_falls_back_to_link_card()` — register filter returning `'nonsense'`; assert behavior matches `'link-card'` default.
     2. Run `composer test` → verify all new tests pass, no regressions on existing.
     3. Commit: `Tests: cover atmosphere_long_form_composition strategy branches`.
  4. **Commit 3 — Publisher rework for thread strategy + ordered-array meta:**
     1. In `includes/class-publisher.php`, refactor `publish( WP_Post $post )`:
        - Instantiate `$bsky_transformer = new Post( $post );` and `$doc_transformer = new Document( $post );` as today.
        - Call `$records = $bsky_transformer->transform_long_form_records();` (from Task 1 Commit 1). Short-form path stays untouched — this only fires when the transformer's own short/long branch picks long.
          - Note: the short/long branch is inside `Post::transform()` today. Clarify at refactor time whether short-form goes through `transform_long_form_records()` as a 1-entry "short-form" record or keeps a separate path. Keep separate for now (short-form is out of this epic's scope); pattern is "if short-form, single record, single write, today's behavior."
        - For single-record long-form (`count($records) === 1`): today's code path — one `applyWrites` with root bsky post + doc, atomic. No further changes.
        - For multi-record long-form (`count($records) >= 2`): execute the sequential-writes-with-rollback flow:
          1. First `applyWrites` (atomic): root bsky post (index 0) + doc. Capture root URI + CID from response.
          2. For each reply `$records[$i]` where `$i >= 1`: fill `$record['reply'] = [ 'root' => [ 'uri' => $root_uri, 'cid' => $root_cid ], 'parent' => [ 'uri' => $prev_uri, 'cid' => $prev_cid ] ];` then call `applyWrites` with a single create. Capture URI + CID. On failure: collect the URIs already created (root + prior replies) and call `applyWrites` with N deletes (reverse order). Return the original `WP_Error` from the failing create — do not return the rollback result even on rollback success. If rollback itself fails, wrap the error with context (`new \WP_Error( 'atmosphere_thread_rollback_failed', …, [ 'partial_uris' => […] ] )`) so the caller can see partial state.
          3. On success for all replies: persist `$ordered_uris` and `$ordered_tids` arrays in post meta (`META_THREAD_URIS`, `META_THREAD_TIDS`). Also mirror root into `META_URI` / `META_TID` for backwards compat.
     2. Refactor `update( WP_Post $post )`:
        - Read `META_THREAD_TIDS`. If absent (post was published before this change) fall back to the single-value `META_TID` as a 1-element array.
        - If the stored thread length equals the new thread length from `transform_long_form_records()` AND the strategy is `'link-card'` or `'truncate-link'` (single-post strategies), issue an in-place `applyWrites#update` as today.
        - Otherwise (thread length changed, or multi-post strategy): delete all stored records (N deletes of bsky posts + 1 delete of doc via `applyWrites`, atomic), then call `publish()` for a fresh write. Document this in the changelog — `update` for threads is "rewrite," not in-place.
     3. Refactor `delete( WP_Post $post )`:
        - Read `META_THREAD_TIDS`; fall back to `META_TID` as 1-element. Issue `applyWrites` with N bsky deletes + 1 doc delete. On failure, return `WP_Error`. On success, clear all four meta keys (`META_URI`, `META_TID`, `META_THREAD_URIS`, `META_THREAD_TIDS`).
     4. Define `META_THREAD_URIS = '_atmosphere_bsky_thread_uris'` and `META_THREAD_TIDS = '_atmosphere_bsky_thread_tids'` constants on the appropriate class (probably `Post` — match where `META_URI` / `META_TID` live today).
     5. Commit: `Publisher: support multi-record thread composition with sequential-writes rollback`.
  5. **Commit 4 — tests for Publisher thread behavior:**
     1. In `tests/phpunit/tests/class-test-publisher.php` (new file; follow the layout of existing sibling tests):
        - `test_publish_link_card_strategy_writes_single_atomic_applywrites()` — default strategy; exactly one `applyWrites` call with 2 writes (bsky post + doc); meta shapes populated in single-element arrays.
        - `test_publish_teaser_thread_strategy_writes_sequentially_and_stores_ordered_meta()` — `atmosphere_long_form_composition` → `'teaser-thread'`; capture `applyWrites` calls; assert first call has root bsky post + doc, subsequent calls have single bsky posts with `reply.root` and `reply.parent` populated; assert `META_THREAD_URIS` has 2 entries in order, root mirrored into `META_URI`.
        - `test_publish_teaser_thread_rollback_on_second_write_failure()` — mock second `applyWrites` to return `WP_Error`; assert: a compensating delete was issued for the root; `META_URI` / `META_THREAD_URIS` are NOT persisted (or are cleared); `publish()` returns the original `WP_Error`.
        - `test_publish_teaser_thread_rollback_itself_failing_surfaces_partial_state()` — mock second create + compensating delete both failing; assert return is a `WP_Error` with a `partial_uris` data payload.
        - `test_delete_thread_removes_all_records()` — seed `META_THREAD_TIDS` with 2 entries; call `delete()`; assert `applyWrites` call includes 3 deletes (2 bsky + 1 doc) and all meta keys cleared.
        - `test_delete_single_post_backwards_compat_with_legacy_meta()` — seed only `META_TID` (legacy shape); assert `delete()` issues 2 deletes (1 bsky + 1 doc).
        - `test_update_thread_length_changed_triggers_rewrite()` — seed `META_THREAD_TIDS` with 1 entry (legacy post or truncate-link); filter flips to `'teaser-thread'`; call `update()`; assert: old records deleted, then `publish()` ran, ending in 2-entry thread meta.
        - `test_update_same_length_single_post_uses_in_place_applywrites_update()` — `'link-card'` strategy unchanged; `update()` issues a single `applyWrites#update` call.
     2. The `API::apply_writes` mock is the test seam. Use `add_filter( 'pre_http_request', … )` or extract `API::apply_writes` into an injectable service if easier — pick the seam consistent with existing upstream test patterns (check `class-test-facet.php` for the convention).
     3. Run `composer test` → all Publisher tests pass; no regression on other suites.
     4. Commit: `Tests: Publisher multi-record thread writes, rollback, and legacy backwards-compat`.
  6. **Commit 5 — readme.txt changelog entry for the feature:**
     1. Bullet: "New `atmosphere_long_form_composition` filter lets downstream plugins select between `link-card` (default, today's behavior), `truncate-link` (single post with body-as-text + permalink), and `teaser-thread` (2-post thread: hook + CTA-with-link)."
     2. Bullet: "New `atmosphere_teaser_thread_posts` filter lets downstream override the 2-post default composition (e.g. expand to 3 posts, customize CTA copy)."
     3. Bullet: "Post meta: new `_atmosphere_bsky_thread_uris` and `_atmosphere_bsky_thread_tids` ordered arrays for thread tracking. Existing `_atmosphere_bsky_uri` / `_atmosphere_bsky_tid` mirror the root post for backwards compatibility."
     4. Note under the filter bullets: "Default behavior is unchanged — `'link-card'` remains the filter default. Existing sites see no change unless they opt into a different strategy."
     5. Run `composer lint`.
     6. Commit: `Changelog: document atmosphere_long_form_composition and thread meta`.
  7. Push the branch, open a PR against `Automattic/wordpress-atmosphere` `trunk`. PR description: summarize the strategy enum, the thread write semantics (sequential-writes-with-rollback) and rationale (client-side CID computation deferred), and back-reference the FOSSE SDD at `Automattic/fosse:sdd/long-form-bluesky-strategy/`. Note the upstream-first policy (AGENTS.md) explicitly.
- **Verify**:
  - All new tests pass locally and in upstream CI.
  - Existing tests still pass (zero-regression on `'link-card'` behavior).
  - Upstream reviewer approval (Matthias or delegate).
  - PR merged to `trunk`; note merge SHA for Task 2's commit body.
- **Depends on**: none (parent epic's DOTCOM-16838 work is already merged on trunk and bundled).

### Task 2 [FOSSE]: Refresh `bundled/atmosphere/` after Task 1 merges

- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Files**: [`bundled/atmosphere/**`](https://github.com/Automattic/fosse/tree/trunk/bundled/atmosphere)
- **Do**:
  1. Ensure `~/code/wordpress-atmosphere` is on `trunk` at or past the Task-1 merge SHA: `git -C ~/code/wordpress-atmosphere fetch origin && git -C ~/code/wordpress-atmosphere checkout trunk && git -C ~/code/wordpress-atmosphere pull --ff-only`.
  2. From the FOSSE checkout: `./tools/sync-bundled.sh`.
  3. Sanity-check the sync landed the new code:
     - `grep -n atmosphere_long_form_composition bundled/atmosphere/includes/transformer/class-post.php` — returns a hit on the new filter.
     - `grep -n build_teaser_thread bundled/atmosphere/includes/transformer/class-post.php` — returns the new method.
     - `grep -n META_THREAD_TIDS bundled/atmosphere/includes/` — returns the new constant.
  4. Run `composer run-script test-php` and `pnpm run test:e2e` to confirm the refresh doesn't regress anything on the short-form path or existing long-form link-card e2e (which stays `'link-card'` under default `atmosphere_long_form_composition`).
  5. Commit: `Bundled atmosphere: pull in atmosphere_long_form_composition + thread Publisher (upstream <SHA>)`.
- **Verify**:
  - All local tests pass.
  - PHPCS exclusion on `bundled/` still holds (no new lint errors).
- **Depends on**: Task 1 merged upstream.

### Task 3 [FOSSE]: `Long_Form_Strategy` projector + `fosse_long_form_strategy` option

- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Linear**: [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810)
- **Files**:
  - `src/class-long-form-strategy.php` (new — WP `class-*.php` filename convention per Jetpack PHPCS; class name stays `Automattic\Fosse\Long_Form_Strategy`).
  - `tests/php/Long_Form_StrategyTest.php` (new).
  - [`fosse.php`](https://github.com/Automattic/fosse/blob/trunk/fosse.php) — register on `init`.
- **Do**:
  1. Write failing PHPUnit test `Automattic\Fosse\Tests\Long_Form_StrategyTest` (mirror `tests/php/Object_TypeTest.php` structure):
     - `@before`: call `\Automattic\Fosse\Long_Form_Strategy::register()`.
     - `test_passes_through_default_when_option_unset()`: assert `apply_filters( 'atmosphere_long_form_composition', 'link-card', $post )` returns `'link-card'`.
     - `test_projects_teaser_thread_when_option_teaser_thread()`: `update_option( 'fosse_long_form_strategy', 'teaser-thread' )`; assert filter returns `'teaser-thread'`.
     - `test_projects_truncate_link_when_option_truncate_link()`: `update_option( 'fosse_long_form_strategy', 'truncate-link' )`; assert filter returns `'truncate-link'`.
     - `test_projects_link_card_when_option_link_card()`: `update_option( 'fosse_long_form_strategy', 'link-card' )`; assert filter returns `'link-card'`.
     - `test_projects_document_card_when_option_document_card()`: `update_option( 'fosse_long_form_strategy', 'document-card' )`; assert filter returns `'document-card'`. (Covers the v2 target value, even though upstream doesn't implement it in Task 1 — the projector is enum-agnostic by design.)
     - `test_passes_through_upstream_default_when_option_unknown()`: `update_option( 'fosse_long_form_strategy', 'nonsense' )`; assert filter returns the input default (pass-through). Projector does not coerce unknown values.
     - `test_passes_through_upstream_default_when_option_explicitly_empty()`: `update_option( 'fosse_long_form_strategy', '' )`; assert pass-through.
  2. Run `composer run-script test-php` → verify failures.
  3. Create `src/class-long-form-strategy.php`:
     - Namespace `Automattic\Fosse`.
     - Class `Long_Form_Strategy` with two public static methods:
       - `register(): void` — `\add_filter( 'atmosphere_long_form_composition', array( self::class, 'filter' ), 10, 2 );`
       - `filter( string $strategy, $post ): string` — read `\get_option( self::OPTION )`; if it matches a known enum value (`'teaser-thread'` | `'truncate-link'` | `'link-card'` | `'document-card'`), return it; else return `$strategy` (pass-through).
     - Private constants: `OPTION = 'fosse_long_form_strategy'`; `KNOWN_STRATEGIES` array with the four enum values.
     - Follow Jetpack PHPCS (tabs, Yoda, file header, PHPDoc on public methods). Model on `src/class-object-type.php` for style.
  4. Wire into `fosse.php`: add `\add_action( 'init', array( '\Automattic\Fosse\Long_Form_Strategy', 'register' ) );` adjacent to the existing `Object_Type::register` call.
  5. Run `composer run-script test-php` → all tests pass.
  6. Run `composer run-script lint-php` → clean.
  7. Commit: `Add Long_Form_Strategy projector for fosse_long_form_strategy option`.
- **Verify**:
  - All seven new unit tests pass.
  - No regressions on existing suites (`Object_TypeTest` etc. still pass).
  - Manual on Playground (optional): `wp option update fosse_long_form_strategy teaser-thread`; publish a titled 1000-word post; check the e2e capture file contains 2 bsky records with reply refs (or wait for Task 4's e2e to automate this).
- **Depends on**: Task 2.

### Task 4 [FOSSE]: Extend e2e capture helper + teaser-thread Playwright spec

- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Files**:
  - [`tests/e2e/mu-plugins/fosse-bsky-capture.php`](https://github.com/Automattic/fosse/blob/trunk/tests/e2e/mu-plugins/fosse-bsky-capture.php) — modify.
  - `tests/e2e/long-form-teaser-thread.spec.ts` (new).
  - `tests/e2e/helpers/atproto.ts` — only if a shared helper emerges; otherwise keep inline.
- **Do**:
  1. Read the existing capture helper at `tests/e2e/mu-plugins/fosse-bsky-capture.php` and `tests/e2e/long-form-link-card.spec.ts` to understand the current shape (single-`applyWrites` capture → one JSON file). This task extends the helper to record *every* `applyWrites` call within a request, not just the first.
  2. Modify the mu-plugin:
     - Change the capture storage from a single JSON object to an ordered array of calls under the same file path (`/wp-content/uploads/fosse-bsky-capture.json`).
     - Each call entry: `{ timestamp, writes: [...] }` where `writes` is the payload Atmosphere passed to `API::apply_writes`.
     - The file is truncated at the start of each publish (so tests always start fresh).
     - Keep the `post_id` + `doc_record` surface for backwards compat with `long-form-link-card.spec.ts` and `short-form-facets.spec.ts`: for single-call writes, the file's top-level shape stays identical (so those specs keep passing unchanged). Only multi-call writes switch to the array shape. Detect via `count($captured_calls)`: if 1, emit the legacy object; if >1, emit `{ calls: [...], post_id, doc_record }`.
     - Alternate: always emit the new shape, and update the two existing specs to read `calls[0]`. Cleaner long-term; confirm preference with the existing spec authors or just take the cleanup (two small spec diffs).
     - Run the existing e2e specs locally to confirm they still pass with the refactored helper.
  3. Create `tests/e2e/long-form-teaser-thread.spec.ts`:
     - Model on `long-form-link-card.spec.ts` structure (REST-based post create, poll for capture, assertions).
     - Before creating the post: `POST` to a FOSSE e2e REST endpoint (the blueprint already seeds one for `fosse_object_type` — add a sibling `/wp-json/fosse-e2e/v1/long-form-strategy` endpoint that sets `fosse_long_form_strategy`). If adding a new endpoint is too heavy, use `wp_cli` via the blueprint to pre-seed the option for this spec's run.
     - Set `fosse_long_form_strategy = 'teaser-thread'`.
     - Create a long titled post (title ≥ 5 words; body ≥ 500 words of lorem with one hashtag, one mention, one URL woven in).
     - Poll the capture file; expect 2 `applyWrites` calls.
     - Assertions on call 0 (root + doc):
       - 2 writes in the payload: one `app.bsky.feed.post` + one `site.standard.document`.
       - The bsky record has `text` matching the first ~280 graphemes of the body (not the title), no `embed`, no `reply`, and facets for hashtag / mention / URL that appear in the hook.
       - The doc record is present (DOTCOM-16809 guard).
     - Assertions on call 1 (CTA reply):
       - 1 write: `app.bsky.feed.post`.
       - `text` matches `/^Continue reading: https?:\/\//`.
       - `reply.root.uri` matches the root's URI; `reply.parent.uri` matches the root's URI (for a 2-post thread, parent === root).
       - `facets` has a link facet over the permalink.
     - Assert post meta via REST / wp-cli read: `_atmosphere_bsky_thread_uris` has 2 entries, `_atmosphere_bsky_thread_tids` has 2 entries, `_atmosphere_bsky_uri` matches the root, `_atmosphere_bsky_tid` matches the root's TID.
  4. Run `pnpm run test:e2e -g long-form-teaser-thread`.
  5. Verify the two existing specs still pass after the capture-helper refactor.
  6. Commit: `E2E: verify teaser-thread long-form composition (two-post thread with reply refs)`.
- **Verify**:
  - New spec passes locally and in CI.
  - `tests/e2e/long-form-link-card.spec.ts` and `tests/e2e/short-form-facets.spec.ts` still pass (no regression from capture-helper refactor).
- **Depends on**: Task 3.

### Task 5 [FOSSE]: Changelog + AGENTS.md note

- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Files**:
  - `CHANGELOG.md` (if the repo has one; otherwise `readme.txt` following the upstream convention — verify at execution time).
  - [`AGENTS.md`](https://github.com/Automattic/fosse/blob/trunk/AGENTS.md).
- **Do**:
  1. Add a changelog entry for `'teaser-thread'` becoming the default long-form strategy and for the `fosse_long_form_strategy` option. Mention the opt-out values (`'link-card'`, `'truncate-link'`) and the v2 target (`'document-card'`).
  2. In `AGENTS.md`, under the "Upstream contribution policy" section that was added in PR #23, append a worked example:
     - **Upstream** — Atmosphere's `atmosphere_long_form_composition` filter, the `build_teaser_thread()` + `build_truncate_link_text()` composition methods, and the `Publisher::publish/update/delete` multi-record sequential-writes-with-rollback redesign. All describe a universal "how does Atmosphere compose long posts" concern valuable to any consumer.
     - **FOSSE** — the `Automattic\Fosse\Long_Form_Strategy` projector that reads `fosse_long_form_strategy` and drives the single upstream filter. Thin projector only.
     - Cross-reference PR #18's short-form worked example for symmetry.
  3. Commit: `Docs: capture long-form strategy rollout + upstream-first decision record`.
- **Verify**:
  - Markdown renders cleanly.
  - No broken links.
- **Depends on**: none — parallelizable once Task 1's upstream PR is open (even pre-merge, the upstream work's shape is locked enough to document).
