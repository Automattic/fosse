# Implementation Plan: Bluesky Native Publishing

Based on: sdd/bluesky-native-publishing/spec.md

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

## Cross-Repo Note

Task 1 lands in `Automattic/wordpress-atmosphere` (locally `~/code/wordpress-atmosphere`), **not** FOSSE. It must be opened as a PR, reviewed, and merged there before Task 2 can run. Work pauses between Task 1's submission and merge; resume at Task 2 once `tools/sync-bundled.sh` can pull the new code into `bundled/atmosphere/`.

Code references throughout the plan link to `trunk` on GitHub. They map to local checkouts at `~/code/<repo-name>` (e.g. `Automattic/wordpress-atmosphere` → `~/code/wordpress-atmosphere`).

## Tasks

### Task 1 [UPSTREAM]: Make Atmosphere `Post` transformer post-format-aware
- **Repo**: `Automattic/wordpress-atmosphere`
- **Files**:
  - [`includes/transformer/class-post.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php)
  - [`includes/transformer/class-base.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-base.php)
  - [`includes/transformer/class-document.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-document.php) (refactor only)
  - `tests/phpunit/tests/transformer/class-test-post.php` (new — file does not exist on trunk; sibling [`class-test-facet.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/tests/phpunit/tests/transformer/class-test-facet.php) shows the convention)
  - [`readme.txt`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/readme.txt)
- **Do**:
  1. Cut a working branch off `trunk`, e.g. `add/short-form-post-format`.
  2. Extract the plain-text rendering helper from `Document::get_text_content()` into a protected method on `Transformer\Base` (e.g. `render_post_content_plain( \WP_Post $post ): string`). The body is the existing three-step: `apply_filters( 'the_content', $post->post_content )`, `wp_strip_all_tags`, `html_entity_decode( …, ENT_QUOTES, 'UTF-8' )`, then `trim( preg_replace( '/\s+/', ' ', $content ) )`. Replace the inlined logic in `Document::get_text_content()` with a call to the new helper. Confirm no behavior change (existing tests should still pass).
  3. In `Post`, add a private method `is_short_form( \WP_Post $post ): bool` that mirrors the AP plugin's [`get_type()`](https://github.com/Automattic/wordpress-activitypub/blob/trunk/includes/transformer/class-post.php) discriminator:
     - Return `true` if `! post_type_supports( $post->post_type, 'title' )` OR `empty( $post->post_title )`.
     - Return `true` if `get_post_format( $post )` is non-empty (i.e. any post format set).
     - Return `false` otherwise.
  4. In `Post`, add a private method `build_short_form_text(): string` that calls the shared `render_post_content_plain()` helper and clamps with `truncate_text( $text, 300 )`.
  5. In `Post::transform()`, branch on `is_short_form( $this->object )`:
     - Short-form branch: `$text = $this->build_short_form_text(); $embed = null;`
     - Long-form branch: `$text = $this->build_text(); $embed = $this->build_embed();` (existing path verbatim)
     - Continue with the existing facets / record assembly using `$text` and `$embed`.
  6. Write `tests/phpunit/tests/transformer/class-test-post.php` from scratch. Suggested cases:
     - `test_long_form_titled_no_format_unchanged()`: titled post, no format → text contains title, excerpt, permalink; embed is `app.bsky.embed.external`. Asserts byte-identical output to current behavior.
     - `test_short_form_when_untitled()`: post with empty `post_title` → text equals plain-text body; no `embed` key.
     - `test_short_form_when_post_format_set()`: titled post + `set_post_format( $id, 'status' )` → text equals plain-text body; no embed.
     - `test_short_form_truncates_over_cap()`: short-form with a 500-grapheme body → text is 300 graphemes ending in the truncate marker.
     - `test_aside_format_also_short_form()`: titled + `aside` format → short-form.
  7. Update `readme.txt` Changelog (unreleased section) with one line: "Short-form posts (untitled or with a post format) now publish as native Bluesky posts instead of link cards, matching the ActivityPub plugin's `Note` discriminator."
  8. Run upstream's `composer run-script test-php` and `composer run-script lint-php` (or whatever Atmosphere's scripts call them); fix any issues.
  9. Push branch, open PR against `Automattic/wordpress-atmosphere` `trunk`. PR description should: (a) explain the discriminator mirrors AP's `get_type()` for cross-network symmetry, (b) cite the FOSSE SDD as the design source, (c) note the `Document::get_text_content()` refactor is bundled but separable on request, (d) link to the AP `get_type()` source for reviewer convenience.
- **Verify**:
  - All new tests pass; all existing atmosphere tests still pass.
  - PR is green on upstream CI.
  - PR merged to atmosphere `trunk`.
- **Depends on**: none
- **Handoff**: Note the merge SHA in Task 2's commit body for traceability.

