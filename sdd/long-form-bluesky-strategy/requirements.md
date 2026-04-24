# Long-Form Bluesky Strategy — Requirements

## Goal

Decide how FOSSE publishes WordPress posts to Bluesky when the post body doesn't fit in a single `app.bsky.feed.post` (Bluesky's ~300-grapheme limit). Today Atmosphere defaults to a link-card teaser that directs readers back to the WordPress site. Long-form is the half of the [DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795) epic that [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810) punted on — we ship short-form first, then come back here with fresh eyes for long-form.

## Pre-Requisite Assumption

**This SDD assumes [Automattic/fosse#18](https://github.com/Automattic/fosse/pull/18) lands as-is.** That PR's SDD at `sdd/bluesky-native-publishing/` defines the short-form path and the conventions this SDD builds on:

-   Atmosphere's `atmosphere_is_short_form_post` filter and ActivityPub's `activitypub_post_object_type` filter exist upstream.
-   FOSSE's `Automattic\Fosse\Object_Type` projector + `fosse_object_type` option drive short/long selection across both backends in lockstep.
-   Upstream-first policy in `AGENTS.md`: post-type-agnostic behavior goes to `wordpress-atmosphere` / `wordpress-activitypub`; FOSSE-shape-specific projection stays in FOSSE.
-   `site.standard.document` is always written by `Atmosphere\Publisher::publish()`, regardless of short/long.

If PR #18's architecture changes materially before merge, the "mirror `fosse_object_type`'s pattern" assumption below needs to be revisited.

## Candidate Options

Five options under fresh evaluation. None are pre-selected; the spec decides.

| # | Option | Summary |
|---|--------|---------|
| 1 | Link card | Today's behavior: title + excerpt + permalink + external embed card (`app.bsky.embed.external`) with thumbnail. Reader clicks through to read. |
| 2 | Truncate + link | Post content truncated to 300 graphemes with an in-text link facet to the WP permalink. No external embed card. |
| 3 | `site.standard.document` record embed | Short `app.bsky.feed.post` with `app.bsky.embed.record` pointing at the already-written `site.standard.document`. Bluesky's app doesn't render this as rich content yet; Leaflet / Pckt.blog / Offprint.app do. |
| 4 | Tweet-storm thread | Full content split across N `app.bsky.feed.post` records, connected by `reply` refs. |
| 5 | Teaser mini-thread | 2-3 `app.bsky.feed.post` records (hook, key takeaway, call-to-action with link to WP). Hybrid of 4 and 1/2. |

See `sdd/bluesky-native-publishing/spec.md` Alternatives section (PR #18) for the prior positions on options 3 and 4 — **not binding here**. We explicitly re-open those calls with fresh criteria.

## Requirements

1. **Re-evaluate all five options fresh** — don't treat PR #18's v1-only rejections of options 3 and 4 as binding for the long-form context.
2. **Pick a recommended default** in the spec. Existing Atmosphere users accept the new default on upgrade if the chosen option is "clearly better" than today's link card (see Open Questions — the criteria itself is unresolved).
3. **MVP ships as a single site-wide option** — e.g. `fosse_long_form_strategy`, mirroring `fosse_object_type`'s shape. One value per site. No per-post override in this epic.
4. **Per-post override is future work**, deferred to the composer epic ([DOTCOM-16794](https://linear.app/a8c/issue/DOTCOM-16794)) or a dedicated follow-up. The spec may mention hook points that make future per-post overrides possible, but doesn't ship that UI.
5. **Upstream-first.** Anything post-type-agnostic (any consumer of `wordpress-atmosphere` would want it) lands in that repo as a PR; FOSSE consumes via `tools/sync-bundled.sh`. FOSSE-side code is limited to projection: reading the site option and driving whatever upstream filter(s) the chosen strategy needs. No composition/discrimination logic of its own.
6. **Facet parity must hold** on whichever option is chosen — hashtags, `@handle.tld` mentions, and URLs in the bsky text all get correct facets. This mirrors the requirement from DOTCOM-16811 that we just verified for the short-form path.
7. **`site.standard.document` writes stay unchanged.** The doc record is the persistent full-content artifact regardless of strategy. Whatever Bluesky renders in-feed is a presentation layer over the doc.

## Constraints

-   No forced short-form regression. The short-form path from DOTCOM-16795 stays as it is — this SDD's scope is strictly long-form.
-   Must work without external Bluesky client changes. Option 3 (document card) can't depend on Bluesky's renderer shipping.
-   ~300-grapheme per-post Bluesky limit is the operating constraint.
-   No new UI surface in this epic (admin settings page, block-editor meta box, etc.). Options set via `wp-cli option set` or direct code for now.
-   Follow the Atmosphere transformer pattern: prefer new upstream filter(s) over a FOSSE monkey-patch of `Publisher::publish()` or `Post::transform()`.
-   Structural change warning: options 4 and 5 require writing N `app.bsky.feed.post` records per 1 WP post. `Atmosphere\Publisher::publish()` today writes exactly one bsky post per WP post atomically with the doc. Multi-post threads are a non-trivial structural change that needs upstream design.

## Out of Scope

-   Per-post strategy override UI (composer epic or follow-up).
-   Bluesky's own `site.standard.*` renderer support — external dependency, tracked in [DOTCOM-16827](https://linear.app/a8c/issue/DOTCOM-16827).
-   Reader-side discovery of AT clients that render standard.site content (Leaflet, Pckt.blog, Offprint.app, etc.) — moved to [DOTCOM-16859](https://linear.app/a8c/issue/DOTCOM-16859).
-   Writing additional AT Protocol lexicons (Rocksky, Flashes, White Wind, etc.) — also [DOTCOM-16859](https://linear.app/a8c/issue/DOTCOM-16859).
-   Changes to AP's long-form `Article` shape on Mastodon. Long-form for AP already works; this epic is about Bluesky.
-   Revisiting the `fosse_object_type` enum. The new long-form option is a sibling, not a replacement.

## Open Questions

These stay unresolved in requirements and get picked up in spec / team review:

-   **"Clearly better" criteria.** Before the spec can confidently recommend a new default over today's link card, the a8c teams working on ActivityPub + Atmosphere internally should agree on what metrics or qualitative signals define "clearly better." Candidates: legibility in-feed without click-through; click-through rate to the WP site; Bluesky algorithmic reach (reposts, replies, Discover-feed inclusion); consistency with short-form's body-as-text feel. Probably some combination. Treat as a conversation to have before finalizing the spec's recommended default.
-   **Thread-shape upstream design.** If options 4 or 5 win, `Atmosphere\Publisher::publish()` needs to write multiple `app.bsky.feed.post` records with `reply` refs between them. That's an upstream change to the write shape and persistence (which record gets `_atmosphere_bsky_tid` / `_atmosphere_bsky_uri` post meta? All of them? The root only?). Needs Matthias / upstream buy-in before FOSSE-side design makes sense.
-   **Option 3's today-cost.** Does option 3 require any FOSSE-side code right now, or is it purely a bet that Bluesky's renderer will arrive? If the bsky post is just a short teaser with an `app.bsky.embed.record` pointing at the already-written `site.standard.document`, that's a composition change in Atmosphere (upstream), not FOSSE code. Need to verify.
-   **Borderline posts.** Posts that have a title + no post format but whose body happens to fit in 300 graphemes. Under DOTCOM-16795 they stay long-form (link card today). Does the long-form strategy still apply, or do we short-circuit to a native post when the body fits? Spec call.
-   **Legacy users' expectations.** Some Atmosphere users may actively prefer today's link card (driving WP traffic is the point). If we flip the default, do we need a deprecation/warning period, or is a release-note callout enough?

## Related Code / Patterns Found

Paths assume PR #18 has landed; pre-merge they live on that branch.

-   `bundled/atmosphere/includes/transformer/class-post.php` — `Post::transform()` is where short/long branches. Long-form currently builds a title + excerpt + permalink teaser and attaches an `app.bsky.embed.external` card via `build_embed()`.
-   `bundled/atmosphere/includes/class-publisher.php` — `Publisher::publish()` atomically writes one `app.bsky.feed.post` + one `site.standard.document` per WP post via `API::apply_writes`. Multi-post thread strategies (options 4/5) would restructure this.
-   `bundled/atmosphere/includes/transformer/class-facet.php` — `Facet::extract()` works on any plain text; reusable on whatever composition we end up with.
-   `bundled/atmosphere/includes/transformer/class-document.php` — `Document::transform()` produces `site.standard.document`. Not changing in this SDD; included for reference on the Open Question about option 3's cost.
-   `src/class-object-type.php` (FOSSE, from PR #21) — template for a site-option projector that hooks an upstream filter. Whatever FOSSE-side code this SDD needs will follow this pattern.
-   `sdd/bluesky-native-publishing/` (FOSSE, from PR #18) — parent epic's SDD with the alternatives analysis for options 3 and 4. Prior positions noted; not binding here.
-   `sdd/bundled-backends/` (FOSSE, merged) — convention reference for how FOSSE bundles upstream code and the implications for upstream-first design.
