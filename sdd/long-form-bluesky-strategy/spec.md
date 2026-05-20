---
status: in-progress
---

# Spec: Long-Form Bluesky Strategy

## Goal

Replace FOSSE's current long-form Bluesky output — a link-card teaser that directs readers back to WordPress — with a strategy that reads better on Bluesky while keeping the WordPress site as the canonical full-content home. Get there by introducing a site-wide `fosse_long_form_strategy` option that selects 1-of-N composition strategies, picking a new default that beats today's link card.

This is the long-form half of the [DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795) epic — short-form shipped in PR #18/#21. See `sdd/bluesky-native-publishing/` for the short-form architecture this spec builds on.

## Requirements Summary

-   One site-wide option (`fosse_long_form_strategy`) selects the long-form composition. No per-post override in this epic.
-   Existing Atmosphere users may see a new default on upgrade — acceptable if the new default is clearly better than today's link card (criteria TBD, see Open Questions).
-   Upstream-first: composition logic lives in `wordpress-atmosphere`; FOSSE owns only the option projector.
-   Facet parity must hold (hashtags, mentions, URLs).
-   `site.standard.document` writes stay unchanged — the doc is the persistent full-content artifact regardless of bsky-side strategy.

Full requirements at [`requirements.md`](./requirements.md).

## Recommended v1

**Option 5 (teaser mini-thread) as the new default**, with Option 1 (link card) and Option 2 (truncate + link) preserved as opt-in alternatives via the selector. **Option 3** (document card) becomes the default once Bluesky ships `site.standard.*` rendering. **Option 4** (tweet-storm thread) is rejected outright.

