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

**Upstream cost we're taking on.** `Atmosphere\Publisher::publish()` today writes exactly one `app.bsky.feed.post` + one `site.standard.document` atomically via a single `applyWrites` call. Option 5 requires N bsky posts with reply refs. Those reply refs need strong refs (URI + CID), and CIDs are content-addressed — you'd need client-side CID computation to make a single `applyWrites` truly atomic. The pragmatic alternative is sequential writes with rollback-on-failure, which is how Bluesky's own clients post threads. The spec picks sequential-writes-with-rollback; details in "Technical Details — Thread write semantics."

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

-   **Post 1 (root — the hook):** first ~280 graphemes of the post body rendered to plain text, truncated at a word/sentence boundary. No title, no permalink in this post. The body should stand on its own as a scroll-stopper.
-   **Post 2 (CTA):** `Continue reading: {permalink}` with the permalink as a link facet. Reply refs point at post 1 (`root` and `parent` both reference the hook).

**3-post variant** (future, opt-in via filter): insert a middle "takeaway" post between hook and CTA. Content source is an open question (excerpt second half? body continuation? auto-generated summary?). v1 defaults to 2; `atmosphere_teaser_thread_posts` filter allows downstream to return 3.

| | |
|---|---|
| **Pros** | Threads of 3-8 posts reportedly get ~3× engagement vs single posts on Bluesky (per [community growth research](https://blog.bskygrowth.com/best-bluesky-growth-strategies-creators-2026/)). Feels native to the platform. The final CTA post still carries the permalink for click-through. Matches the body-as-text native feel of the short-form path we shipped. |
| **Cons** | Big upstream change: `Publisher::publish()` today writes 1 bsky post + 1 doc record atomically via `applyWrites`. Thread shape means N bsky posts with reply refs. Reply refs require `strongRef {uri, cid}` — CIDs are content-addressed, so client-side CID computation is required for a true single-`applyWrites` atomic write. v1 takes the pragmatic route: sequential writes with rollback-on-failure (match Bluesky's own client behavior). Post meta storage becomes an ordered array of URIs/TIDs. Edit/delete semantics: rewrite the whole thread on update, delete all N on delete. |
| **Engineering** | Significant upstream work. FOSSE side is just the projector. |
| **Upstream work** | Large but scoped: sequential-writes-with-rollback inside `Publisher::publish/update/delete`, array-shape post meta (`_atmosphere_bsky_thread_uris`, `_atmosphere_bsky_thread_tids`), new composition method `build_teaser_thread()` returning an array of N record-payloads, the `atmosphere_long_form_composition` filter, and the `atmosphere_teaser_thread_posts` filter. Requires Matthias / upstream buy-in. |
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
|    $strategy = apply_filters(                |
|      'atmosphere_long_form_composition',     |
|      'link-card',                            |
|      $post )                                 |
|                                              |
|    switch ($strategy)                        |
|      case 'teaser-thread':                   |
|        $posts = Post::build_teaser_thread()  |
|        $doc   = Document::transform()        |
|        sequential write:                     |
|          1) applyWrites: root + doc (atomic) |
|          2) for each reply in $posts[1..]:   |
|               applyWrites: reply (refs prev) |
|          on any failure: rollback prior      |
|        store array of URIs/TIDs in meta      |
|      case 'truncate-link':                   |
|        single-post path (same shape as today)|
|      case 'link-card' (default):             |
|        single-post path (byte-identical to   |
|          today's behavior)                   |
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
|    'fosse_long_form_strategy' )              |
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

Reply refs on `app.bsky.feed.post` require `strongRef {uri, cid}`. CIDs are content-addressed hashes of the record, so to produce them client-side requires DAG-CBOR encoding + SHA-256 + base32 of the full record. That's a non-trivial dependency to add to Atmosphere. v1 skips it and does sequential writes, matching Bluesky's own client behavior.

**Publisher::publish() for the thread path:**

1. Build the thread records locally — an ordered array of `$thread` records where `$thread[0]` is the root (no `reply`) and `$thread[1..N-1]` each set `reply.root` and `reply.parent`. The root and parent refs for each reply get filled in as the write progresses (see step 3); at build time they're placeholders.
2. First `applyWrites` call (atomic): create the root `app.bsky.feed.post` + the `site.standard.document` record. This mirrors today's atomic write. Response gives us the root post's URI + CID.
3. For each subsequent `$thread[i]`: fill in `reply.root` with the root's `{uri, cid}` and `reply.parent` with the previous post's `{uri, cid}`. Call `applyWrites` (single-record create). On success, record URI + CID. On failure: delete all previously-created records in reverse order (rollback) and return `WP_Error`.
4. Persist ordered arrays of URIs and TIDs in post meta:
    - `_atmosphere_bsky_thread_uris` — ordered array of `at://.../app.bsky.feed.post/TID`.
    - `_atmosphere_bsky_thread_tids` — ordered array of TIDs.
    - Existing single-value `_atmosphere_bsky_uri` / `_atmosphere_bsky_tid` are retained for backwards compatibility, pointing at `$thread[0]` (root). Callers using the old keys get the root post URI.

**Publisher::update():** rewrite the whole thread. Delete all existing posts in the thread (via `applyWrites#delete`), then re-publish using the new thread records. Doc record updates in place. This is the simplest correct behavior — preserving reply refs across edits with content that changes length is hard, and editing in place would leave orphan replies when the new thread is shorter. Document the limitation in the changelog.

**Publisher::delete():** delete all N thread posts + the doc record. Read the ordered TIDs array; issue `applyWrites` with N+1 deletes.

**Rollback semantics for partial failure:** sequential writes can fail midway. Document which posts succeeded in `_atmosphere_bsky_thread_uris` after each success. If a subsequent write fails, iterate the array in reverse and issue deletes. If rollback itself fails (network blip), surface a `WP_Error` noting the partial state; the admin's recourse is to republish, which will rewrite the thread. The post meta is the source of truth for "what's out there."

### Composition (2-post default)

Post 1 (root — the hook): first ~280 graphemes of `$post->post_content` rendered to plain text via the shared `Transformer\Base::render_post_content_plain()` helper (from DOTCOM-16838), truncated at a word or sentence boundary. No title prefix, no permalink in this post. Facets extracted over the text.

Post 2 (CTA): string in the form `Continue reading: {permalink}` (translator-aware via `__()`). Permalink is a link facet. Reply refs: `root` and `parent` both point at post 1.

The composition is filterable via `atmosphere_teaser_thread_posts` — filter callback receives the default array of post-text strings + the `$post` object and returns the array to write. Returning a 3-entry array produces a 3-post thread. Returning a 1-entry array falls back to single-post behavior — this is an escape hatch, not a common path.

### Data Flow

**FOSSE option unset or `'teaser-thread'`** (default on upgrade):

1. User publishes a 2000-word post (titled, no post format).
2. AP transformer runs. `get_type()` returns `'Article'`. Filter pass-through. AP federates as Article. (Unchanged from today.)
3. Atmosphere `Publisher::publish()` runs. Applies `atmosphere_long_form_composition` filter → FOSSE callback returns `'teaser-thread'`. `Post::build_teaser_thread()` returns the 2-post default.
4. First `applyWrites`: root post + `site.standard.document`. Atomic. Response stores root URI/CID.
5. Second `applyWrites`: CTA post with `reply.root` and `reply.parent` pointing at root. Response stores CTA URI/CID.
6. Post meta updated: `_atmosphere_bsky_thread_uris = [root_uri, cta_uri]`, `_atmosphere_bsky_thread_tids = [root_tid, cta_tid]`. `_atmosphere_bsky_uri` and `_atmosphere_bsky_tid` mirror the root (backwards-compat).

**FOSSE option set to `'truncate-link'`:** single `applyWrites` (root bsky post + doc record atomic). `_atmosphere_bsky_thread_uris` is a 1-element array; `_atmosphere_bsky_uri` matches. No reply refs.

**FOSSE option set to `'link-card'`:** byte-identical to today's default behavior. Single post with title + excerpt + external embed card. Same meta shape as `'truncate-link'`.

### Key Components

| Component | Repo | Change |
|---|---|---|
| `Atmosphere\Publisher::publish()` | upstream Atmosphere | Switch on the filtered strategy. For `'teaser-thread'`, build the thread via `Post::build_teaser_thread()` and execute the sequential-writes-with-rollback flow. For `'truncate-link'` and `'link-card'`, the existing single-post write path runs (with the appropriate text composition). |
| `Atmosphere\Publisher::update()` | upstream Atmosphere | For threads, delete-all then re-publish. For single-post strategies, existing update path stays. |
| `Atmosphere\Publisher::delete()` | upstream Atmosphere | Iterate `_atmosphere_bsky_thread_tids` (falling back to the single-value meta for already-published posts from before this change) and issue deletes for all. |
| `Atmosphere\Transformer\Post::build_teaser_thread()` | upstream Atmosphere (new) | Returns an ordered array of post-record payloads (text + facets, no reply refs yet — those get filled by Publisher during the sequential write). Default 2 entries (hook + CTA), filterable via `atmosphere_teaser_thread_posts`. |
| `Atmosphere\Transformer\Post::build_truncate_link_text()` | upstream Atmosphere (new) | Body rendered to plain text via the shared helper, truncated to reserve space for the permalink + whitespace, appended with `\n\n{permalink}`. |
| `Atmosphere\Transformer\Post::transform()` | upstream Atmosphere | Single-post path (used for `'link-card'` and `'truncate-link'`) switches on the filtered composition. Unchanged for the `'link-card'` branch. |
| `atmosphere_long_form_composition` filter | upstream Atmosphere (new) | Returns the strategy enum. Default `'link-card'` so existing users see no change when upstream merges standalone. |
| `atmosphere_teaser_thread_posts` filter | upstream Atmosphere (new) | Returns an ordered array of post-text strings. Default is the 2-post hook+CTA composition. |
| `_atmosphere_bsky_thread_uris`, `_atmosphere_bsky_thread_tids` | upstream Atmosphere (new post meta) | Ordered arrays. Always present; single-element for non-thread strategies. |
| `Automattic\Fosse\Long_Form_Strategy` | FOSSE (new) | Static `register()` on `init`. One filter callback projecting `fosse_long_form_strategy` onto `atmosphere_long_form_composition`. Mirror of `Automattic\Fosse\Object_Type`. |
| `fosse_long_form_strategy` option | FOSSE (new) | Default `'teaser-thread'`. Set via `wp-cli option set` for now (UI is out of scope). |

### File Changes

| File | Change Type | Description | Repo |
|------|-------------|-------------|------|
| `includes/class-publisher.php` | modify | Switch-on-strategy in `publish/update/delete`; sequential-writes-with-rollback for threads; ordered-array meta storage. | `Automattic/wordpress-atmosphere` (upstream PR A — Publisher) |
| `includes/transformer/class-post.php` | modify | Add `build_teaser_thread()`, `build_truncate_link_text()`; keep existing long-form `build_text()` + `build_embed()` as the `'link-card'` path. | `Automattic/wordpress-atmosphere` (upstream PR B — transformer) |
| `tests/phpunit/tests/transformer/class-test-post.php` | modify | Tests: thread default is 2 entries; `atmosphere_teaser_thread_posts` filter extends to 3; truncate-link composition is body + permalink; link-card byte-identical to today. | `Automattic/wordpress-atmosphere` |
| `tests/phpunit/tests/class-test-publisher.php` | new | Tests: 2-post thread publish stores ordered meta; rollback on simulated second-write failure; delete iterates thread TIDs; update rewrites thread. | `Automattic/wordpress-atmosphere` |
| `readme.txt` | modify | Changelog entries for the new filters, meta keys, and default-behavior change. | `Automattic/wordpress-atmosphere` |
| `src/class-long-form-strategy.php` | new | `Automattic\Fosse\Long_Form_Strategy` projector class. | `Automattic/fosse` |
| `tests/php/Long_Form_StrategyTest.php` | new | PHPUnit coverage mirroring `Object_TypeTest.php`: pass-through default, each enum value projects correctly, unknown values pass through. | `Automattic/fosse` |
| `fosse.php` | modify | `add_action( 'init', [ '\Automattic\Fosse\Long_Form_Strategy', 'register' ] )` alongside the existing Object_Type registration. | `Automattic/fosse` |
| `bundled/atmosphere/**` | regenerated | `tools/sync-bundled.sh` after upstream PRs A and B merge. | `Automattic/fosse` |
| `tests/e2e/long-form-teaser-thread.spec.ts` | new | Playwright e2e: publish a long titled post under `'teaser-thread'`; verify the mu-plugin captured two `app.bsky.feed.post` records with correct reply refs, body-as-text hook, and `Continue reading:` CTA with link facet. | `Automattic/fosse` |
| `tests/e2e/mu-plugins/fosse-bsky-capture.php` | modify | Extend the existing capture helper to record *all* `applyWrites` calls per request, not just the first (required for multi-call thread writes). | `Automattic/fosse` |

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

1.  **Thread composition details (v1 default).**
    -   Where do we cut the hook post's body? Fixed grapheme count, or cut at a sentence/paragraph boundary before the cap?
    -   What's the CTA copy? `Continue reading:` is the default — do we want something shorter, and do we want it translator-aware from day one?
    -   What happens if the body is too short for a meaningful split (e.g. the whole body fits in 280 graphemes)? Fall back to `'truncate-link'` shape (single post + permalink) automatically, or still write a 2-post thread with a short hook?
2.  **3-post variant.** Spec mentions a middle "takeaway" post as a future 3-post variant behind the `atmosphere_teaser_thread_posts` filter. What goes in the takeaway post by default — post excerpt second half, body continuation, auto-generated summary, or nothing (no default, filter-only)? v1 is 2-post; 3-post is a follow-up decision.
3.  **Rollback observability.** When sequential-write rollback fires (or fails), what signal does the admin get? Error in the post's publish queue / notice / log? Out of scope for v1 if we pick "log to error_log + return WP_Error"; worth naming the escalation path.
4.  **Borderline posts.** Titled post with no post format whose body happens to fit in 300 graphemes: long-form strategy still applies, per DOTCOM-16795. v1 answer is "whatever the strategy says" with no automatic short-circuit, confirmed.
5.  **Legacy expectations.** Flipping the default from `'link-card'` to `'teaser-thread'` is a visible change for existing Atmosphere users. Is a CHANGELOG.md / readme.txt callout enough, or do we need a release-note-level comms / admin notice / deprecation window? Decision needed at upstream merge.
6.  **Atomic-write upgrade path.** v1 takes sequential-writes-with-rollback. If Bluesky (or an a8c effort) ships a thread-create API that's atomic, or we decide to implement client-side CID computation, how does the write path change? Not a v1 blocker; capture as future work so the thread-write code is kept narrow enough to swap.

## Open Questions Resolved

-   **Scope of selection model**: site-wide option, no per-post override in v1. (Resolved in brainstorm.)
-   **Should we re-open PR #18's rejection of Options 3 and 4?** Yes — PR #18's rejections were v1/short-form-context-only. Options 3 and 4 got fresh analysis here. Option 4 is still rejected; Option 3 is deferred to v2 (pending Bluesky's renderer); Option 5 is selected for v1. (Resolved in brainstorm.)
-   **Is this a decision doc or an implementation doc?** Both — an exploration spec that now recommends Option 5 as v1 after the Jim Ray call reframed the time horizon. (Resolved via user's "B" call + the 2026-04-23 Bluesky devrel call.)
-   **"Clearly better" criteria.** Resolved on the RFC thread: the native-feeling thread shape (hook + takeaway + CTA) is preferred over today's link card and over Option 2's truncate-and-link based on in-feed legibility, consistency with the short-form path we shipped, and community engagement data on short threads. Driving WP traffic stays available via `'link-card'` opt-in.
-   **Is `'teaser-thread'` worth the upstream cost when Option 3 is the long-term target?** Yes. The Jim Ray call made clear Option 3's enabling renderer is months out and multi-iteration — a short bridge with Option 2 isn't what we're shipping; a long bridge is. Option 5 is worth the investment.
