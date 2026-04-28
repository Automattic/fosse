# Spec: Unified Reactions Display

## Goal

Step 1 of [DOTCOM-16894](https://linear.app/a8c/issue/DOTCOM-16894). Surface ActivityPub and Bluesky reactions side by side on a single WordPress post by leaning on the bundled `activitypub/reactions` block (whose query is already protocol-agnostic) and reframing it as a FOSSE feature rather than an AP-only feature. The visible deliverables are: (1) a small FOSSE-side relabel from "Fediverse Reactions" to "Social Reactions" on that block, (2) a Playwright end-to-end test that proves both networks' reactions render together, and (3) the test mu-plugin that makes the e2e seed deterministic. No new block, no JS/Gutenberg build infra.

## Requirements Summary

- **Source-agnostic v1**: AP and Bluesky reactions fold into shared counts and lists. No per-source badges.
- **Verification on a real-shaped install**: prove the unified display works in the Playground harness, not just on paper.
- **Relabel** "Fediverse Reactions" → "Social Reactions" on the inserter UI surfaces (block title + description). Legacy render fallback is NOT in scope (see "Known Gaps").
- **Playwright e2e** under `tests/e2e/` that seeds `wp_comments` rows for both `protocol='activitypub'` and `protocol='atproto'` and asserts the block renders both.
- **Shim stays small**: PHP-only, follows the existing FOSSE projector pattern (`Object_Type`, `Post_Types`, `Long_Form_Strategy`).
- **Upstream-first**: any post-type-agnostic improvement to bundled-plugin reaction handling lands in `wordpress-activitypub` / `wordpress-atmosphere`, not in FOSSE.
- **No edits to `bundled/`** and no JS build infra in this SDD.

Out of scope for v1: replies handling, the `posts-and-replies` block, a FOSSE-owned reactions block, per-source visual distinction, JS/Gutenberg build infra. See `requirements.md` for the full list.

## Chosen Approach

**`register_block_type_args` filter** — a tightly-scoped hook that rewrites the block's registered metadata (title + description) when the registered block name matches `activitypub/reactions`. Wrapped in a small FOSSE-side class following the existing projector pattern.

### Why this over the alternatives

- **Precision over breadth.** The relabel only needs to land on `activitypub/reactions`. `register_block_type_args` makes that scope explicit at the call site; a future reader sees the `'activitypub/reactions' === $block_name` guard immediately. The `gettext` alternative, while simpler, hooks every translation in the AP textdomain and relies on string-match coupling to remain narrow. The block-args approach is the more honest expression of intent.
- **Documented core API.** `register_block_type_args` is part of WordPress's stable block-registration pipeline. The contract — array in, array out, guarded by registered name — is intentional and not a side effect.
- **Smallest blast radius if upstream drifts.** If AP later renames or restructures the block's title/description, the filter no-ops cleanly (the array key just isn't there to overwrite) rather than failing with a cryptic substitution mismatch in some other location.

### Alternatives Considered

- **`gettext` filter on the AP textdomain** (Approach A in brainstorm): catches every user-visible surface in one hook, including the legacy v1.0.0 render fallback in `bundled/activitypub/build/reactions/render.php:40`. Rejected for being broader than the actual scope and string-match-coupled to the upstream wording.
- **Layered (`register_block_type_args` + narrow `gettext`)** (Approach C in brainstorm): would cover the legacy-fallback gap explicitly. Rejected on YAGNI grounds — see "Known Gaps" below; if the legacy fallback turns out to matter, layering can be added in a follow-up without rewriting v1.

## Technical Details

### Architecture

One new PHP class, `Automattic\Fosse\Reactions_Label`, lives in `src/class-reactions-label.php` and follows the existing FOSSE projector shape:

- A `private const BLOCK_NAME = 'activitypub/reactions'` and a small set of `private const` strings holding the substituted title and description.
- A `public static function register(): void` that calls `add_filter( 'register_block_type_args', [ self::class, 'rewrite_block_args' ], 10, 2 )`.
- A `public static function rewrite_block_args( array $args, string $name ): array` that returns the input unchanged unless `$name === self::BLOCK_NAME`, in which case it overlays the new `title` and `description` keys.

`fosse.php` registers the class on `init` behind the same `class_exists` guard the other projectors use, immediately after the `Long_Form_Strategy` registration block.

### Data Flow

