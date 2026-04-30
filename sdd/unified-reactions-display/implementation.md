# Implementation Notes — Unified Reactions Display

## Verification Result

**The central thesis of this SDD held.** AP's `bundled/activitypub/build/reactions/` block displays both AP and Bluesky reaction rows on the same post without any FOSSE-side changes to the block itself — its `get_comments(['type' => 'like', ...])` query is keyed on `comment_type`, not on the `protocol` comment-meta value, so Atmosphere's `protocol='atproto'` rows match alongside AP's `protocol='activitypub'` rows.

The Playwright spec at `tests/e2e/reactions-display.spec.ts` proves this end-to-end on Playground:

- Likes group shows count `2` (one AP-protocol like + one AT-protocol like).
- Reposts group shows count `1` (AT-protocol repost only — AP has no repost in seed).
- The block's `data-wp-context` JSON contains author names from both protocols (`Alice via Mastodon`, `Bob via Bluesky`, `Carol via Bluesky`), proving the avatar template will hydrate them client-side.
- `/wp/v2/block-types/activitypub/reactions` returns `title === 'Social Reactions'` and `description === 'Display social likes and reposts for your posts.'`, proving the FOSSE relabel reached the registered metadata.

Final suite state on `add/unified-reactions-DOTCOM-16894`:

- PHPCS (Jetpack ruleset): 25/25 files clean.
- PHPUnit: 74/74 cases pass, 117 assertions. The new `Reactions_LabelTest` adds 4 cases / 10 assertions.
- ESLint: clean.
- Prettier: clean.
- Playwright: 10/10 specs pass (full suite, not just new spec).

## Deviations from Spec

### Seed mu-plugin uses a REST endpoint instead of auto-on-load + idempotency marker

- **Spec said**: "Mu-plugin that runs once to insert a published post + reaction comments split between protocols. Should be idempotent (don't double-insert on reload)."
- **Implementation does**: Registers a `POST /wp-json/fosse-e2e/v1/seed-reactions` endpoint that the spec calls explicitly. Returns `{ post_id, post_url, comment_ids }` in the response.
- **Reason**: Gives the Playwright spec deterministic control over timing (seed before navigation, capture the seeded post URL directly from the response) and matches the existing `fosse-bsky-capture.php` pattern of providing test-only REST helpers under `fosse-e2e/v1`. Idempotency-via-marker would have required option storage and the spec couldn't have read back the post URL without an extra round-trip.
- **Impact**: None for production (the mu-plugin is test-only). The spec is one fetch shorter than an auto-seeded variant would have been.

### E2E asserts the relabel via `/wp/v2/block-types`, not the editor inserter

- **Spec said**: "assert the relabeled inserter title appears in admin contexts (or, if simpler, a settings/customizer surface where block metadata is rendered)" — explicitly flagged as a plan-phase decision.
- **Implementation does**: `GET /wp-json/wp/v2/block-types/activitypub/reactions` and asserts `title` and `description` from the JSON response.
- **Reason**: The block-types REST endpoint is the canonical source of registered block metadata; it's what the editor inserter consumes too. Asserting on the metadata directly is faster, more deterministic, and isolates the relabel concern from any editor UI flake.
- **Impact**: None. The spec proves the same invariant via a more direct path.

### Block markup is not embedded in the seed post; relies on AP's `blockHooks`

- **Spec said**: nothing explicit; the plan suggested putting `<!-- wp:activitypub/reactions /-->` in the post content.
- **Implementation does**: The seed post contains only a paragraph block. AP's `block.json` declares `"blockHooks": { "core/post-content": "after" }`, which causes WordPress to auto-inject the reactions block after `core/post-content` at render time.
- **Reason**: Initial attempt to embed the block markup in `post_content` returned a 500 from `wp_insert_post` (not yet root-caused; likely related to block parsing during save). Switching to `blockHooks` auto-injection sidesteps the issue and matches how a real site renders the block.
- **Impact**: The e2e is closer to real-site behavior. If the seed post needs explicit block control later, the root cause of the 500 should be investigated.

### Mu-plugin error diagnostics expanded slightly during e2e debugging

- **Spec said**: simple insert with WP_Error fall-through.
- **Implementation does**: separate WP_Error vs zero-return branches with distinct error codes (`fosse_e2e_seed_post_failed` with the underlying `WP_Error::get_error_message()` vs `'wp_insert_post returned 0.'`).
- **Reason**: While debugging the Playground 500 mentioned above, finer-grained error responses pointed at the failing step. Worth keeping for future regressions.
- **Impact**: None for production. A failing seed will now report a more useful message in the Playwright assertion's text.

## Open Follow-ups

These are intentionally out of v1 scope but worth noting now that the verification has been done:

- **Legacy v1.0.0 fallback string** in `bundled/activitypub/build/reactions/render.php:40` (`__('Fediverse Reactions', 'activitypub')`) is still not covered. No real-install evidence yet of a v1.0.0 block surviving a migration; defer unless one shows up.
- **Replies handling.** Replies from both networks land as standard `comment_type='comment'` rows with `comment_parent` linkage. They appear in WP's native comment list, not in the reactions block (which filters on `parent=0`). A separate SDD can take this on if the reply-display surface needs FOSSE-side polish.
- **Per-source visual distinction.** Source-agnostic v1 was the explicit choice. If a future user request justifies it, the `protocol` comment-meta value is already there — a small filter on the block's render output (or a FOSSE-owned block) could badge each item by source.
- **A FOSSE-owned reactions block.** Still a possibility for v2; this SDD's outcome is evidence for *not* building it yet — the unified-display promise is satisfied without one. Revisit if a block-shape concern (different layout, FOSSE-specific data model) emerges.
- **Quote-post handling on Bluesky side.** AT Protocol has no native quote; AP's `quote` `comment_type` stays AP-only by definition. Not a gap.
- **Atmosphere not registering its `comment_type` values with `register_comment_type`.** Not a practical issue: AP's `get_comments()` query is type-name-based, not registry-based. The earlier brainstorm-phase agent's claim that this caused invisibility was wrong — the e2e has now confirmed it. No upstream action required for the unified-display goal.

## Files Touched

| Path | Change |
|------|--------|
| `src/class-reactions-label.php` | new — projector class |
| `fosse.php` | new `add_action('init', …)` block registering `Reactions_Label` |
| `tests/php/Reactions_LabelTest.php` | new — 4 PHPUnit cases |
| `tests/e2e/mu-plugins/fosse-reactions-seed.php` | new — test mu-plugin |
| `tests/e2e/reactions-display.spec.ts` | new — Playwright spec |
| `tests/e2e/blueprint.json` | one new `cp` step for the seed mu-plugin |
| `sdd/unified-reactions-display/{requirements,spec,plan,implementation}.md` | the SDD record itself |

## Branch

`add/unified-reactions-DOTCOM-16894`, branched off `trunk` at `133d8f8` (the merge commit for PR #29 / DOTCOM-16887). Not yet pushed at the time this note is written; user reviews locally before push.
