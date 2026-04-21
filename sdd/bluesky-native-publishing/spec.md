# Spec: Bluesky Native Publishing

## Goal

Make short-form WordPress posts publish to Bluesky as *posts* (native `app.bsky.feed.post` with the body as text, no link card) instead of the current "teaser + link back to WordPress" card. Long-form posts keep the teaser + card. The short/long discriminator is `get_post_format()` — the exact same signal the ActivityPub plugin already uses to split Mastodon `Note` from `Article`. Users "just post" (optionally picking a post format like `status`); Bluesky and Mastodon agree on the shape automatically.

All behavior changes land upstream in `Automattic/wordpress-atmosphere`. FOSSE consumes the change by re-bundling atmosphere. FOSSE's only code contribution in this epic is a Playwright e2e test for facet parity on the short-form path.

## Requirements Summary

- Upstream: `Atmosphere\Transformer\Post` becomes post-format-aware.
  - Short-form (untitled **or** has a post format): body-as-text, no embed.
  - Long-form (titled with no format): unchanged — title + excerpt + permalink + external card.
- Upstream: preserve byte-identical behavior on the long-form path for every current user.
- FOSSE: refresh `bundled/atmosphere/` via `tools/sync-bundled.sh` after upstream merge.
- FOSSE: Playwright e2e spec that publishes a short-form post with `#tag`, `@handle.tld`, and a link, then asserts the three facet features round-trip through the record correctly (closes DOTCOM-16811).
- Docs: AGENTS.md captures the upstream-first decision rule; DOTCOM-16812 gets a comment with the same matrix.

## Chosen Approach

**Mirror the AP plugin's [`get_type()`](https://github.com/Automattic/wordpress-activitypub/blob/trunk/includes/transformer/class-post.php) discriminator directly in Atmosphere's [`Post::transform()`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php).** A post is short-form iff AP would federate it as a `Note`: no title **or** non-empty `get_post_format()`. Short-form branch composes body text and returns `null` embed; long-form branch is the existing code path.

This is the minimum viable change that delivers the epic's headline ask. It doesn't introduce any new extension points, doesn't require FOSSE-side code for publishing, doesn't depend on external Bluesky roadmap, and stays in lockstep with AP's behavior so the cross-network story is coherent.

### Alternatives Considered

- **`fosse_note` CPT + two narrow Atmosphere filters.** The original SDD draft. Rejected because (a) it introduces a new content type that duplicates what `post_format` already expresses, (b) it diverges from AP's approach, making "publish once, reach everywhere" asymmetric across networks, (c) it pushes complexity onto FOSSE (CPT registration, `atmosphere_syncable_post_types` filter, `Atmosphere_Integration` class hooking two filters) for behavior that can live entirely upstream, and (d) it complicates the later unified-homepage stream (DOTCOM-16818) because the stream would have to union two post types instead of just querying `post`.
- **One wide filter (`atmosphere_bsky_post_record`)** letting a consumer rewrite the whole record. Rejected: bigger surface than needed and invites consumers to rewrite unrelated fields. Internal branching in the transformer is cleaner than an external extension point when the logic is universally useful.
- **Option 2 — swap external link card for `app.bsky.embed.record` pointing at our own `site.standard.document`** on long-form posts. Rejected for v1 because Bluesky's renderer for `site.standard.*` embeds has no published ship date; today a record embed to a non-`app.bsky.*` collection renders as a generic "not found" card, strictly worse than the current link card. Tracked as a v2 future bet (DOTCOM-16827 dev-rel thread, Ryan leading).
- **Option 3 — thread long-form posts** across multiple `app.bsky.feed.post` records with `reply` refs. Rejected outright: N writes per publish, fragmented engagement, ugly update/delete semantics, and most long-form writers don't want their essay auto-threaded.
- **Status-only discriminator** (only `post_format = status` counts as short-form, not any format). Rejected: AP treats any post format as `Note`, not just `status`. Matching AP exactly is the whole point.
- **Hard-cap short-form posts that exceed 300 graphemes by failing publish.** Rejected: surprising for users, makes Atmosphere stricter than AP (which silently handles long `Note` bodies). Atmosphere's existing `truncate_text` helper handles over-cap gracefully with an ellipsis.

