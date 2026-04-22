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

**Option 2 (truncate + link) as the new default**, with Option 1 (link card) preserved as an opt-in alternative via the selector. **Option 3** (document card) becomes the default once Bluesky ships `site.standard.*` rendering. **Option 5** (teaser mini-thread) is a credible follow-up when/if upstream adds multi-post writes. **Option 4** (tweet-storm thread) is rejected outright.

Reasoning:

-   **Option 2 wins the "cost per improvement" ratio clearly.** It's a new text-composition method in the Atmosphere transformer plus one new upstream filter — no restructuring of `Publisher::publish()`, no multi-post writes, no dependency on Bluesky client features. Compared to today's link card, the bsky post looks and feels like a post (body-as-text with an explicit link), consistent with the short-form path we just shipped.
-   **Option 3 is the obvious long-term target** if Bluesky's `site.standard.*` renderer arrives. It's what [standard.site](https://standard.site) was designed for. But today it renders as a "record not found" card in the Bluesky app, which is strictly worse than Option 1. We shouldn't adopt it until the renderer ships.
-   **Option 5 would likely beat Option 2 on engagement** (community data says threads of 3-8 posts get ~3× engagement). But it requires upstream redesign of `Atmosphere\Publisher::publish()` which today writes exactly one bsky post per WP post. The write-atomicity, TID-tracking, and edit/delete semantics all change. Worth pursuing later — too expensive to gate v1 on it.
-   **Option 4 is a nope.** Full tweet storms read as spam on Bluesky. The upstream cost is the same as Option 5 with worse UX.
-   **Option 1 stays available** because some users genuinely prefer the link card for driving WP traffic. The selector gives them opt-out.

This is my opinion stated as a starting point for discussion, not a locked decision. The spec is deliberately structured so the recommendation can be overridden at review without gutting the architecture — swapping the default value of `fosse_long_form_strategy` is a one-line change.

## Option Analysis

### Option 1 — Link card (today's behavior)

**Composition**: `{title}\n\n{excerpt}\n\n{permalink}` truncated to 300, plus `app.bsky.embed.external` card with title, description, thumbnail.

| | |
|---|---|
| **Pros** | Ships today; zero engineering cost; thumbnail is valuable for scroll-stopping; driver for WP traffic. |
| **Cons** | Reader must leave Bluesky to read. Link cards have lower in-feed engagement than body-text posts per Bluesky community data. Feels like "Jetpack Social with extra steps" — the thing the parent epic wanted to move past. |
| **Engineering** | None. Preserve as a selectable option. |
| **Upstream work** | None. |

### Option 2 — Truncate + link *(recommended for v1 default)*

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

### Option 5 — Teaser mini-thread *(credible follow-up)*

**Composition**: 2-3 `app.bsky.feed.post` records — hook, key takeaway, CTA-with-link — connected by `reply` refs.

| | |
|---|---|
| **Pros** | Threads of 3-8 posts reportedly get ~3× engagement vs single posts on Bluesky (per [community growth research](https://blog.bskygrowth.com/best-bluesky-growth-strategies-creators-2026/)). Feels native to the platform. The final CTA post converts readers who want more. Keeps readers in-feed longer before the click-through moment. |
| **Cons** | Big upstream change: `Publisher::publish()` today writes 1 bsky post + 1 doc record atomically. Thread shape means N bsky posts + 1 doc. TID storage, URI resolution, delete/update semantics, and atomic-write guarantees all need rethinking. Composition is open — what goes in each post? First N words for hook? Post excerpt? Auto-generated teaser? |
| **Engineering** | Significant upstream work + rethink of Publisher's write shape. FOSSE side would just project the option. |
| **Upstream work** | Large. Needs Matthias / upstream buy-in before FOSSE design can land. |
| **When to pursue** | After v1 ships and if team wants to invest in the upstream restructure. Don't block v1 on this. |

## Technical Details

This section spec's the v1 implementation assuming the recommended default (Option 2) holds through review. Option 1 opt-out is part of the same surface area.

### Architecture

Mirrors the `fosse_object_type` pattern. One site-wide option, one FOSSE projector, one upstream filter. Composition changes live entirely in Atmosphere.

```
+----------------------------------------+
|  Automattic/wordpress-atmosphere       |
|                                        |
|  Post::transform()                     |
|    $is_short = apply_filters(          |
|      'atmosphere_is_short_form_post',  |
|      ... )                             |
|    if ($is_short) { /* short-form */ } |
|    else {                              |
|      $strategy = apply_filters(        |
|        'atmosphere_long_form_          |
|         composition',                  |
|         'link-card',                   |
|         $post )                        |
|      if ($strategy === 'truncate-link')|
|        $text = build_truncate_link_    |
|                text()                  |
|        $embed = null                   |
|      else                              |
|        $text = build_text()            |
|        $embed = build_embed()          |
|    }                                   |
+----------------+-----------------------+
                 │
                 │ filter override
                 ▼
+----------------------------------------+
|  Automattic/fosse                      |
|                                        |
|  Long_Form_Strategy::register()        |
|    hooks atmosphere_long_form_         |
|    composition                         |
|                                        |
|  reads get_option(                     |
|    'fosse_long_form_strategy' )        |
+----------------------------------------+
```

`fosse_long_form_strategy` accepted values:

| Value | Effect | Status |
|-------|--------|--------|
| `'truncate-link'` (default) | Atmosphere takes the new truncate-link branch. | v1 |
| `'link-card'` | Explicit opt-in to today's behavior. | v1 |
| `'document-card'` | Atmosphere takes the document-card branch. | v2 (when Bluesky renderer ships) |
| unset | Same as default (`'truncate-link'`). | |

The enum is extensible — future values (e.g. `'teaser-thread'`) fit without changing the filter shape.

### Data Flow

**FOSSE option unset or `'truncate-link'`** (default on upgrade):

1. User publishes a 2000-word post (titled, no post format).
2. AP transformer runs. `get_type()` returns `'Article'`. Filter pass-through. AP federates as Article. (Unchanged from today.)
3. Atmosphere transformer runs. `is_short_form()` returns `false`. Long-form branch. Applies `atmosphere_long_form_composition` filter → FOSSE callback returns `'truncate-link'`. Atmosphere takes the truncate-link branch: body-text truncated to ~280 graphemes, permalink appended, no embed card. `Facet::extract()` turns the permalink into a link facet.
4. `site.standard.document` record written with full content as it always has been.

**FOSSE option set to `'link-card'`**:

1. Same as above through step 2.
2. Atmosphere long-form branch. Filter callback returns `'link-card'`. Atmosphere takes the existing long-form branch unchanged (byte-identical to today's default).

### Key Components

| Component | Repo | Change |
|---|---|---|
| `Atmosphere\Transformer\Post::build_truncate_link_text()` | upstream Atmosphere (new) | Renders post body to plain text via the shared helper from DOTCOM-16838, truncates to a budget that reserves space for the permalink plus whitespace, appends `\n\n{permalink}`. |
| `Atmosphere\Transformer\Post::transform()` | upstream Atmosphere | Long-form branch becomes `switch ($strategy)` on the filtered value. Default and `'link-card'` both dispatch to the existing `build_text()` + `build_embed()` path. `'truncate-link'` dispatches to the new method with `embed = null`. |
| `atmosphere_long_form_composition` filter | upstream Atmosphere (new) | Returns a strategy enum string. Default `'link-card'` so existing users see no change when upstream merges standalone. |
| `Automattic\Fosse\Long_Form_Strategy` | FOSSE (new) | Static `register()` on `init`. One filter callback projecting `fosse_long_form_strategy` onto `atmosphere_long_form_composition`. Mirror of `Automattic\Fosse\Object_Type`. |
| `fosse_long_form_strategy` option | FOSSE (new) | Default `'truncate-link'`. Set via `wp-cli option set` for now (UI is out of scope). |

### File Changes

| File | Change Type | Description | Repo |
|------|-------------|-------------|------|
| `includes/transformer/class-post.php` | modify | Add `build_truncate_link_text()`; wrap long-form branch in filter + switch. | `Automattic/wordpress-atmosphere` (upstream PR) |
| `tests/phpunit/tests/transformer/class-test-post.php` | modify | New tests: default is link-card, `'truncate-link'` filter produces body-as-text + permalink, `'link-card'` filter byte-identical to default, unknown value falls back to link-card. | `Automattic/wordpress-atmosphere` |
| `readme.txt` | modify | Changelog entry for the new filter. | `Automattic/wordpress-atmosphere` |
| `src/class-long-form-strategy.php` | new | `Automattic\Fosse\Long_Form_Strategy` projector class. | `Automattic/fosse` |
| `tests/php/Long_Form_StrategyTest.php` | new | PHPUnit coverage mirroring `Object_TypeTest.php`. | `Automattic/fosse` |
| `fosse.php` | modify | `add_action( 'init', [...] )` for the new projector. | `Automattic/fosse` |
| `bundled/atmosphere/**` | regenerated | `tools/sync-bundled.sh` after upstream PR merges. | `Automattic/fosse` |
| `tests/e2e/long-form-truncate.spec.ts` | new | Playwright e2e: publish a long titled post, verify the captured `app.bsky.feed.post` record has truncated body + trailing permalink + link facet + no `embed`. | `Automattic/fosse` |

### Upgrade Path to Option 3 (document card)

When Bluesky's `site.standard.*` renderer ships and is verified to degrade acceptably on non-supporting clients, a follow-up PR:

1. Adds `build_document_card_text()` + matching `'document-card'` branch in Atmosphere.
2. Extends the filter's accepted values.
3. Flips the `fosse_long_form_strategy` default from `'truncate-link'` to `'document-card'`.

No breaking change for users on `'link-card'`. Users on `'truncate-link'` get the upgrade.

## Out of Scope

-   Per-post strategy override UI — deferred to composer epic (DOTCOM-16794) or a follow-up.
-   Bluesky's own `site.standard.*` renderer support (tracked [DOTCOM-16827](https://linear.app/a8c/issue/DOTCOM-16827)).
-   Reader-side discovery of alt clients / other AT lexicons ([DOTCOM-16859](https://linear.app/a8c/issue/DOTCOM-16859)).
-   Thread-shape (Options 4 and 5) — Option 4 rejected, Option 5 deferred pending upstream redesign of `Publisher::publish()`'s write atomicity.
-   Changes to AP's long-form `Article` shape on Mastodon.

## Open Questions (held for team discussion)

These questions stay open in the spec. They're the reason this SDD exists as a discussion artifact — the P2 post that accompanies this spec is the forum to close them.

1.  **"Clearly better" criteria.** Before we commit to flipping the default from `'link-card'` to `'truncate-link'`, the a8c teams working on ActivityPub + Atmosphere internally should agree on what "clearly better" means. Candidates: in-feed legibility, click-through rate, Bluesky algorithmic reach, consistency with short-form. Probably a combination. Possible outcome: criteria might flip the recommendation entirely (e.g. if "drive WP traffic" is the top criterion, Option 1 stays default).
2.  **`'truncate-link'` composition edge cases.**
    -   Where do we cut? Fixed grapheme count, or cut at a sentence/paragraph boundary before the cap?
    -   Do we include a bridge phrase (e.g. "Read more:") or just whitespace before the permalink?
    -   What if the title itself is long enough that even a very short body teaser would exceed 300? Do we fall back to link-card in that case, or keep cutting?
3.  **Option 3's today-cost.** Does Option 3 require any FOSSE-side code right now that Option 2 doesn't, or is the difference purely in `build_document_card_text()` upstream? If no FOSSE difference, we could ship both strategies in v1 with `'truncate-link'` as default and let power users try `'document-card'` opt-in. (Probably not — the UX hit on non-rendering clients is too big to put behind an option without warnings.)
4.  **Borderline posts.** A titled post with no post format whose body happens to fit in 300 graphemes: should the long-form strategy still apply, or should it auto-native? DOTCOM-16795 leaves these as long-form. Probably the v1 answer is "whatever the strategy says" with no automatic short-circuit, but worth confirming.
5.  **Legacy expectations.** Some users actively prefer today's link-card behavior (driving WP traffic is the point of publishing). Flipping the default is a visible change. Is a CHANGELOG.md / readme.txt callout enough, or do we need a release-note-level comms / admin notice / deprecation window?

## Open Questions Resolved

-   **Scope of selection model**: site-wide option, no per-post override in v1. (Resolved in brainstorm.)
-   **Should we re-open PR #18's rejection of Options 3 and 4?** Yes — PR #18's rejections were v1/short-form-context-only. Options 3 and 4 get fresh analysis here. Option 4 is still rejected; Option 3 is deferred not rejected. (Resolved in brainstorm.)
-   **Is this a decision doc or an implementation doc?** Both — an exploration spec that recommends Option 2 as v1 with explicit holes for team discussion. The spec's architecture is structured so a different winner at review can slot in without restructuring. (Resolved via user's "B" call.)