### Task 2: Refresh `bundled/atmosphere/` after upstream merge
- **Repo**: `Automattic/fosse`
- **Files**: [`bundled/atmosphere/**`](https://github.com/Automattic/fosse/tree/trunk/bundled/atmosphere)
- **Do**:
  1. Ensure `~/code/wordpress-atmosphere` is on `trunk` at or past the merge SHA from Task 1.
  2. Run `./tools/sync-bundled.sh`.
  3. Sanity-check: `grep -n is_short_form bundled/atmosphere/includes/transformer/class-post.php` returns the new method.
  4. Commit: `Bundled atmosphere: pull in post-format-aware short-form path (upstream <SHA>)` — include the upstream merge SHA in the commit body.
- **Verify**:
  - `pnpm run test:e2e` and `composer run-script test-php` still pass (no regressions from the bundle refresh).
  - PHPCS exclusion on `bundled/` still holds (no new warnings).
- **Depends on**: Task 1 merged upstream

### Task 3: Playwright e2e — facet parity round-trip on the short-form path (DOTCOM-16811)
- **Repo**: `Automattic/fosse`
- **Files**:
  - `tests/e2e/short-form-facets.spec.ts` (new)
  - `tests/e2e/helpers/atproto.ts` (new, if needed — shared interception/assertion utilities)
  - [`tests/e2e/blueprint.json`](https://github.com/Automattic/fosse/blob/trunk/tests/e2e/blueprint.json) (modify only if a mu-plugin or fixture must be added for OAuth/intercept)
- **Do**:
  1. Decide PDS strategy. Two viable options:
     - **(a) Sandbox PDS**: configure Atmosphere with test credentials in the Playground blueprint and let `applyWrites` hit a real PDS. More realistic, more setup, possible flakiness.
     - **(b) Request interception**: a tiny mu-plugin that filters [`Atmosphere\API::post`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/class-api.php) (or the underlying HTTP path) to capture the outgoing applyWrites payload to disk; the spec reads it and asserts. Pure offline; fully deterministic. Recommended.
  2. Implement strategy (b) by default:
     - Add a Playground mu-plugin that mocks `is_connected()` to return `true`, sets a fake `_atproto_connection` option, and intercepts `Atmosphere\API::apply_writes` to write the payload to a known path under the upload dir as JSON.
     - Spec: navigate to `/wp-admin/post-new.php`, enter body `"hello #world @alice.test https://example.com"`, set post format to "Status" via the editor sidebar (or via a post-meta REST helper exposed by the mu-plugin), publish, fetch the captured JSON, assert.
  3. Assertions:
     - The `app.bsky.feed.post` write's `text` exactly equals `"hello #world @alice.test https://example.com"`.
     - The write has no `embed` key.
     - `facets` contains exactly three entries:
       - One `app.bsky.richtext.facet#tag` with `tag = "world"`, byte range covering `#world`.
       - One `app.bsky.richtext.facet#mention` covering `@alice.test`. The DID will be `did:web:alice.test` (DNS resolution fails in test env; falls back per [`Facet::resolve_mention`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-facet.php) behavior).
       - One `app.bsky.richtext.facet#link` with `uri` matching the URL, byte range covering it.
  4. Run locally: `pnpm run test:e2e -g short-form-facets`.
  5. Commit: `E2E: verify short-form post facet round-trip (tag, mention, link)`
- **Verify**:
  - Test passes locally and in CI.
  - Closes [DOTCOM-16811](https://linear.app/a8c/issue/DOTCOM-16811).
  - If any facet feature fails, file a separate upstream issue + PR per defect. Do not patch in FOSSE.
- **Depends on**: Task 2

### Task 4: Document upstream-first decision policy
- **Repo**: `Automattic/fosse`
- **Files**: [`AGENTS.md`](https://github.com/Automattic/fosse/blob/trunk/AGENTS.md)
- **Do**:
  1. Add a new section to `AGENTS.md` titled "Upstream contribution policy" (placed near the bottom, after "Common Pitfalls"):
     - Rule: **post-type-agnostic correctness goes upstream; FOSSE-shape-specific behavior stays in FOSSE.**
     - Worked example: this epic. Atmosphere's short-form discriminator is universally useful (any consumer with mixed short/long content benefits) → upstream. A hypothetical FOSSE-only "always treat post X as short" rule would stay in FOSSE.
     - Cite the SDD: `sdd/bluesky-native-publishing/`.
  2. Externally (outside this task's file list, requires explicit approval): post a comment on [DOTCOM-16812](https://linear.app/a8c/issue/DOTCOM-16812) with the same matrix and a link to the SDD. **Ask Kraft before posting.**
  3. Commit: `Docs: capture upstream-first contribution policy`
- **Verify**:
  - `AGENTS.md` renders cleanly; new section is discoverable.
  - Linear comment posted (after explicit approval).
- **Depends on**: none — can run in parallel with Tasks 1–3.