- WordPress fires `register_block_type` for `activitypub/reactions` (during the bundled AP plugin's bootstrap).
- The `register_block_type_args` filter runs with the block's metadata array.
- `Reactions_Label::rewrite_block_args()` matches the name, overlays new strings, returns the modified array.
- The block inserter and any block-metadata consumers see the relabeled title and description.
- The block's `render.php` is untouched at runtime; it queries comments protocol-agnostically and renders whatever rows match.

There is no runtime data flow on every request — the filter only fires at block-registration time, once per request boot.

### Key Components

| Component | Responsibility |
|-----------|----------------|
| `Automattic\Fosse\Reactions_Label` | The single static class. Holds the substituted strings as constants; registers and runs the filter. |
| `fosse.php` registration block | Mirrors the `Long_Form_Strategy` registration: `add_action('init', …)` + `class_exists` guard. |
| `tests/php/Reactions_LabelTest.php` | PHPUnit case extending `\WorDBless\BaseTestCase`. Asserts: (1) the filter rewrites for the right block name, (2) it returns input unchanged for any other block name, (3) `register()` is callable repeatedly without double-registering (matches `Post_Types` / `Long_Form_Strategy` idempotency precedent). |
| `tests/e2e/reactions-display.spec.ts` | Playwright spec. Loads a page with the block in place, asserts both protocols' avatars appear, and asserts the relabeled inserter title appears in admin contexts (or, if simpler, a settings/customizer surface where block metadata is rendered). |
| `tests/e2e/mu-plugins/fosse-reactions-seed.php` | Test-scoped mu-plugin that on activation seeds `wp_comments` rows: a published post + N "like" rows split between `protocol='activitypub'` and `protocol='atproto'` with the meta keys both plugins use. Mirrors the convention established by `tests/e2e/mu-plugins/fosse-bsky-capture.php`. |
| `tests/e2e/blueprint.json` | Blueprint may need to mount the new mu-plugin alongside the existing capture mu-plugin. Decision in plan phase. |

### File Changes

| File | Change Type | Description |
|------|-------------|-------------|
| `src/class-reactions-label.php` | new | The `Reactions_Label` class. |
| `fosse.php` | modify | One new `add_action('init', …)` block registering `Reactions_Label::register()` behind a `class_exists` guard. Sits between the `Long_Form_Strategy` and `Provider_Loader` blocks. |
| `tests/php/Reactions_LabelTest.php` | new | PHPUnit cases. |
| `tests/e2e/reactions-display.spec.ts` | new | Playwright spec. |
| `tests/e2e/mu-plugins/fosse-reactions-seed.php` | new | Seeding mu-plugin. |
| `tests/e2e/blueprint.json` | possibly modify | If the existing blueprint doesn't already auto-mount mu-plugins from `tests/e2e/mu-plugins/`, add a step. |
| `sdd/unified-reactions-display/implementation.md` | new at end | Captures verification outcomes, any deviations from this spec. Per `AGENTS.md` SDD convention. |

## Known Gaps

The legacy render fallback at `bundled/activitypub/build/reactions/render.php:40` (`$_title = $attributes['title'] ?? __('Fediverse Reactions', 'activitypub');`) is **not** caught by `register_block_type_args`. That string only appears for v1.0.0 blocks (pre-inner-blocks era) where the title was an attribute, not a heading inside the block content. Modern blocks (the default since AP 1.1+) supply their own heading inner block, so the fallback never fires.

Why we accept this gap in v1:
- Modern blocks dominate. Any FOSSE install is on a recent AP version per the bundled refresh cadence.
- If a legacy v1.0.0 block survives a migration, the visual leak is small (one heading on one block on one site).
- A follow-up can add a narrow `gettext` filter scoped to the single string if a real install surfaces this. That follow-up is one method on `Reactions_Label`, not a redesign.

## Verification & E2E Strategy

**Seeding via mu-plugin, not real network.** Atmosphere's `Reaction_Sync` polls Bluesky over the network. Playground + Playwright cannot rely on a live Bluesky account in CI. The e2e mu-plugin instead writes `wp_comments` rows directly with the exact shape both plugins produce — `comment_type` ∈ `{like, repost}`, `comment_approved=1`, `parent=0`, plus the `protocol` / `source_id` / `_atmosphere_author_avatar` meta. This isolates "does the unified display work?" from "does Atmosphere's polling work?", which is the right division of concerns: the latter is Atmosphere's responsibility, the former is FOSSE's.

**E2E assertions, in plan-phase order:**
1. Page contains the `activitypub/reactions` block on the seeded post.
2. The rendered facepile contains avatars from both protocol values (assert at least one row from each `protocol`).
3. The reactions count label reflects the total across both protocols.
4. The block inserter (or some metadata-rendering surface) shows the relabeled title "Social Reactions". *Plan phase to pick the precise locator — admin block library if accessible from Playground, otherwise a backend assertion via REST or PHP-side metadata read.*

## Contingency

If the e2e fails because Atmosphere's rows don't render in the AP block on a real install — the inferred protocol-agnostic query notwithstanding — the failure tells us exactly which assertion broke and points at the cause. Two ladder steps:

1. **Tactical (FOSSE-side workaround).** Add a narrow filter on `comments_pre_query` or on AP's per-type `get_comments()` call site to ensure Bluesky rows are included. Kept in `Reactions_Label` or a sibling class. Documented as a holdover until upstream resolves.
2. **Strategic (upstream).** File an upstream PR — most likely on `wordpress-atmosphere` to register its `comment_type` values with `register_comment_type` (or coordinate with AP to make the query unambiguously protocol-agnostic). Track in Linear; refresh `bundled/atmosphere/` after merge.

The contingency is documented here so the plan and any reviewer know what to do without re-deriving it.

## Out of Scope (for completeness)

- Replies handling and the `posts-and-replies` block.
- A FOSSE-owned reactions block (deferred until evidence demands one).
- JS/Gutenberg build infra in this repo.
- Per-source visual distinction (badges, separate counts).
- Quote-post handling on the Bluesky side (AT Protocol has no native quote primitive).
- Any change to AP's reaction-handling internals or Atmosphere's `Reaction_Sync` internals.
- Atmosphere's `Reaction_Sync` polling cadence, watermark logic, or REST surface.
- Admin diagnostic UI ("show me per-source reaction counts on this post").
- Covering the legacy v1.0.0 render fallback string (see "Known Gaps").

## Open Questions Resolved

- **Filter choice for the relabel.** Resolved: `register_block_type_args`. Precision over breadth.
- **E2E seeding strategy.** Resolved: test-scoped mu-plugin under `tests/e2e/mu-plugins/`, mirroring the `fosse-bsky-capture.php` pattern. Seeds rows directly rather than depending on a real network round-trip.
- **AP's inserter description.** Resolved: in scope. The `register_block_type_args` filter rewrites both `title` and `description`.
- **Naming.** Resolved: "Social Reactions" for the title; matching wording for the description ("Display social likes and reposts for your posts." or similar — exact phrasing in the plan phase).
- **What if verification fails.** Resolved via the "Contingency" section above.
