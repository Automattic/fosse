# Implementation Plan: Long-Form Bluesky Strategy (Option 5 — teaser mini-thread)

Based on: [sdd/long-form-bluesky-strategy/spec.md](./spec.md)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

## Cross-Repo Note

The upstream work lands in `Automattic/wordpress-atmosphere` as **one PR** — composition methods, Publisher redesign, and the new meta shape all ship together because Publisher depends on the composition methods and the meta constant. **The PR opens as a draft as the first step of Task 1** so asynchronous upstream review can run in parallel with the FOSSE-side tasks (2–5). FOSSE then consumes via `tools/sync-bundled.sh` and adds its own thin projector.

Code references link to `trunk` on GitHub. They map to local checkouts at `~/code/<repo-name>` (e.g. `Automattic/wordpress-atmosphere` → `~/code/wordpress-atmosphere`).

Linear:

- Parent epic: [DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795)
- This work (umbrella — no per-task children for v1): [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810) — long-form text strategy for Bluesky (Todo after the 2026-04-23 decision)
- Follow-on v2 renderer: [DOTCOM-16827](https://linear.app/a8c/issue/DOTCOM-16827)
- Adjacent niche-ecosystem work: [DOTCOM-16859](https://linear.app/a8c/issue/DOTCOM-16859)

Decision context:

- RFC: [How should FOSSE publish long-form posts to Bluesky?](https://fossep2.wordpress.com/2026/04/22/rfc-how-should-fosse-publish-long-form-posts-to-bluesky/)
- Call notes: [Bluesky Intro (Jim Ray)](https://fossep2.wordpress.com/2026/04/23/call-notes-bluesky-intro-jim-ray/) — `standard.site` native rendering is months out and multi-iteration. Not a v1 gate.
- Decision comment: [RFC comment #27](https://fossep2.wordpress.com/2026/04/22/rfc-how-should-fosse-publish-long-form-posts-to-bluesky/#comment-27) — v1 is Option 5; Option 3 stays the long-term target.

## Progress

- [ ] Task 1 [UPSTREAM-AT]: Composition helpers + `atmosphere_long_form_composition` filter + Publisher thread redesign (one upstream PR, opens draft first)
- [ ] Task 2 [FOSSE]: Refresh `bundled/atmosphere/` after Task 1 merges
- [ ] Task 3 [FOSSE]: `Long_Form_Strategy` projector + `fosse_long_form_strategy` option
- [ ] Task 4 [FOSSE]: Rewrite e2e capture helper as `pre_http_request` interceptor + teaser-thread Playwright spec
- [ ] Task 5 [FOSSE]: Changelog + AGENTS.md note

## Tasks

### Task 1 [UPSTREAM-AT]: Composition helpers + `atmosphere_long_form_composition` filter + Publisher thread redesign

- **Status**: Not started
- **Repo**: `Automattic/wordpress-atmosphere`
- **Linear**: [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810)
- **Files**:
  - [`includes/transformer/class-post.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php) — add `build_long_form_records()`, `build_teaser_thread()`, `build_truncate_link_text()`, private `truncate_to_budget()` helper (sentence → word → hard-cap), and `META_THREAD_RECORDS` constant. `transform()`'s short-form branch is untouched; the long-form branch is kept for legacy callers that call `transform()` directly.
  - [`includes/class-publisher.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/class-publisher.php) — branch on short/long; extend `store_results()` to append to the thread-records meta after each successful write; add sequential-writes-with-rollback for multi-record long-form; rewrite `update()` and `delete()` to handle thread shape with legacy fallback.
  - `tests/phpunit/tests/transformer/class-test-post.php` — extend with composition tests.
  - `tests/phpunit/tests/class-test-publisher.php` — new file. Pattern reference: sibling `tests/phpunit/tests/transformer/class-test-facet.php`.
  - [`readme.txt`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/readme.txt) — changelog with caveats for `update()` semantics.
- **Do**:
  1. **Open a draft PR first.** Cut a branch off `trunk` (e.g. `add/long-form-teaser-thread`), push an empty commit `WIP: long-form teaser-thread strategy` with the FOSSE SDD link in the body, and open as draft against `Automattic/wordpress-atmosphere:trunk`. This starts the async review window. Convert to ready-for-review only after Commit 5 lands.
  2. **Commit 1 — composition methods + `atmosphere_long_form_composition` filter + `META_THREAD_RECORDS` constant, with `'link-card'` default unchanged:**
     1. On `Atmosphere\Transformer\Post`, add `public const META_THREAD_RECORDS = '_atmosphere_bsky_thread_records';` alongside the existing `META_URI` / `META_TID` / `META_CID` constants.
     2. Add a private helper `truncate_to_budget( string $text, int $max_graphemes, bool $prefer_sentence = true ): string`:
        - If the grapheme count of `$text` is already ≤ `$max_graphemes`, return `$text` unchanged.
        - Clamp to `$max_graphemes` graphemes using the grapheme-aware helper that Atmosphere already uses (look for `truncate_text` / `Facet::` length helpers — match the convention).
        - **If `$prefer_sentence` is true:** search for the last sentence-ending punctuation (`.`, `!`, `?`) in the clamped string — allow trailing close-quote / close-bracket / close-paren characters after the punctuation. A regex like `preg_match_all( '/[.!?][\"\')\]]?(?=\s|$)/u', $clamped, $matches, PREG_OFFSET_CAPTURE )` finds candidates; take the last match and return `$clamped` truncated to that match's end offset. If at least one match exists, return this truncation and stop.
        - **Word boundary fallback** (either `$prefer_sentence` was false, or no sentence break was found in the window): trim back to the last whitespace boundary using a regex like `preg_replace( '/\s+\S*$/u', '', $clamped )`. If that leaves a non-empty string, return it.
        - **Hard-cap last resort** (single very long word with no whitespace in the window): return the grapheme-clamped string with an ellipsis appended inside the cap (one-grapheme budget for `…`).
        - Covered by tests in Commit 2.
     3. Add private method `build_truncate_link_text(): string` (used by the `'truncate-link'` strategy — not the final-prose-before-CTA case):
        - Call the existing shared plain-text helper `$this->render_post_content_plain( $this->object )` (confirmed present on `Post`; inherited from `Transformer\Base` per DOTCOM-16838).
        - Compute budget = `300 − mb_strlen( "\n\n" ) − mb_strlen( $this->get_permalink() )` (use the grapheme-aware length helper used elsewhere in Atmosphere).
        - Return `truncate_to_budget( $plain, $budget, $prefer_sentence = false ) . "\n\n" . $this->get_permalink()`. `'truncate-link'` is a single-post strategy where the permalink follows immediately; a word-boundary cut is sufficient.
     4. Add private method `build_teaser_thread(): array`:
        - Compute hook = `truncate_to_budget( $this->render_post_content_plain( $this->object ), 280, $prefer_sentence = true )`. The hook is the final prose cut before the CTA, so sentence boundary is required. 280 leaves room for future trailing content without blowing the 300 cap.
        - Compute CTA = `sprintf( __( 'Continue reading: %s', 'atmosphere' ), $this->get_permalink() )`.
        - Return `apply_filters( 'atmosphere_teaser_thread_posts', array( $hook, $cta ), $this->object )` — the filter lets downstream override the defaults or extend to 3 posts. **Note for future 3-post variant:** if the filter returns 3 entries, the intermediate body-to-body cut (entry 1 → entry 2) can be word-boundary (`$prefer_sentence = false`); the final body entry before the CTA (entry 2 → entry 3) must be sentence-boundary. The filter's return contract doesn't currently capture this — downstream filter authors are responsible for respecting it. Worth a PHPDoc note on the filter.
     5. Add public method `build_long_form_records(): array` — this is what Publisher calls for long-form posts:
        - `$strategy = apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->object );`
        - `switch ( $strategy )`:
          - `'teaser-thread'`: iterate `build_teaser_thread()`; for each post-text string, compute `$facets = Facet::extract( $text, $this->object )`; build a record array `[ 'text' => $text, 'facets' => $facets, 'langs' => $this->get_langs(), 'embed' => null ]`. (Do NOT set `createdAt` here — Publisher stamps at write time. Do NOT set `reply` here — Publisher fills refs at write time.)
          - `'truncate-link'`: single entry `[ 'text' => build_truncate_link_text(), 'facets' => Facet::extract(...), 'langs' => get_langs(), 'embed' => null ]`.
          - `'link-card'` (and any unknown value): single entry reproducing today's long-form composition — `[ 'text' => build_text(), 'facets' => existing logic, 'langs' => get_langs(), 'embed' => build_embed() ]`.
        - Before returning each entry, apply `$record = apply_filters( 'atmosphere_transform_bsky_post', $record, $this->object );` so the existing post-transform hook runs consistently for every record in the thread (matching today's behavior for single posts).
     6. Leave `Post::transform()` alone. It still handles short-form (via `atmosphere_is_short_form_post`) and today's long-form path; legacy callers stay on today's shape. Publisher is the only caller that will adopt `build_long_form_records()` (see Commit 3).
     7. In `readme.txt` Changelog (unreleased), describe the new filter + methods. State that default behavior is unchanged (upstream default is `'link-card'`).
     8. Run `composer test` and `composer lint` (upstream PR convention).
     9. Commit: `Add atmosphere_long_form_composition filter and long-form record composition methods`.
  3. **Commit 2 — tests for composition methods:**
     1. In `tests/phpunit/tests/transformer/class-test-post.php`, add:
        - `test_truncate_to_budget_prefers_sentence_when_enabled()` — multi-sentence input; `$prefer_sentence = true`; assert the cut ends at `.`/`!`/`?` (not mid-sentence), even though a word boundary exists later in the budget.
        - `test_truncate_to_budget_allows_trailing_close_punctuation()` — input ends a sentence with `!")` — assert the cut includes the closing `)` after the `!`.
        - `test_truncate_to_budget_falls_back_to_word_boundary_when_no_sentence()` — long opening clause with no `.`/`!`/`?` in the budget; `$prefer_sentence = true`; assert word-boundary cut (no mid-word).
        - `test_truncate_to_budget_word_boundary_only_when_prefer_sentence_false()` — `$prefer_sentence = false`; sentence break exists but cut should be at word boundary regardless.
        - `test_truncate_to_budget_hard_cap_for_single_long_word()` — input with no whitespace longer than `$max`; assert hard-cap + ellipsis behavior (no infinite loop, no empty string).
        - `test_build_long_form_records_default_is_link_card()` — no filter; `build_long_form_records()` returns 1-entry array whose `text` + `embed` match today's `transform()` output (use `transform()` itself as the oracle).
        - `test_build_long_form_records_applies_atmosphere_transform_bsky_post_per_entry()` — register a transform filter that appends `__transformed__` to every record's text; assert every entry in the returned array carries the marker.
        - `test_build_long_form_records_truncate_link_branch()` — `atmosphere_long_form_composition` → `'truncate-link'`; 1-entry array, text ends with `\n\n` + permalink, `embed === null`, at least one link facet covering the permalink.
        - `test_build_long_form_records_teaser_thread_default_two_entries()` — `atmosphere_long_form_composition` → `'teaser-thread'`; 2-entry array. First entry: body hook, ≤ 280 graphemes, **cut ends at sentence-closing punctuation** (`.`/`!`/`?`), no permalink. Second entry: text matches `/^Continue reading: https?:\/\//` and has a link facet.
        - `test_build_long_form_records_teaser_thread_hook_falls_back_to_word_boundary_when_no_sentence()` — `'teaser-thread'` with a body whose first 280 graphemes have no sentence break; assert hook ends at whitespace (word boundary), no mid-word.
        - `test_build_long_form_records_teaser_thread_filter_extends_to_three()` — additionally register `atmosphere_teaser_thread_posts` returning 3 strings; assert the returned records match the filter's output (3 entries).
        - `test_build_long_form_records_langs_consistent_across_thread()` — `'teaser-thread'`; assert every entry's `langs` equals the root's `langs`.
        - `test_build_long_form_records_facets_extracted_per_entry()` — `'teaser-thread'` with a body containing `#tag` and CTA that naturally contains the URL; assert each entry's facets are extracted against that entry's own text.
        - `test_build_long_form_records_unknown_strategy_falls_back_to_link_card()` — filter → `'nonsense'`; output matches the `'link-card'` default case byte-for-byte.
     2. Run `composer test` → all new tests pass; existing tests untouched.
     3. Commit: `Tests: cover build_long_form_records strategy branches`.
  4. **Commit 3 — Publisher rework for thread strategy + partial-meta writes + rollback + legacy fallback in update/delete:**
     1. In `includes/class-publisher.php`, refactor `publish( WP_Post $post )`:
        - Keep today's short-form detection: Publisher computes `$is_short = apply_filters( 'atmosphere_is_short_form_post', Post::is_short_form( $post ), $post );`. If short, go through today's single-record path unchanged (call `$bsky_transformer->transform()`). This task does not touch short-form.
        - For long-form (`! $is_short`): call `$records = $bsky_transformer->build_long_form_records();`. Note `count( $records )`:
          - `count === 1`: mostly today's code. Build the `applyWrites` batch (bsky post[0] with `createdAt = wp_date( 'c' )` + doc). On success, call the existing `store_results()` as today **and** additionally populate `META_THREAD_RECORDS` with a single-entry `[ { uri, cid, tid } ]` array (so readers of the new key always find something after any successful publish).
          - `count >= 2` (thread): run the sequential-writes-with-rollback flow in step (2) below.
     2. Thread write flow (inside `publish()` or a new private `publish_thread()` helper, whichever reads cleaner — pick during implementation, keep private):
        1. Stamp `records[0]['createdAt'] = wp_date( 'c' );`. First `applyWrites` batch has two creates: root bsky post + doc. Atomic. On failure: return the `WP_Error` unchanged. On success:
           - Extract root's `{ uri, cid }` from the response (index 0 by existing `store_results()` convention).
           - **Write partial meta immediately:** `update_post_meta( $post->ID, Post::META_THREAD_RECORDS, [ [ 'uri' => …, 'cid' => …, 'tid' => Post::get_tid_from_uri( … ) ] ] );` + mirror single-value keys (`META_URI`, `META_TID`, `META_CID`) to root. Also call the existing `update_document_bsky_ref()` now — the doc always refers to the root, unchanged from today.
           - Initialize a local `$thread_records = [ root_triple ];`.
        2. For each `records[$i]` where `$i >= 1`:
           - Fill `records[$i]['reply'] = [ 'root' => $thread_records[0]['uri'/'cid'], 'parent' => $thread_records[$i - 1]['uri'/'cid'] ];` (for a 2-post thread, `parent === root`; for 3-post, `parent === records[1]`).
           - Stamp `records[$i]['createdAt'] = wp_date( 'c' );`.
           - `applyWrites` with a single create of bsky post. On failure:
             - Issue rollback: iterate `$thread_records` in reverse and call `API::apply_writes` with `applyWrites#delete` for each. If any rollback delete fails, log via `error_log` and continue the rollback loop (don't abort).
             - Clear thread records meta and restore legacy mirrors to empty: `delete_post_meta( $post->ID, Post::META_THREAD_RECORDS )`, `delete_post_meta( Post::META_URI )`, etc.
             - Return the original failing `WP_Error` (not the rollback result). If the rollback itself had failures, wrap with `new WP_Error( 'atmosphere_thread_rollback_failed', …, [ 'partial_records' => $thread_records ] );` so an admin retrying sees what's out there.
           - On success: extract `{ uri, cid }`, compute `tid`, append to `$thread_records`, persist updated `META_THREAD_RECORDS` immediately. Continue the loop.
        3. After the loop: return the concatenated `applyWrites` results (for existing return-contract compatibility).
     3. Refactor `update( WP_Post $post )`:
        - Read `META_THREAD_RECORDS`. If absent, construct a 1-element equivalent from the legacy single-value meta (`META_URI` / `META_TID` / `META_CID`) for backwards compat.
        - If `count( $stored ) === 1` and the new `build_long_form_records()` is also 1-entry (same strategy, same shape), issue a single `applyWrites#update` as today and reuse existing helpers.
        - Otherwise (strategy changed, or multi-record → multi-record): delete all existing records (N bsky deletes + 1 doc delete in one `applyWrites` for atomicity), then call `publish()` to write the fresh thread. Post-meta will be fully rewritten by `publish()`.
     4. Refactor `delete( WP_Post $post )`:
        - Read `META_THREAD_RECORDS`, falling back to the single-value meta as a 1-element list. Build a batch of N `applyWrites#delete` for bsky posts + 1 for doc. One atomic `applyWrites` call. On success: clear all four meta keys (`META_THREAD_RECORDS`, `META_URI`, `META_TID`, `META_CID`). On failure: leave meta intact so a retry can complete, return `WP_Error`.
     5. Run `composer test` and `composer lint`.
     6. Commit: `Publisher: sequential-writes-with-rollback for thread strategies, ordered meta, legacy fallback`.
  5. **Commit 4 — tests for Publisher behavior:**
     1. In `tests/phpunit/tests/class-test-publisher.php` (new file; layout mirrors existing `tests/phpunit/tests/transformer/class-test-facet.php`):
        - Test seam: intercept `API::apply_writes` via `add_filter( 'pre_http_request', … )` (how FOSSE's e2e mu-plugin also hooks; see Task 4). Capture each call's body; return mocked success responses.
        - `test_publish_link_card_writes_single_atomic_applywrites()` — default strategy; exactly one `applyWrites` call with 2 creates; `META_THREAD_RECORDS` populated as a 1-entry array; `META_URI`/`META_TID` mirror the root (which is also the only post).
        - `test_publish_teaser_thread_writes_root_first_then_reply_sequentially()` — filter → `'teaser-thread'`; 2 `applyWrites` calls. First call: root bsky + doc, `createdAt` matches wall-clock window, no `reply`. Second call: 1 bsky post, `reply.root` and `reply.parent` both pointing at root's `{uri, cid}`, own `createdAt` later than root's.
        - `test_publish_teaser_thread_partial_meta_written_after_root()` — mock the second `applyWrites` to sleep 100ms; during the sleep, assert `META_THREAD_RECORDS` is already a 1-entry array pointing at root (so crash recovery could surface it).
        - `test_publish_teaser_thread_final_meta_has_ordered_triples()` — happy path; `META_THREAD_RECORDS` = `[ root_triple, cta_triple ]`; `META_URI`/`META_TID` mirror the root.
        - `test_publish_teaser_thread_rollback_on_second_write_failure()` — mock second `applyWrites` to return `WP_Error`; assert: a compensating `applyWrites#delete` for the root was issued (match on URI); all meta keys cleared; return is the original `WP_Error`.
        - `test_publish_teaser_thread_rollback_failing_surfaces_partial_state()` — mock second create AND the compensating delete both to fail; assert: return is a `WP_Error` with `code === 'atmosphere_thread_rollback_failed'` and data containing `partial_records`.
        - `test_update_link_card_unchanged_single_post_uses_in_place_applywrites_update()` — default strategy; seed `META_THREAD_RECORDS` with one triple; `update()` issues one `applyWrites#update` call, not a delete + republish.
        - `test_update_thread_rewrites_on_strategy_change()` — seed `META_THREAD_RECORDS` with a 1-entry triple (simulates a legacy link-card post); flip the filter to `'teaser-thread'`; `update()` issues deletes for the old record + doc, then a fresh thread publish; final meta is a 2-entry array.
        - `test_delete_thread_removes_all_records()` — seed 2-entry `META_THREAD_RECORDS`; `delete()` issues one `applyWrites` with 3 deletes (2 bsky + 1 doc); all four meta keys cleared afterward.
        - `test_delete_legacy_single_post_meta()` — seed only `META_URI`/`META_TID`/`META_CID` (no thread meta); `delete()` issues one `applyWrites` with 2 deletes (1 bsky + 1 doc); meta cleared.
     2. Run `composer test` → all Publisher tests pass; no regression elsewhere.
     3. Commit: `Tests: Publisher thread writes, rollback, legacy fallback`.
  6. **Commit 5 — readme.txt changelog + mark PR ready for review:**
     1. Changelog bullets (unreleased):
        - "New `atmosphere_long_form_composition` filter selects long-form composition strategy. Accepts `'link-card'` (default, unchanged behavior), `'truncate-link'` (single post, body-as-text + permalink), or `'teaser-thread'` (2-post thread: hook + CTA-with-link)."
        - "New `atmosphere_teaser_thread_posts` filter lets downstream override the default 2-post composition (e.g. expand to 3 posts, customize CTA copy)."
        - "New `_atmosphere_bsky_thread_records` post meta: ordered array of `{ uri, cid, tid }` triples tracking every bsky post in a thread. Existing `_atmosphere_bsky_uri` / `_atmosphere_bsky_tid` / `_atmosphere_bsky_cid` mirror the root post for backwards compatibility."
        - "`Publisher::update()` for thread-strategy posts rewrites the entire thread (delete + re-publish). Two known behavioral implications: (1) any replies other Bluesky users posted to the original thread become orphaned because their `reply.root` points at a deleted AT URI; (2) the replaced posts surface to followers with a fresh `createdAt`, i.e. the Bluesky feed treats an update as a republish. If neither behavior is acceptable for your use case, pin `fosse_long_form_strategy` or `atmosphere_long_form_composition` to a single-post strategy."
        - "Default upstream behavior is unchanged — `atmosphere_long_form_composition` defaults to `'link-card'`. Existing sites see no change unless they opt into a different strategy or install a downstream like FOSSE that projects the value."
     2. Run `composer lint`.
     3. Commit: `Changelog: atmosphere_long_form_composition, thread records meta, update caveats`.
     4. Mark the draft PR as ready for review. PR description summarizes the strategy enum, the thread write semantics (sequential writes with rollback; why not one `applyWrites`; CID-comes-from-server framing), back-references the FOSSE SDD at `Automattic/fosse:sdd/long-form-bluesky-strategy/`, and notes the `update()` caveats. Request review from Matthias.
- **Verify**:
  - All new tests pass locally and in upstream CI.
  - Existing tests still pass (zero-regression on `'link-card'` behavior).
  - PR approved (Matthias or delegate) and merged to `trunk`; note merge SHA for Task 2.
- **Depends on**: none. Short-form work on trunk provides `render_post_content_plain()` and the `atmosphere_is_short_form_post` filter already.

### Task 2 [FOSSE]: Refresh `bundled/atmosphere/` after Task 1 merges

- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Files**: [`bundled/atmosphere/**`](https://github.com/Automattic/fosse/tree/trunk/bundled/atmosphere)
- **Do**:
  1. Ensure `~/code/wordpress-atmosphere` is on `trunk` at or past the Task-1 merge SHA:
     ```
     git -C ~/code/wordpress-atmosphere fetch origin
     git -C ~/code/wordpress-atmosphere checkout trunk
     git -C ~/code/wordpress-atmosphere pull --ff-only
     ```
  2. From the FOSSE checkout: `./tools/sync-bundled.sh`.
  3. Sanity-check the sync:
     - `grep -n atmosphere_long_form_composition bundled/atmosphere/includes/transformer/class-post.php` — hit.
     - `grep -n build_long_form_records bundled/atmosphere/includes/transformer/class-post.php` — hit.
     - `grep -n build_teaser_thread bundled/atmosphere/includes/transformer/class-post.php` — hit.
     - `grep -n META_THREAD_RECORDS bundled/atmosphere/includes/transformer/class-post.php` — hit.
     - `grep -rn atmosphere_bsky_thread_records bundled/atmosphere/includes/` — hit in `class-publisher.php`.
  4. Run `composer run-script test-php` and `pnpm run test:e2e` locally. Short-form path and existing long-form link-card e2e must still pass (which they should — default `atmosphere_long_form_composition` is `'link-card'`, i.e. today's behavior, and the long-form link-card e2e was written against that).
  5. Commit: `Bundled atmosphere: pull in atmosphere_long_form_composition + thread Publisher (upstream <SHA>)`.
- **Verify**:
  - All local tests pass.
  - PHPCS exclusion on `bundled/` still holds.
- **Depends on**: Task 1 merged upstream.

### Task 3 [FOSSE]: `Long_Form_Strategy` projector + `fosse_long_form_strategy` option

- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Linear**: [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810)
- **Files**:
  - `src/class-long-form-strategy.php` (new; WP `class-*.php` filename convention per Jetpack PHPCS; class stays `Automattic\Fosse\Long_Form_Strategy`)
  - `tests/php/Long_Form_StrategyTest.php` (new; mirror `tests/php/Object_TypeTest.php`)
  - [`fosse.php`](https://github.com/Automattic/fosse/blob/trunk/fosse.php) — register `Long_Form_Strategy` on `init` using the file's **existing anonymous-function + `class_exists` guard pattern**, not a bare class-method array. Read the file for the exact shape before editing and match it.
- **Do**:
  1. Write failing PHPUnit test `Automattic\Fosse\Tests\Long_Form_StrategyTest` (style mirrors `tests/php/Object_TypeTest.php`):
     - `@before`: call `\Automattic\Fosse\Long_Form_Strategy::register()`.
     - `test_unset_option_returns_teaser_thread()`: no option set; `apply_filters( 'atmosphere_long_form_composition', 'link-card', $post )` returns `'teaser-thread'`. **This is the FOSSE-opinionated default — it coerces unset → `'teaser-thread'`, unlike `Object_Type` which passes through.**
     - `test_teaser_thread_option_returns_teaser_thread()`: `update_option( 'fosse_long_form_strategy', 'teaser-thread' )`; filter returns `'teaser-thread'`.
     - `test_truncate_link_option_returns_truncate_link()`: `update_option( 'fosse_long_form_strategy', 'truncate-link' )`; filter returns `'truncate-link'`.
     - `test_link_card_option_returns_link_card()`: `update_option( 'fosse_long_form_strategy', 'link-card' )`; filter returns `'link-card'`.
     - `test_document_card_option_returns_document_card()`: `update_option( 'fosse_long_form_strategy', 'document-card' )`; filter returns `'document-card'`. (v2 target; projector is enum-agnostic about which strategies are supported upstream — if Atmosphere doesn't know the value, Atmosphere falls back to `'link-card'` on its own side. Projector passes through any known-shape value.)
     - `test_unknown_option_coerces_to_teaser_thread()`: `update_option( 'fosse_long_form_strategy', 'nonsense' )`; filter returns `'teaser-thread'`. Opinionation: FOSSE-installed sites get the FOSSE default when the option is missing or garbage.
     - `test_empty_option_coerces_to_teaser_thread()`: `update_option( 'fosse_long_form_strategy', '' )`; filter returns `'teaser-thread'`.
  2. Run `composer run-script test-php` → verify failures.
  3. Create `src/class-long-form-strategy.php`:
     - Namespace `Automattic\Fosse`.
     - File doc-block following Jetpack PHPCS.
     - Class `Long_Form_Strategy`:
       - `private const OPTION = 'fosse_long_form_strategy';`
       - `private const DEFAULT_STRATEGY = 'teaser-thread';`
       - `private const KNOWN_STRATEGIES = [ 'teaser-thread', 'truncate-link', 'link-card', 'document-card' ];`
       - `public static function register(): void` — `\add_filter( 'atmosphere_long_form_composition', array( self::class, 'filter' ), 10, 2 );`
       - `public static function filter( string $strategy, $post ): string` — read `\get_option( self::OPTION )`; if the value is in `KNOWN_STRATEGIES`, return it; otherwise return `self::DEFAULT_STRATEGY`. (Note: this *coerces* unset/unknown, unlike `Object_Type` which passes through. Deliberate — documented in the class-level doc-block.)
     - Tabs, Yoda, PHPDoc on public methods. Model on `src/class-object-type.php` for style.
  4. Wire into `fosse.php`. Read the file and mirror the existing registration pattern used for `Object_Type::register` — an anonymous function with `class_exists` guard. Example to match (exact shape must be confirmed against the current file):
     ```php
     \add_action( 'init', function () {
         if ( \class_exists( '\Automattic\Fosse\Long_Form_Strategy' ) ) {
             \Automattic\Fosse\Long_Form_Strategy::register();
         }
     } );
     ```
  5. Run `composer run-script test-php` → all tests pass.
  6. Run `composer run-script lint-php` → clean.
  7. Commit: `Add Long_Form_Strategy projector for fosse_long_form_strategy option`.
- **Verify**:
  - All seven unit tests pass.
  - No regressions (`Object_TypeTest` etc.).
  - Manual on Playground (optional): install-fresh should have `fosse_long_form_strategy` unset; publish a 1000-word titled post; confirm the e2e capture (after Task 4 lands) shows a 2-post thread without any manual `wp option set`.
- **Depends on**: Task 2.

### Task 4 [FOSSE]: Rewrite e2e capture helper as `pre_http_request` interceptor + teaser-thread Playwright spec

- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Files**:
  - [`tests/e2e/mu-plugins/fosse-bsky-capture.php`](https://github.com/Automattic/fosse/blob/trunk/tests/e2e/mu-plugins/fosse-bsky-capture.php) — full rewrite. Today it hooks `transition_post_status` and calls the transformers directly, which captures transformer *output* before Publisher runs. The thread path requires capturing actual HTTP `applyWrites` calls since Publisher issues N of them per thread. Switch to intercepting via `pre_http_request`.
  - [`tests/e2e/long-form-link-card.spec.ts`](https://github.com/Automattic/fosse/blob/trunk/tests/e2e/long-form-link-card.spec.ts) — update to read the new capture shape.
  - [`tests/e2e/short-form-facets.spec.ts`](https://github.com/Automattic/fosse/blob/trunk/tests/e2e/short-form-facets.spec.ts) — update to read the new capture shape.
  - `tests/e2e/long-form-teaser-thread.spec.ts` (new).
  - [`tests/e2e/blueprint.json`](https://github.com/Automattic/fosse/blob/trunk/tests/e2e/blueprint.json) — if a new e2e REST endpoint is needed (for setting `fosse_long_form_strategy`), register it here.
- **Do**:
  1. **Read the current mu-plugin** at `tests/e2e/mu-plugins/fosse-bsky-capture.php` and both existing specs. Confirm the current hook point and capture-file shape before rewriting.
  2. **Rewrite the mu-plugin:**
     - Hook `pre_http_request` at priority 10 with 3 args.
     - Inspect `$parsed_args['body']` (or the request URL if Atmosphere routes applyWrites through a specific endpoint — check `~/code/wordpress-atmosphere/includes/class-api.php` during implementation). Identify calls targeting `com.atproto.repo.applyWrites`.
     - For matching calls: append an entry to a per-request array of `{ timestamp, writes }` where `writes` is the parsed JSON body's `writes` array. Return a mocked success response that matches the PDS's `applyWrites` response shape — one `CreateResult` per `#create` with a plausible `uri` (using the `rkey` + a fake DID) and a plausible `cid` (hash the input to produce a stable placeholder). The mock response must be realistic enough that Publisher's `store_results()` can parse `uri` / `cid` out of each result.
     - After the Publisher call chain completes, write the accumulated `calls` array to `/wp-content/uploads/fosse-bsky-capture.json` as `{ post_id, calls: [...] }`. Truncate the file at the start of each publish (hook `transition_post_status` on the way in with priority 1 to reset — or drive from the pre_http_request itself when it first sees an applyWrites).
     - Keep `post_id` and the now-lazy `bsky_record` / `doc_record` convenience fields by extracting them from `calls[0].writes` (first-call = root + doc) — so existing specs can keep `bsky_record` and `doc_record` unchanged shape-wise.
  3. **Update `long-form-link-card.spec.ts`** to read `calls[0].writes` instead of the legacy top-level fields (or rely on the legacy convenience fields above; either works — pick cleaner).
  4. **Update `short-form-facets.spec.ts`** similarly.
  5. **Create `long-form-teaser-thread.spec.ts`:**
     - Pattern reference: `long-form-link-card.spec.ts`.
     - At the start of the test: set `fosse_long_form_strategy = 'teaser-thread'`. If a REST endpoint for this doesn't exist in the blueprint, add one (sibling to the `fosse_object_type` endpoint that already exists for the short-form spec). Alternative: drive via `wp-cli` in a blueprint pre-step.
     - Create a post via REST: title (5+ words) + body (500+ words of lorem with `#tag` woven into the first paragraph, `@user.bsky.social` mention, and a `https://example.com` URL in the first paragraph so the hook captures them).
     - Poll the capture file; expect `calls.length === 2`.
     - **Assertions on `calls[0].writes` (first applyWrites — root + doc):**
       - 2 writes: one `app.bsky.feed.post` + one `site.standard.document`.
       - The bsky record: `text` starts with the first ~280 graphemes of the body, **ending at sentence-closing punctuation** (`.`/`!`/`?`) — not mid-word, not mid-sentence. No `embed`, no `reply`, `createdAt` set, `langs` non-empty. Facets include one tag facet and (if mention resolution worked in test env) one mention facet and one link facet.
       - The doc record is present (DOTCOM-16809 guard).
     - **Assertions on `calls[1].writes` (second applyWrites — CTA reply):**
       - 1 write: `app.bsky.feed.post`.
       - `text` matches `/^Continue reading: https?:\/\//`.
       - `reply.root.uri` matches the root URI from `calls[0]`; `reply.parent.uri` === `reply.root.uri` (for a 2-post thread, parent is the root).
       - `reply.root.cid` and `reply.parent.cid` match the mocked CID from `calls[0]`.
       - `langs` matches the root's `langs`.
       - Facets include a link facet covering the permalink.
     - **Post-meta assertions via REST or wp-cli:**
       - `_atmosphere_bsky_thread_records` has 2 entries in order.
       - `_atmosphere_bsky_uri` === `calls[0].writes[0].rkey`-based AT URI (mirror to root).
       - `_atmosphere_bsky_tid` matches the root's TID.
  6. Run `pnpm run test:e2e` → all three specs pass.
  7. Commit: `E2E: teaser-thread spec + pre_http_request capture rewrite`.
- **Verify**:
  - All three specs pass locally and in CI.
  - No regressions from the capture-helper rewrite.
- **Depends on**: Task 3.

### Task 5 [FOSSE]: Changelog + AGENTS.md note

- **Status**: Not started
- **Repo**: `Automattic/fosse`
- **Files**:
  - `readme.txt` (upstream convention; FOSSE does not have a `CHANGELOG.md`).
  - [`AGENTS.md`](https://github.com/Automattic/fosse/blob/trunk/AGENTS.md).
- **Do**:
  1. Add a `readme.txt` changelog entry for:
     - New `fosse_long_form_strategy` option; FOSSE-opinionated default `'teaser-thread'`; opt-in values `'link-card'`, `'truncate-link'`, and (v2) `'document-card'`.
     - Side effects of `'teaser-thread'` when posts are updated: other Bluesky users' replies to the original thread are orphaned; the Bluesky feed treats an update as a republish (fresh `createdAt` on the replaced posts).
  2. In `AGENTS.md`, under "Upstream contribution policy" (added in PR #23), append a second worked example mirroring the existing one:
     - **Upstream** — Atmosphere's `atmosphere_long_form_composition` filter, the `build_long_form_records()` / `build_teaser_thread()` / `build_truncate_link_text()` composition methods, the `META_THREAD_RECORDS` constant, and the `Publisher::publish/update/delete` thread redesign. All describe a universal "how does Atmosphere compose long posts, and how do thread writes work" concern that's valuable to any consumer of `wordpress-atmosphere`.
     - **FOSSE** — `Automattic\Fosse\Long_Form_Strategy` projector that reads `fosse_long_form_strategy` and drives the single upstream filter. Thin projector only.
  3. Commit: `Docs: long-form strategy changelog + upstream-first worked example`.
- **Verify**:
  - Markdown renders cleanly.
  - Links work.
- **Depends on**: none — parallelizable once Task 1's draft PR is open (even pre-merge, the upstream shape is locked enough to document).