**Decision source:** the original RFC proposed Option 2 as v1 because it had the best cost-per-improvement with no dependencies on unshipped Bluesky features. The [Bluesky devrel call with Jim Ray on 2026-04-23](https://fossep2.wordpress.com/2026/04/23/call-notes-bluesky-intro-jim-ray/) clarified that Option 3's enabling renderer is S-tier on Bluesky's roadmap but months out and multi-iteration. That changed the trade-off: we're not bridging a short gap with Option 2 before Option 3 lands — we're bridging a long one. Paying the upstream cost for Option 5 is worth it when the interim ships for multiple quarters. [Full context on the RFC comment](https://fossep2.wordpress.com/2026/04/22/rfc-how-should-fosse-publish-long-form-posts-to-bluesky/#comment-27).

Reasoning:

-   **Option 5 is the best-feeling output on Bluesky.** A 2–3-post thread (hook, takeaway, CTA-with-link) reads as native-to-the-platform and reportedly gets ~3× the engagement of single posts per Bluesky community growth research. A brutal mid-sentence cut (Option 2) or a link card (Option 1) both read as "posted from elsewhere." Option 5 reads as "wrote this for Bluesky."
-   **Option 3 stays the long-term target** if Bluesky's `site.standard.*` renderer arrives. It's what [standard.site](https://standard.site) was designed for. But today and for the foreseeable future it renders as a "record not found" card in the Bluesky app, which is strictly worse than Option 1. Not a v1 gate.
-   **Option 2 is preserved as an opt-in** because it's genuinely useful for users who want native-feeling text without the thread overhead. It also serves as a fallback when a post is too short to meaningfully split into a thread.
-   **Option 1 stays available** because some users genuinely prefer the link card for driving WP traffic. The selector gives them opt-out.
-   **Option 4 is still a nope.** Full tweet storms read as spam on Bluesky. The upstream cost is the same as Option 5 with worse UX.

**Upstream cost we're taking on.** `Atmosphere\Publisher::publish()` today writes exactly one `app.bsky.feed.post` + one `site.standard.document` atomically via a single `applyWrites` call. Option 5 requires N bsky posts with reply refs. Reply refs are `strongRef {uri, cid}` — and a reply's CID depends on the parent's CID, which only arrives in the `applyWrites` response after the parent is committed. You cannot batch all N posts into one `applyWrites` because the reply records can't be constructed until their parent's CID is known. There is no Bluesky-native thread-create endpoint; their own client does sequential writes post-by-post. The spec picks sequential-writes-with-rollback; details in "Technical Details — Thread write semantics."

## Option Analysis

### Option 1 — Link card (today's behavior)

**Composition**: `{title}\n\n{excerpt}\n\n{permalink}` truncated to 300, plus `app.bsky.embed.external` card with title, description, thumbnail.

| | |
|---|---|
| **Pros** | Ships today; zero engineering cost; thumbnail is valuable for scroll-stopping; driver for WP traffic. |
| **Cons** | Reader must leave Bluesky to read. Link cards have lower in-feed engagement than body-text posts per Bluesky community data. Feels like "Jetpack Social with extra steps" — the thing the parent epic wanted to move past. |
| **Engineering** | None. Preserve as a selectable option. |
| **Upstream work** | None. |

### Option 2 — Truncate + link *(v1 alternative, opt-in via the selector)*

**Composition**: body rendered to plain text, truncated to ~280 graphemes to reserve space for the permalink, then appended with the permalink separated by whitespace. Facet extraction turns the permalink into a link facet. No external embed card.

Example (simplified):
```
The Atmosphere plugin makes WordPress posts show up on Bluesky,
but long posts got cut off as link cards. This changes…

https://example.com/long-post-title
```

| | |
|---|---|
| **Pros** | Looks and feels like a post, matching the short-form path. No external card means the text gets more visual weight. Explicit link is unambiguous. Zero structural change to `Publisher::publish()`. |
| **Cons** | Truncation is brutal — the post is more of a "flash" of content than a readable unit. No thumbnail. WP post needs a compelling opening sentence. Users who relied on the link-card thumbnail for engagement will lose it. |
| **Engineering** | New method `build_truncate_link_text()` on `Atmosphere\Transformer\Post`. New upstream filter `atmosphere_long_form_composition` returning a strategy enum. FOSSE projector hooks the filter. |
| **Upstream work** | Yes — composition method + filter. Tracks alongside DOTCOM-16838's short-form additions. |

### Option 3 — `site.standard.document` record embed *(v2 upgrade target)*

**Composition**: short bsky text (title + brief excerpt) with `app.bsky.embed.record` pointing at the already-written `site.standard.document` record.

| | |
|---|---|
| **Pros** | Full content lives inline once Bluesky's renderer supports `site.standard.*`. Matches what standard.site was designed for. Niche clients already render it (Leaflet, Pckt.blog, Offprint.app). |
| **Cons** | **Today** renders as a "record not found" card in the Bluesky app, which is worse than Option 1. Adoption depends on Bluesky's timeline. Ouranos, Graysky, and other alt clients also don't render it yet. |
| **Engineering** | New composition method that references the doc's AT-URI + CID. Needs access to the doc record's identifiers at transform time (Publisher writes them into post meta; transform() would need to read them). Upstream filter returns `'document-card'`. |
| **Upstream work** | Yes — composition method + filter extension. Modest. |
| **When to default** | When Bluesky ships `site.standard.*` rendering AND it's verified to degrade gracefully on non-supporting clients (worst case: card shows title/excerpt, no content; best case: full content renders inline). |

### Option 4 — Tweet-storm thread *(rejected)*

**Composition**: full body split across N `app.bsky.feed.post` records connected by `reply` refs, posted atomically.

Rejected because: (a) Bluesky's audience responds poorly to tweet-storm style posts; (b) the upstream restructuring cost is the same as Option 5 without Option 5's UX payoff; (c) edit/delete semantics become confusing (user updates the WP post → which bsky posts change, and how?).

### Option 5 — Teaser mini-thread *(recommended for v1 default)*

**Composition**: 2–3 `app.bsky.feed.post` records — hook, optional key takeaway, CTA-with-link — connected by `reply` refs. The `site.standard.document` record is written alongside the root post (unchanged from today). The FOSSE site-wide option selects this strategy; the composition itself is filterable so per-site tuning doesn't require code changes.

**v1 default composition (2 posts):**

-   **Post 1 (root — the hook):** if `$post->post_excerpt` is non-empty, use it as the hook (user-curated wins). Otherwise use the first ~280 graphemes of the post body rendered to plain text, clamped at a **sentence boundary** (last `.`/`!`/`?` ≤ 280, allowing trailing close-quote/bracket). The final prose cut before a CTA is required to be a sentence boundary so the reader lands on something that reads finished rather than dangling. Word boundary is the fallback only when the hook window contains no sentence break. Mid-word is a last-resort floor (single very long word exceeds the cap). No title, no permalink. The body should stand on its own as a scroll-stopper. **Edge case — empty or near-empty body (< 10 graphemes of rendered plain text) with no excerpt:** the post falls back to the `'link-card'` composition for this post only (site option unchanged); a debug log entry explains the fallback.
-   **Post 2 (CTA):** `Continue reading: {permalink}` with the permalink as a link facet. Reply refs point at post 1 (`root` and `parent` both reference the hook).

**3-post variant** (future, opt-in via filter): insert a middle "takeaway" post between hook and CTA. Content source is an open question (excerpt second half? body continuation? auto-generated summary?). v1 defaults to 2; `atmosphere_teaser_thread_posts` filter allows downstream to return 3. In the 3-post case, body-to-body cuts (post 1 → post 2) can be word boundary (mid-word permitted); only the **final body post before the CTA** requires the sentence-boundary rule.

| | |
|---|---|
| **Pros** | Threads of 3-8 posts reportedly get ~3× engagement vs single posts on Bluesky (per [community growth research](https://blog.bskygrowth.com/best-bluesky-growth-strategies-creators-2026/)). Feels native to the platform. The final CTA post still carries the permalink for click-through. Matches the body-as-text native feel of the short-form path we shipped. |
| **Cons** | Big upstream change: `Publisher::publish()` today writes 1 bsky post + 1 doc record atomically via `applyWrites`. Thread shape means N bsky posts with reply refs. Reply refs require `strongRef {uri, cid}` and each reply's parent CID only arrives in the server response from writing the parent — so the thread must be written sequentially, one `applyWrites` call per reply post, with rollback-on-failure. Post meta storage becomes an ordered array of `{uri, cid, tid}` triples. Edit/delete semantics: rewrite the whole thread on update, delete all N on delete. Rewriting on update also orphans any replies other Bluesky users posted to the old thread and resets the post's in-feed timestamp — both documented in the changelog. |
| **Engineering** | Significant upstream work. FOSSE side is just the projector. |
| **Upstream work** | Large but scoped: sequential-writes-with-rollback inside `Publisher::publish/update/delete`, ordered-array post meta (`_atmosphere_bsky_thread_records` holding `{uri, cid, tid}` triples; single-value `_atmosphere_bsky_uri`/`_atmosphere_bsky_tid` preserved as root mirrors for backwards compat), new composition method `build_teaser_thread()` returning an array of N post-text strings, new entry point `build_long_form_records()` returning an ordered array of `{text, embed, facets, langs}` records, the `atmosphere_long_form_composition` filter, and the `atmosphere_teaser_thread_posts` filter. Requires Matthias / upstream buy-in. Opened as a **draft PR early** so async upstream review runs in parallel with FOSSE-side work. |
| **When to pursue** | **Now.** This is the v1 path. |

## Technical Details

This section specs the v1 implementation with Option 5 (teaser mini-thread) as the default, Option 2 (truncate + link) and Option 1 (link card) as selectable alternatives.

### Architecture

Mirrors the `fosse_object_type` pattern on the selector side. One site-wide option, one FOSSE projector, one upstream filter controls strategy. Composition logic and the write-shape redesign live entirely in `Automattic/wordpress-atmosphere`. FOSSE stays a thin option projector.

```
+----------------------------------------------+
|  Automattic/wordpress-atmosphere             |
|                                              |
|  Publisher::publish( $post )                 |
|    if ( is_short_form )                      |
|      records = [ Post::transform() ]         |
|    else                                      |
|      records = Post::build_long_form_        |
|                      records()               |
|        - applies atmosphere_long_form_       |
|          composition filter                  |
|        - dispatches to link-card /           |
|          truncate-link / teaser-thread       |
|                                              |
|    if ( count(records) == 1 )                |
|      applyWrites: record + doc (atomic)      |
|    else  # thread                            |
|      1) applyWrites: records[0] + doc        |
|         → capture root {uri, cid}            |
|         → write partial meta                 |
|      2) for each records[i>=1]:              |
|         fill reply.root = root {uri, cid}    |
|         fill reply.parent = prev {uri, cid}  |
|         stamp createdAt at write time        |
|         applyWrites: single record           |
|         → capture {uri, cid}                 |
|         → append to partial meta             |
|         on failure: rollback prior           |
|      3) persist final ordered meta           |
+--------------------+-------------------------+
                     │
                     │ filter override
                     ▼
+----------------------------------------------+
|  Automattic/fosse                            |
|                                              |
|  Long_Form_Strategy::register()              |
|    hooks atmosphere_long_form_composition    |
|                                              |
|  reads get_option(                           |
|    'fosse_long_form_strategy' ) — unset      |
|    and unknown values default to             |
|    'teaser-thread' (FOSSE-opinionated)       |
+----------------------------------------------+
```

`fosse_long_form_strategy` accepted values:

| Value | Effect | Status |
|-------|--------|--------|
| `'teaser-thread'` (default) | Atmosphere takes the thread branch: 2-post hook + CTA. | v1 |
| `'truncate-link'` | Single post: body truncated to ~280 graphemes + permalink. | v1 |
| `'link-card'` | Explicit opt-in to today's title/excerpt + external embed card. | v1 |
| `'document-card'` | Short teaser post with `app.bsky.embed.record` → `site.standard.document`. | v2 (when Bluesky `site.standard.*` renderer ships) |
| unset | Same as default (`'teaser-thread'`). | |

The enum stays extensible — adding a v2 value or a custom composition later doesn't change the filter shape.

### Thread write semantics

**Why sequential, not one atomic batch.** Reply refs on `app.bsky.feed.post` require `strongRef {uri, cid}`. A reply record's content includes its parent's CID — so you cannot construct the reply record until the parent has been written and the server has returned the parent's CID. The only way around this would be to pre-compute CIDs client-side (DAG-CBOR + SHA-256 + base32 of the full record), which is non-trivial and not a dependency Atmosphere wants to take on for v1. Bluesky's own client does sequential writes for threads; we match.

**Publisher::publish() for the thread path:**

1. Compose the long-form records via `Post::build_long_form_records()` → ordered array of `{ text, embed, facets, langs, createdAt }` entries, no `reply` field yet. The `langs` value is computed once and inherited by every record in the thread (so Bluesky's algorithmic surfacing treats the thread consistently). `createdAt` is **not** filled here — it's stamped at each record's write time in the steps below.
2. **First `applyWrites` call (atomic):** create root `app.bsky.feed.post` (records[0], stamped with `createdAt = now()`) + `site.standard.document`. Response gives root's `{uri, cid}`.
3. **Write a partial-meta entry immediately** after step 2 succeeds: append the root's `{uri, cid, tid}` triple to `_atmosphere_bsky_thread_records` as a 1-entry array and set `_atmosphere_bsky_uri`/`_atmosphere_bsky_tid` to mirror it. This is the crash-recovery guard — if PHP fatals before step 5, we still know a root post exists on Bluesky and can surface or clean it up. Matches today's `store_results()` helper shape; extend that helper to accept a single-record result and append rather than overwrite.
4. **For each subsequent `records[i]` where `i >= 1`:**
    - Fill `reply.root = { uri, cid }` with the root's ref (records[0]'s captured result).
    - Fill `reply.parent = { uri, cid }` with the previous post's ref (records[i−1]'s captured result — **not** always the root; for a 2-post thread parent == root, for a 3-post thread parent of post 3 is post 2).
    - Stamp `createdAt = now()` at write time (sequential monotonicity falls out naturally; don't pre-compute).
    - Call `applyWrites` with a single create. On success: append `{uri, cid, tid}` to `_atmosphere_bsky_thread_records` immediately (same crash-recovery guard). On failure: iterate the partial-meta array in reverse, issue `applyWrites#delete` for each, return the **original** `WP_Error` (not the rollback result). If rollback itself fails, return `new WP_Error( 'atmosphere_thread_rollback_failed', …, [ 'partial_records' => $stored ] )` so the admin can see what's out there.
5. On full success: the post meta is already consistent from the per-write appends in steps 3–4. Also update `update_document_bsky_ref()` with the root's `{uri, cid}` (unchanged from today — the doc always points at the root, not any reply).

**Post meta shape:**

-   `_atmosphere_bsky_thread_records` (new) — ordered array of `{ uri, cid, tid }` associative arrays. One entry per bsky post in the thread. Always present after a successful publish (1-element for `'link-card'` / `'truncate-link'`, N-element for `'teaser-thread'`). Single array-of-triples rather than parallel arrays so positional invariants stay self-contained per element.
-   `_atmosphere_bsky_uri`, `_atmosphere_bsky_tid` (existing, kept) — mirror the root post's `uri` / `tid`. Backwards-compat for legacy callers that only know the single-value keys.
-   Legacy posts published before this change still have only the single-value keys and no `_atmosphere_bsky_thread_records`. `update()` and `delete()` fall back to treating them as 1-element threads (see those methods below).

**Publisher::update():** rewrite the whole thread. Delete all existing posts in the thread (iterate `_atmosphere_bsky_thread_records`, falling back to `_atmosphere_bsky_tid` as a 1-element list for legacy posts) via `applyWrites#delete`, then re-publish using the fresh thread records. Doc record can update in place (not deleted). Two side effects worth documenting in the changelog and admin docs:

-   **Other Bluesky users' replies to the old thread become orphaned** — their `reply.root` / `reply.parent` refs will point at deleted AT URIs. Bluesky renders deleted roots as `[deleted]` in-feed. There is no migration path.
-   **Algorithmic recency resets** — the replaced posts carry the update-time `createdAt`, so they surface to followers again as if fresh. Treat as "republish" not "edit in place." For a correction typo, this may not be what users want; documented as a known limitation.

**Publisher::delete():** iterate `_atmosphere_bsky_thread_records` (fallback: `_atmosphere_bsky_tid` as a 1-element list) and issue `applyWrites` with N bsky deletes + 1 doc delete. On success, clear all four meta keys. On failure, return `WP_Error` — meta is left intact so a retry can complete.

**Facet byte-offsets.** Facets are extracted per post using `Facet::extract()` over that post's own `text` field. Offsets are UTF-8 byte positions (Atmosphere's existing convention). Truncation at the grapheme-boundary cap happens **before** facet extraction, so `Facet::extract()` operates on the truncated text and byte offsets are always consistent with what's in the `text` field.

### Composition (2-post default)

**Post 1 (root — the hook):** precedence: (1) user-set `$post->post_excerpt` if non-empty, passed through `render_post_content_plain()` for consistency and clamped to 300 graphemes if it somehow exceeds; (2) otherwise the first ~280 graphemes of `$post->post_content` rendered to plain text via the shared `Transformer\Base::render_post_content_plain()` helper (from DOTCOM-16838), clamped at a **sentence boundary** ≤ 280 graphemes (last `.`/`!`/`?`, allowing trailing close-quote/bracket/paren). Sentence boundary is required because post 1 is the final prose cut before the CTA. If no sentence break exists in the window, fall back to word boundary. Mid-word is used only as a last resort (single very long word). No title prefix. No permalink in this post. Facets extracted over the text.

**Empty-body fallback.** If the rendered plain-text body is below a small threshold (10 graphemes) AND no user-set excerpt exists, `build_long_form_records()` substitutes the `'link-card'` composition for that post only. The site-wide `fosse_long_form_strategy` / `atmosphere_long_form_composition` option is not changed. A `debug_log` entry records the fallback so ops can tell a "hook was empty, degraded to link-card" event apart from a "user opted into link-card" configuration. This keeps the worst-case output at the current link-card quality instead of an empty thread post.

**Final-record filter semantics.** The existing `atmosphere_transform_bsky_post` filter fires **per record** on thread strategies (once per bsky post in the thread), not once per WP post. This is a behavior change for downstream consumers who register the filter assuming single-post semantics. Documented in `readme.txt`. Consumers who need single-invocation semantics can branch on the record shape they receive; Atmosphere does not try to detect thread context and elide the filter on reply posts.

**Post 2 (CTA):** `sprintf( __( 'Continue reading: %s', 'atmosphere' ), $permalink )`. The permalink becomes a link facet. Reply refs: `root` and `parent` both point at post 1 (for a 2-post thread, parent == root).

**3-post variant (future, filter-opt-in).** A middle "takeaway" post sits between hook and CTA. `reply.root` points at post 1; `reply.parent` points at the immediate previous post in the chain (post 2 for the takeaway; post 2 for the CTA in a 3-post thread). Default composition of the takeaway post is not settled — the `atmosphere_teaser_thread_posts` filter is the current path to ship a 3-post thread; defaults stay at 2 posts until a v1.x decision.

**Composition filter.** `atmosphere_teaser_thread_posts` receives the default array of post-text strings + the `$post` object and returns the array to write. Returning a 3-entry array produces a 3-post thread. Returning a 1-entry array falls back to single-post behavior — an escape hatch, not a common path.

**`langs` inheritance.** Computed once for the thread (source: Atmosphere's existing post-language derivation, unchanged from today's single-post path) and written on every record. This keeps Bluesky's in-feed language filtering consistent across the thread.

### Data Flow

**FOSSE option unset, unknown, or set to `'teaser-thread'`** (default on upgrade):

1. User publishes a 2000-word post (titled, no post format).
2. AP transformer runs. `get_type()` returns `'Article'`. Filter pass-through. AP federates as Article. (Unchanged from today.)
3. Atmosphere `Publisher::publish()` runs. `is_short_form()` returns `false`; Publisher calls `Post::build_long_form_records()`. That method applies `atmosphere_long_form_composition` → FOSSE callback returns `'teaser-thread'`. Returns a 2-entry array `[ { hook + facets + langs }, { CTA + link facet + langs } ]`.
4. First `applyWrites`: root post (stamped `createdAt = now()`) + `site.standard.document`. Atomic. Response stores root `{uri, cid, tid}` → append to `_atmosphere_bsky_thread_records`.
5. Second `applyWrites`: CTA post with `reply.root` and `reply.parent` pointing at root, stamped `createdAt = now()`. Response stores CTA `{uri, cid, tid}` → append to `_atmosphere_bsky_thread_records`.
6. Mirror meta set: `_atmosphere_bsky_uri` and `_atmosphere_bsky_tid` point at the root.

**FOSSE option set to `'truncate-link'`:** single `applyWrites` (one bsky post + doc record, atomic). `_atmosphere_bsky_thread_records` is a 1-element array; `_atmosphere_bsky_uri`/`_tid` match. No reply refs.

**FOSSE option set to `'link-card'`:** byte-identical to today's default behavior. Single post with title + excerpt + external embed card. Same meta shape as `'truncate-link'`.

### Key Components

| Component | Repo | Change |
|---|---|---|
| `Atmosphere\Publisher::publish()` | upstream Atmosphere | Branch on short/long via `is_short_form`. Short: today's single-record path via `Post::transform()`. Long: call `Post::build_long_form_records()`, then either one atomic `applyWrites` (single-record strategies) or sequential-writes-with-rollback with partial-meta writes after each success (thread strategies). |
| `Atmosphere\Publisher::update()` | upstream Atmosphere | Single-record strategies: today's in-place update. Threads: delete all existing thread records (iterating `_atmosphere_bsky_thread_records`, falling back to single-value meta for legacy posts), then re-publish. |
| `Atmosphere\Publisher::delete()` | upstream Atmosphere | Iterate `_atmosphere_bsky_thread_records` (falling back to `_atmosphere_bsky_tid` as a 1-element list for legacy posts); issue N bsky deletes + 1 doc delete via `applyWrites`. |
| `Atmosphere\Publisher::store_results()` | upstream Atmosphere | Extend to handle both the "atomic root + doc" first-call result and per-reply single-record results. Append to `_atmosphere_bsky_thread_records` after each successful write (crash-recovery guard). |
| `Atmosphere\Publisher::update_document_bsky_ref()` | upstream Atmosphere | Unchanged in intent — always points the doc at the root post. In the thread path, call after step 2 completes using the root's `{uri, cid}`. |
| `Atmosphere\Transformer\Post::build_long_form_records()` | upstream Atmosphere (new) | Public entry point for Publisher. Applies `atmosphere_long_form_composition`; returns an ordered array of `{ text, embed, facets, langs }` entries (no `reply`, no `createdAt` — Publisher fills those at write time). Also applies the existing `atmosphere_transform_bsky_post` final-record filter per entry so thread posts go through the same post-processing hooks as single posts. |
| `Atmosphere\Transformer\Post::build_teaser_thread()` | upstream Atmosphere (new) | Returns the default ordered array of post-text strings for the thread composition: 2 entries (hook + CTA). Filterable via `atmosphere_teaser_thread_posts`. |
| `Atmosphere\Transformer\Post::build_truncate_link_text()` | upstream Atmosphere (new) | Body rendered to plain text via the shared helper, clamped to reserve space for the permalink + whitespace, appended with `\n\n{permalink}`. |
| `Atmosphere\Transformer\Post::truncate_to_budget()` | upstream Atmosphere (new private helper) | Clamps a plain-text string at a boundary ≤ `$max` graphemes. Tries sentence boundary first (required for the hook's final prose cut); falls back to word boundary; last resort hard-cap at the grapheme limit with trailing ellipsis. Used by `build_teaser_thread()` for the hook and by `build_truncate_link_text()`. |
| `Atmosphere\Transformer\Post::transform()` | upstream Atmosphere | Short-form branch stays as-is. Long-form branch preserved for legacy callers that still invoke `transform()` directly (stays as today's single-record `build_text()` + `build_embed()` composition; for new callers, `build_long_form_records()` is the entry point). |
| `atmosphere_long_form_composition` filter | upstream Atmosphere (new) | Returns the strategy enum. Default `'link-card'` so existing users see no change when upstream merges standalone. |
| `atmosphere_teaser_thread_posts` filter | upstream Atmosphere (new) | Returns an ordered array of post-text strings. Default is the 2-post hook+CTA composition. |
| `Atmosphere\Transformer\Post::META_THREAD_RECORDS` | upstream Atmosphere (new constant) | `_atmosphere_bsky_thread_records`. Ordered array of `{ uri, cid, tid }` triples. Lives on the `Post` class alongside existing `META_URI`/`META_TID`/`META_CID`. |
| `Automattic\Fosse\Long_Form_Strategy` | FOSSE (new) | Static `register()` on `init`. One filter callback projecting `fosse_long_form_strategy` onto `atmosphere_long_form_composition`. Unset / unknown values coerce to `'teaser-thread'` — the projector is opinionated here, unlike `Object_Type`'s pass-through. |
| `fosse_long_form_strategy` option | FOSSE (new) | Default via the projector is `'teaser-thread'`. Set via `wp-cli option set` for now (UI is out of scope). |

### File Changes

Upstream work lands as **one PR** against `Automattic/wordpress-atmosphere` — composition and Publisher changes ship together because the Publisher branch depends on the composition methods existing. Opened as a draft PR early so async review runs in parallel with FOSSE-side work.

| File | Change Type | Description | Repo |
|------|-------------|-------------|------|
| `includes/class-publisher.php` | modify | Branch short/long; single-atomic path for single-record; sequential-writes-with-rollback + partial-meta for threads; extend `store_results()` and `update_document_bsky_ref()` for the thread path. | `Automattic/wordpress-atmosphere` |
| `includes/transformer/class-post.php` | modify | Add `build_long_form_records()`, `build_teaser_thread()`, `build_truncate_link_text()`, private `truncate_to_budget()` helper (sentence → word → hard-cap), and `META_THREAD_RECORDS` constant. Leave `transform()`'s long-form branch in place for legacy callers. | `Automattic/wordpress-atmosphere` |
| `tests/phpunit/tests/transformer/class-test-post.php` | modify | Tests: default composition is link-card; `'truncate-link'` returns body + permalink; `'teaser-thread'` returns 2 entries (hook + CTA); `atmosphere_teaser_thread_posts` filter extends to 3; word-boundary truncation; facet extraction per entry; `langs` inherited consistently; unknown strategy falls back to link-card; final-record filter applied per entry. | `Automattic/wordpress-atmosphere` |
| `tests/phpunit/tests/class-test-publisher.php` | new | Tests: single-record publish writes one atomic `applyWrites`; thread publish writes root + doc first, then each reply sequentially with correct `reply.root`/`reply.parent`; partial-meta appended after each success; rollback on mid-thread failure deletes prior records in reverse order; rollback-failing surfaces `WP_Error` with `partial_records`; delete iterates thread records; delete falls back to legacy single-value meta; update rewrites the thread; `createdAt` stamped at write time (not pre-computed). | `Automattic/wordpress-atmosphere` |
| `readme.txt` | modify | Changelog entries for new filters, new meta key, default-behavior change, and `update()` caveats (orphaned replies, recency reset). | `Automattic/wordpress-atmosphere` |
| `src/class-long-form-strategy.php` | new | `Automattic\Fosse\Long_Form_Strategy` projector class. Unset / unknown coerce to `'teaser-thread'`. | `Automattic/fosse` |
| `tests/php/Long_Form_StrategyTest.php` | new | PHPUnit coverage: unset → `'teaser-thread'`; each known enum value projects correctly; unknown values coerce to `'teaser-thread'` (documented opinionation). | `Automattic/fosse` |
| `fosse.php` | modify | Register `Long_Form_Strategy` on `init` using the existing anonymous-function + `class_exists` guard pattern. | `Automattic/fosse` |
| `bundled/atmosphere/**` | regenerated | `tools/sync-bundled.sh` after upstream PR merges. | `Automattic/fosse` |
| `tests/e2e/mu-plugins/fosse-bsky-capture.php` | rewrite | Replace the `transition_post_status`-driven capture with a `pre_http_request` interceptor that records every `com.atproto.repo.applyWrites` HTTP call and returns mock success responses (so no real network traffic). Emits an ordered array of captured calls. Legacy single-call consumers read `calls[0]`; the two existing specs (`long-form-link-card.spec.ts`, `short-form-facets.spec.ts`) updated to the new shape. | `Automattic/fosse` |
| `tests/e2e/long-form-teaser-thread.spec.ts` | new | Playwright e2e: publish a long titled post under `'teaser-thread'`; verify two captured `applyWrites` calls (root + doc; CTA reply); hook is body-as-text with word-boundary cut and no permalink; CTA starts with `Continue reading:` with a link facet over the permalink; reply refs correct; `_atmosphere_bsky_thread_records` has 2 triples in order; legacy meta mirrors the root. | `Automattic/fosse` |
| `AGENTS.md` | modify | Append long-form worked example to the "Upstream contribution policy" section. | `Automattic/fosse` |

### Upgrade Path to Option 3 (document card)

When Bluesky's `site.standard.*` renderer ships (tracked in [DOTCOM-16827](https://linear.app/a8c/issue/DOTCOM-16827)) and is verified to degrade acceptably on non-supporting clients, a follow-up PR:

1. Adds `build_document_card_text()` + the `'document-card'` branch in Atmosphere.
2. Extends the filter's accepted values.
3. Flips `fosse_long_form_strategy` default from `'teaser-thread'` to `'document-card'`.

No breaking change for users on explicit `'link-card'` or `'truncate-link'`. Users on `'teaser-thread'` get the upgrade on option-default flip. Thread-shape meta keys stay valid (single-element arrays for doc-card) — no migration.

## Out of Scope

-   Per-post strategy override UI — deferred to composer epic (DOTCOM-16794) or a follow-up.
-   Bluesky's own `site.standard.*` renderer support (tracked [DOTCOM-16827](https://linear.app/a8c/issue/DOTCOM-16827)).
-   Reader-side discovery of alt clients / other AT lexicons ([DOTCOM-16859](https://linear.app/a8c/issue/DOTCOM-16859)).
-   Option 4 (tweet-storm thread) — rejected.
-   Changes to AP's long-form `Article` shape on Mastodon.

## Open Questions (held for team / upstream discussion)

The list below is what's genuinely still open — the applied defaults are in "Open Questions Resolved."

1.  **3-post variant default composition.** What goes in the takeaway post by default if someone opts into a 3-post thread via `atmosphere_teaser_thread_posts` — post excerpt second half, body continuation, auto-generated summary, or no opinionated default (filter-only)? Not a v1 blocker; v1 ships 2-post and leaves the 3-post path filter-only.
2.  **Atomic-write upgrade path.** If Bluesky (or an a8c effort) ships a thread-create API that's atomic, or someone invests in client-side CID computation, how does the write path change? Keep the thread-write code narrow enough to swap. Not a v1 blocker.

## Review Notes (from PR #24 review)

Gaps flagged during review that need verification before the upstream PR leaves draft:

1.  **Delete gap on force-delete.** `on_before_delete` only captures the root TID — thread reply posts would be orphaned on force-delete. The spec's `Publisher::delete()` section describes iterating `_atmosphere_bsky_thread_records`, but the implementation needs to confirm the hook fires with access to thread meta and handles the full array, not just the root. Fix before the upstream PR leaves draft — orphaned posts on Bluesky are a bad look.
2.  **`createdAt` behavior change for link-card.** The thread path stamps `createdAt = now()` at write time (sequential monotonicity). This is a change from the link-card path where `createdAt` previously used the post's published date. Subtle breaking change for existing users switching strategies. Needs explicit upstream review and changelog documentation — don't let this ship quietly.

## Open Questions Resolved

Decisions carry the resolver in parens. Defaults marked "applied (confirm)" are my calls that the author should redirect on PR #24 if wrong — but are safe enough to build against in the meantime.

-   **Scope of selection model**: site-wide option, no per-post override in v1. (Resolved in brainstorm.)
-   **Should we re-open PR #18's rejection of Options 3 and 4?** Yes — PR #18's rejections were v1/short-form-context-only. Option 4 stays rejected; Option 3 is deferred to v2 (pending Bluesky's renderer); Option 5 is selected for v1. (Resolved in brainstorm.)
-   **Is this a decision doc or an implementation doc?** Both — an exploration spec that now recommends Option 5 as v1 after the Jim Ray call reframed the time horizon. (Resolved via user's "B" call + the 2026-04-23 Bluesky devrel call.)
-   **"Clearly better" criteria.** Resolved on the RFC thread: the native-feeling thread shape (hook + takeaway + CTA) is preferred over today's link card and over Option 2's truncate-and-link based on in-feed legibility, consistency with the short-form path we shipped, and community engagement data on short threads. Driving WP traffic stays available via `'link-card'` opt-in.
-   **Is `'teaser-thread'` worth the upstream cost when Option 3 is the long-term target?** Yes. The Jim Ray call made clear Option 3's enabling renderer is months out and multi-iteration — a short bridge with Option 2 isn't what we're shipping; a long bridge is. Option 5 is worth the investment.
-   **Hook-post cut semantics.** Sentence boundary ≤ 280 graphemes for the final prose cut before the CTA (v1's post 1). Fall back to word boundary if no sentence break exists in the window; mid-word is a last resort. In a future 3+-post variant, intermediate body-to-body cuts (post 1 → post 2 in a 3-post thread) can be word boundary with mid-word permitted — the sentence-boundary rule only applies to the **last body post before the CTA**. — applied (Kraft confirmed 2026-04-23).
-   **Upstream default for `atmosphere_long_form_composition`** stays `'link-card'`. FOSSE's opinion (thread default) is carried entirely by the FOSSE projector. If the Atmosphere plugin team ever wants to change the upstream default, that's their call; we don't push it on behalf of FOSSE. — applied (Kraft confirmed 2026-04-23).
-   **User-set excerpt as the hook source.** When `$post->post_excerpt` is non-empty, `build_teaser_thread()` uses it as the hook (user-curated wins over machine-truncated body prefix). Still clamped to 300 graphemes as a safety floor. — applied (Kraft confirmed 2026-04-23).
-   **Empty / whitespace-only body + no excerpt.** The post falls back to `'link-card'` composition for that post only; site-wide option unchanged; an error-log / notice records the fallback so ops can distinguish it from intentional `'link-card'` configuration. Threshold: < 10 graphemes of rendered plain text. — applied (Kraft confirmed 2026-04-23).
-   **`atmosphere_transform_bsky_post` fires per thread record**, not once per WP post. Consistent treatment across the thread; existing consumers who assumed single-post semantics can branch on record shape if they need different behavior. Behavior change documented in upstream changelog. — applied (Kraft confirmed 2026-04-23).
-   **CTA copy.** `sprintf( __( 'Continue reading: %s', 'atmosphere' ), $permalink )`. Translator-aware from day one. — applied (confirm).
-   **Borderline post (body fits in ≤ 300 graphemes).** Still write the 2-post thread per the strategy; no automatic short-circuit to `'truncate-link'`. Users who want single-post can opt in via the option. — applied (confirm).
-   **Rollback observability.** `error_log` + `WP_Error` return from `Publisher::publish()`. No admin UI surface in v1. Partial-meta writes after each successful create are the crash-recovery anchor. — applied (confirm).
-   **Legacy expectations when flipping the default.** `readme.txt` changelog entry only; FOSSE is pre-1.0 and the scope of users who've pinned `fosse_long_form_strategy` intentionally is effectively zero. No admin notice, no deprecation window. — applied (confirm).
-   **Post-meta shape (`{uri, cid, tid}` triples vs parallel arrays).** Triples in a single `_atmosphere_bsky_thread_records` array. Parallel arrays can drift; triples stay self-consistent per element and storing CID now enables future `swapRecord`-based in-place updates without a meta migration. The backwards-compat argument for parallel arrays is weak (the keys are new; legacy posts use the preserved single-value keys). — applied (confirm).
-   **Crash-recovery between last successful write and meta persist.** Write partial-meta after each successful create rather than once at the end. Cheap; eliminates the orphan-records window where PHP fatals leave posts on Bluesky that WordPress doesn't know about. — applied (confirm).
-   **Linear issue split under DOTCOM-16810.** Keep as an umbrella; no per-task children for v1. Mirrors how DOTCOM-16795 was split into DOTCOM-16838/9/40 only once the upstream-vs-FOSSE split made that useful. — applied (confirm).
-   **Open Task 1 as a draft PR early.** Yes — the upstream review window is the more likely execution stall than code volume. Draft PR opens as the first step of Task 1 so async review runs in parallel with FOSSE-side work. — applied (confirm).