## Technical Details

### Architecture

Pure upstream change. No new classes, no new hooks, no new filters. Existing `Atmosphere\Transformer\Post::transform()` gains a format branch, delegating to one of two composition paths:

```
Post::transform()
  │
  ├── is_short_form( $post )?
  │     ├── yes → build_short_form_text()   (body-as-text, clamped to 300)
  │     │        build_short_form_embed()   (null)
  │     │
  │     └── no  → build_text()              (existing title+excerpt+permalink)
  │               build_embed()              (existing external card)
  │
  └── existing applyWrites path continues unchanged
```

`is_short_form()` mirrors AP's `get_type()` logic exactly:

```php
private function is_short_form( \WP_Post $post ): bool {
    if ( ! \post_type_supports( $post->post_type, 'title' ) || empty( $post->post_title ) ) {
        return true;
    }
    if ( \get_post_format( $post ) ) {
        return true;
    }
    return false;
}
```

### Data Flow

**Short-form publish** (`post_format = status`, titled or untitled):

1. User publishes. Atmosphere's `transition_post_status` hook fires as it does today.
2. `Publisher::publish()` builds `Post` transformer, calls `transform()`.
3. `transform()` checks `is_short_form()` → true.
4. `build_short_form_text()` runs `apply_filters( 'the_content', $post->post_content )`, strips tags, decodes entities, collapses whitespace (reusing `Document::get_text_content()`'s logic — extract to a shared private helper), then `truncate_text( …, 300 )`.
5. Embed stays `null`; the record's `embed` field is omitted.
6. Facets extract on the short-form text body.
7. `applyWrites` writes `app.bsky.feed.post` (short-form shape) + `site.standard.document` (unchanged — full content lives here) atomically.
8. Follow-up `putRecord` attaches `bskyPostRef` to the document (existing path).

**Long-form publish** (titled, no format): entirely unchanged. `is_short_form()` returns false → existing code path → byte-identical output.

### Key Components

| Component | Repo | Change |
|---|---|---|
| `Atmosphere\Transformer\Post::transform()` | upstream | Branch on `is_short_form()`. |
| `Atmosphere\Transformer\Post::is_short_form()` | upstream (new) | Mirrors AP's `get_type()` discriminator. Private method on `Post`. |
| `Atmosphere\Transformer\Post::build_short_form_text()` | upstream (new) | Renders `post_content` to plain text, clamps to 300. |
| `Atmosphere\Transformer\Post::build_text()` | upstream | Unchanged. Now only called on long-form branch. |
| `Atmosphere\Transformer\Post::build_embed()` | upstream | Unchanged. Now only called on long-form branch. |
| `Atmosphere\Transformer\Post` plain-text helper | upstream | Extract the `wp_strip_all_tags` + `html_entity_decode` + whitespace-collapse pattern currently in `Document::get_text_content()` into a shared private helper (maybe on `Base`) so both document and short-form post builders use it. |
| `tests/phpunit/tests/transformer/class-test-post.php` | upstream (new) | Tests for: long-form unchanged; untitled post is short-form; titled + format is short-form; titled + no format is long-form; over-cap short-form is truncated. |
| `bundled/atmosphere/**` | FOSSE | Refreshed via `tools/sync-bundled.sh` after upstream merge. |
| `tests/e2e/short-form-facets.spec.ts` | FOSSE (new) | Playwright spec: publish a `post_format=status` post with tag/mention/link; assert the three facet features. Closes DOTCOM-16811. |
| `AGENTS.md` | FOSSE | New section capturing the upstream-first decision rule. |

### File Changes

**Upstream repo** (`/Users/kraft/code/wordpress-atmosphere`, separate PR):

| File | Change Type | Description |
|------|-------------|-------------|
| `includes/transformer/class-post.php` | modify | Add `is_short_form()`, `build_short_form_text()`. Branch in `transform()`. |
| `includes/transformer/class-base.php` | modify | Extract plain-text helper (shared with `Document::get_text_content()`). |
| `includes/transformer/class-document.php` | modify | Use the shared plain-text helper instead of inlined logic. Pure refactor — no behavior change. |
| `tests/phpunit/tests/transformer/class-test-post.php` | new | File doesn't exist on atmosphere trunk today. Start from scratch. |
| `readme.txt` | modify | Changelog line: "Short-form posts (untitled or with a post format) now publish as native Bluesky posts instead of link cards, matching the ActivityPub plugin's `Note` discriminator." |
| `integrations/README.md` | no change | No new filters to document. |

**FOSSE repo** (`/Users/kraft/code/fosse`):

| File | Change Type | Description |
|------|-------------|-------------|
| `bundled/atmosphere/**` | modify (regenerated) | `tools/sync-bundled.sh` after upstream merge. |
| `tests/e2e/short-form-facets.spec.ts` | new | Playwright e2e closing DOTCOM-16811. |
| `tests/e2e/helpers/atproto.ts` | new (if needed) | Shared helpers for intercepting `applyWrites` or hitting a sandbox PDS. |
| `AGENTS.md` | modify | Add "Upstream contribution policy" section. |

### Ordering / Dependency

1. **Upstream Atmosphere PR** — first. No FOSSE dependency. Ships independently.
2. **FOSSE bundle refresh** — after upstream merges. `tools/sync-bundled.sh`. One commit.
3. **FOSSE e2e test** — after bundle refresh (it tests the new short-form behavior).
4. **Docs (AGENTS.md + DOTCOM-16812 comment)** — parallelizable any time.

## Out of Scope

- FOSSE composer UI (DOTCOM-16794). The composer owns defaulting new posts to `post_format = status` and enforcing 300 graphemes in the UI.
- Unified onboarding (DOTCOM-16793).
- Inbound reactions (DOTCOM-16796) — already shipped upstream.
- Admin suppression of bundled settings (DOTCOM-16808).
- Threaded long-form — see alternatives.
- `app.bsky.embed.images` for posts with image attachments. Atmosphere ships only `app.bsky.embed.external` today; image support is a separate upstream improvement.
- Default `at.markpub.markdown` content parser — tracked out of scope; upstream `origin/add/markpub-parser` will ship.
- Option-2 record embeds for long-form posts — v2 future.

## Open Questions Resolved

- **Discriminator.** Mirror AP's `get_type()` exactly: untitled OR has post format → short-form. Same signal users already learn for Mastodon.
- **Over-cap short-form posts.** Silently truncated with ellipsis via `truncate_text`. Consistent with the long-form over-cap path. Composer UI (DOTCOM-16794) prevents users from getting there in practice.
- **Long-form posts with a format (edge case).** Format wins — federate as short-form. Matches AP. Users can clear the format for long-form treatment.
- **Upstream abstraction.** No new filters. Internal branching in the transformer. Simpler than the earlier two-filter design; both Mastodon (via AP) and Bluesky (via Atmosphere) now use the same internal format-aware logic without consumer-side plumbing.
- **Default content parser.** Wait on upstream `origin/add/markpub-parser`. Revisit if it stalls 4+ weeks.

## Review Notes for Kraft / Ryan

Three notable calls:

1. **No FOSSE PHP code in this epic.** The entire publishing behavior change is upstream in Atmosphere. FOSSE's only contribution is the e2e test and documentation. If you want a FOSSE-layer smoke integration test (PHPUnit in FOSSE rather than e2e), speak up — it'd be a small add.
2. **`is_short_form()` is a private method, not a filter.** Users can't override it. If a consumer wants different behavior (e.g. "always federate as short-form regardless of format"), they'd have to wrap the existing `atmosphere_transform_bsky_post` filter. That's fine for v1 — the discriminator is universally useful, not a point of legitimate customization. If use cases emerge we can add `atmosphere_is_short_form_post` later.
3. **Refactor of `Document::get_text_content()` into a shared helper.** Small quality-of-life cleanup bundled into this PR. If the upstream reviewer prefers to keep the refactor separate, we split it into two PRs.
