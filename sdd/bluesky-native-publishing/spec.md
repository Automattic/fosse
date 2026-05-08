---
status: shipped
---

# Spec: Bluesky Native Publishing

## Goal

Make short-form WordPress posts publish to Bluesky as *posts* (native `app.bsky.feed.post` with the body as text, no link card) instead of the current "teaser + link back to WordPress" card. Long-form posts keep the teaser + card. The short/long discriminator is `get_post_format()` — the exact same signal the ActivityPub plugin already uses to split Mastodon `Note` from `Article`. Users "just post" (optionally picking a post format like `status`); Bluesky and Mastodon agree on the shape automatically.

A user-level "always Note" override is owned by FOSSE (one option, `fosse_object_type`), projected into both backends via filters. AP and Atmosphere stay independent of each other — FOSSE is the only piece that knows both networks exist.

## Requirements Summary

- **Upstream Atmosphere PR**: `Atmosphere\Transformer\Post` becomes post-format-aware, with `is_short_form()` discriminator wrapped in a new `atmosphere_is_short_form_post` filter for downstream override.
- **Upstream AP PR**: wrap `Activitypub\Transformer\Post::get_type()`'s return in a new `activitypub_post_object_type` filter for downstream override (one-line addition + tiny refactor).
- **FOSSE side**: new `Automattic\Fosse\Object_Type` class projects a single FOSSE option (`fosse_object_type`) onto both upstream filters.
- **FOSSE side**: refresh `bundled/atmosphere/` and `bundled/activitypub/` via `tools/sync-bundled.sh` after each upstream merges.
- **FOSSE side**: Playwright e2e spec verifying facet parity (links, hashtags, mentions) on the short-form path. Closes [DOTCOM-16811](https://linear.app/a8c/issue/DOTCOM-16811).
- **Docs**: AGENTS.md captures the upstream-first rule (landed in [#23](https://github.com/Automattic/fosse/pull/23)); [DOTCOM-16812](https://linear.app/a8c/issue/DOTCOM-16812) gets a comment with the same matrix after review.

## Chosen Approach

**Mirror the AP plugin's [`get_type()`](https://github.com/Automattic/wordpress-activitypub/blob/trunk/includes/transformer/class-post.php) discriminator inside Atmosphere's [`Post::transform()`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php).** Each backend exposes one focused filter as the override point. FOSSE owns one cross-platform option and hooks both filters to project it.

This is the minimum viable change that delivers the epic's headline ask. It introduces *one targeted* filter per backend (no wide filters, no rewrites of upstream internals beyond the discriminator). Atmosphere stays AP-agnostic; AP stays Atmosphere-agnostic; FOSSE is the only seam that knows about both. Path to v2 (`site.standard.document` record embeds for long-form) is additive and stays open.

### Alternatives Considered

- **`fosse_note` CPT + two narrow Atmosphere filters.** The original SDD draft. Rejected because (a) it introduces a new content type that duplicates what `post_format` already expresses, (b) it diverges from AP's approach, making "publish once, reach everywhere" asymmetric, (c) it complicates the later unified-homepage stream ([DOTCOM-16818](https://linear.app/a8c/issue/DOTCOM-16818)) since the stream would have to union two post types.
- **Atmosphere reads `activitypub_object_type` directly.** Earlier interim proposal. Rejected: cross-coupling Atmosphere to AP's option ties two upstream plugins together for behavior the user thinks of as "their site's preference," not "an AP setting." The projector pattern owned by FOSSE is cleaner — each plugin stays unaware of the other; FOSSE is the only piece that crosses the line.
- **Use AP's existing `activitypub_transform_set_type` filter.** It exists today, but it only changes the wire-format `type` value being set on the activity object. AP's `get_type()` is called five times inside `Post::transform()` to drive content composition, attachment handling, content templates, and preview generation. Filtering only `set_type` leaves those internal decisions running with the un-overridden type — inconsistent state. Need a filter on the discriminator itself, hence the new upstream AP PR.
- **One wide Atmosphere filter (`atmosphere_bsky_post_record`)** letting consumers rewrite the whole composed record. Rejected: bigger surface than needed; forces consumers to duplicate Atmosphere's short-form composition logic; invites rewriting unrelated fields. Two focused yes/no override filters on the discriminator path are cleaner.
- **Option 2 — swap external link card for `app.bsky.embed.record` pointing at our own `site.standard.document`** on long-form posts. Rejected for v1: Bluesky's renderer for `site.standard.*` embeds has no published ship date; today a record embed to a non-`app.bsky.*` collection renders as a generic "not found" card, strictly worse than the link card. Tracked as v2 future bet ([DOTCOM-16827](https://linear.app/a8c/issue/DOTCOM-16827) dev-rel thread).
- **Option 3 — thread long-form posts** across multiple `app.bsky.feed.post` records with `reply` refs. Rejected outright.
- **Status-only discriminator** (only `post_format = status` counts as short-form). Rejected: AP treats *any* post format as `Note`. Matching AP exactly is the whole point.
- **Hard-cap short-form posts that exceed 300 graphemes by failing publish.** Rejected: surprising; makes Atmosphere stricter than AP. `truncate_text` handles over-cap gracefully.

## Technical Details

### Architecture

Three small pieces of code spread across three repos, connected by two new upstream filters and one FOSSE option:

```
+-----------------------------------+        +-----------------------------------+
|  Automattic/wordpress-activitypub |        |  Automattic/wordpress-atmosphere  |
|                                   |        |                                   |
|  Post::get_type()                 |        |  Post::transform()                |
|    └─ apply_filters(              |        |    └─ apply_filters(              |
|         'activitypub_post_        |        |         'atmosphere_is_short_     |
|          object_type', $type,     |        |          form_post', $is_short,   |
|          $post )                  |        |          $post )                  |
+-----------------┬-----------------+        +-----------------┬-----------------+
                  │                                            │
                  │ filter override                            │ filter override
                  │                                            │
                  └──────────────┬─────────────────────────────┘
                                 │
                  +-----------------------------+
                  |  Automattic/fosse           |
                  |                             |
                  |  Object_Type::register()    |
                  |    ├─ hooks AP filter       |
                  |    └─ hooks Atmosphere      |
                  |       filter                |
                  |                             |
                  |  reads get_option(          |
                  |    'fosse_object_type' )    |
                  +-----------------------------+
```

`fosse_object_type` accepts the same enum AP uses: `'note'` or `'wordpress-post-format'`. Default unset → both filter callbacks return their input unchanged → each backend uses its native per-post detection.

### Data Flow

**FOSSE option set to `note` (force short-form for everything):**

1. User publishes a post (any title, any format).
2. AP transformer runs. `Post::get_type()` computes the per-post type (Note/Article/Page). Applies `activitypub_post_object_type` filter → FOSSE callback returns `'Note'`. AP federates as Note.
3. Atmosphere transformer runs. `Post::is_short_form()` computes the per-post boolean. Applies `atmosphere_is_short_form_post` filter → FOSSE callback returns `true`. Atmosphere takes the short-form branch (body-as-text, no embed). Bluesky receives a native post.
4. Both networks receive consistent short-form treatment.

**FOSSE option unset / `wordpress-post-format` (per-post detection):**

1. User publishes a post.
2. AP transformer runs. `get_type()` returns its computed value. Filter passes through (FOSSE callback returns input unchanged). AP federates per-post.
3. Atmosphere transformer runs. `is_short_form()` returns its computed boolean. Filter passes through. Atmosphere branches per-post.
4. Both networks agree on the shape because they use the same discriminator logic — but each derived it independently.

**Atmosphere short-form composition** (when `is_short_form() === true`, filtered or not):
- Text: `apply_filters( 'the_content', $post->post_content )` → `wp_strip_all_tags` → `html_entity_decode` → whitespace-collapse → `truncate_text( …, 300 )`. Same plain-text rendering as `Document::get_text_content()` (extracted to a shared helper).
- Embed: `null` — the `embed` field is omitted from the record.
- Facets: extracted by the existing `Facet::extract()` against the final short-form text.
- `site.standard.document` record: unchanged. Full content lives there.

**Atmosphere long-form composition** (when `is_short_form() === false`): existing code path verbatim. Byte-identical output for every current Atmosphere user.

### Key Components

| Component | Repo | Change |
|---|---|---|
| `Activitypub\Transformer\Post::get_type()` | upstream AP | Wrap return value in `apply_filters( 'activitypub_post_object_type', $object_type, $this->item )`. Light refactor to return once at the bottom. |
| `Atmosphere\Transformer\Post::is_short_form()` | upstream Atmosphere (new) | Private method mirroring AP's discriminator. |
| `Atmosphere\Transformer\Post::build_short_form_text()` | upstream Atmosphere (new) | Renders `post_content` to plain text, clamps to 300. |
| `Atmosphere\Transformer\Post::transform()` | upstream Atmosphere | Branch on `apply_filters( 'atmosphere_is_short_form_post', $this->is_short_form( $post ), $post )`. |
| `Atmosphere\Transformer\Base` plain-text helper | upstream Atmosphere | Extract the `wp_strip_all_tags` + `html_entity_decode` + whitespace-collapse pattern from `Document::get_text_content()` into a shared protected helper. |
| `Automattic\Fosse\Object_Type` | FOSSE (new) | Static `register()` on `init`. Two filter callbacks projecting `fosse_object_type` onto both upstream filters. |
| `fosse_object_type` option | FOSSE (new) | One option, two values (`note` / `wordpress-post-format`). Default unset. No UI in this epic. |
| Atmosphere transformer test | upstream Atmosphere (new) | `tests/phpunit/tests/transformer/class-test-post.php` — currently absent on trunk. |
| AP transformer test addition | upstream AP | Add a test case to existing transformer test verifying the new filter overrides the type end-to-end. |
| FOSSE projector test | FOSSE (new) | `tests/php/Object_TypeTest.php` — option-driven filter behavior. |
| FOSSE e2e | FOSSE (new) | `tests/e2e/short-form-facets.spec.ts` — facet parity round-trip. Closes DOTCOM-16811. |
| `bundled/atmosphere/**` | FOSSE | Refreshed via `tools/sync-bundled.sh` after upstream merge. |
| `bundled/activitypub/**` | FOSSE | Refreshed via `tools/sync-bundled.sh` after upstream merge. |
| `AGENTS.md` | FOSSE | Capture the upstream-first decision rule. |

### File Changes

**Upstream AP** (`Automattic/wordpress-activitypub`, separate PR — Linear [DOTCOM-16839](https://linear.app/a8c/issue/DOTCOM-16839)):

| File | Change Type | Description |
|------|-------------|-------------|
| [`includes/transformer/class-post.php`](https://github.com/Automattic/wordpress-activitypub/blob/trunk/includes/transformer/class-post.php) | modify | Wrap `get_type()` return in new filter. Light refactor to single-return shape. |
| Existing transformer tests | modify | Add a test confirming the filter overrides the type and that all five internal `get_type()` consumers see the filtered value. |
| `readme.txt` / changelog | modify | One-line entry. |

**Upstream Atmosphere** (`Automattic/wordpress-atmosphere`, separate PR — Linear [DOTCOM-16838](https://linear.app/a8c/issue/DOTCOM-16838)):

| File | Change Type | Description |
|------|-------------|-------------|
| [`includes/transformer/class-post.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php) | modify | Add `is_short_form()`, `build_short_form_text()`. Branch in `transform()` with the new `atmosphere_is_short_form_post` filter. |
| [`includes/transformer/class-base.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-base.php) | modify | Extract plain-text helper from `Document::get_text_content()` (shared with the new short-form path). |
| [`includes/transformer/class-document.php`](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-document.php) | modify | Use the shared plain-text helper. Pure refactor — no behavior change. |
| `tests/phpunit/tests/transformer/class-test-post.php` | new | Start from scratch (no existing Post transformer test on trunk). Cover long-form unchanged, short-form variants, filter override, over-cap truncate. |
| `readme.txt` | modify | Changelog entry. |

**FOSSE** (`Automattic/fosse`) — this PR contains only the SDD docs; the implementation lands across follow-up PRs (current state noted inline). Linear [DOTCOM-16840](https://linear.app/a8c/issue/DOTCOM-16840) tracks the projector.

| File | Change Type | Description | Lands in |
|------|-------------|-------------|----------|
| `src/class-object-type.php` | new | `Automattic\Fosse\Object_Type`. Static `register()` + two filter callbacks. | [#21](https://github.com/Automattic/fosse/pull/21) |
| `fosse.php` | modify | One-line `add_action( 'init', [ '\Automattic\Fosse\Object_Type', 'register' ] )` alongside the existing bundled-bootstrap wiring. | [#21](https://github.com/Automattic/fosse/pull/21) |
| `tests/php/Object_TypeTest.php` | new | WorDBless test for option-driven filter behavior. | [#21](https://github.com/Automattic/fosse/pull/21) |
| `tests/e2e/short-form-facets.spec.ts` | new | Playwright e2e closing DOTCOM-16811. | [#21](https://github.com/Automattic/fosse/pull/21) |
| `tests/e2e/mu-plugins/fosse-bsky-capture.php` | new | Test-only mu-plugin that captures the transformed record on publish. | [#21](https://github.com/Automattic/fosse/pull/21) |
| `bundled/atmosphere/**` | regenerated | `tools/sync-bundled.sh` after Atmosphere PR merges. | [#19](https://github.com/Automattic/fosse/pull/19) (merged) |
| `bundled/activitypub/**` | regenerated | `tools/sync-bundled.sh` after AP PR merges. | [#19](https://github.com/Automattic/fosse/pull/19) (merged) |
| [`AGENTS.md`](https://github.com/Automattic/fosse/blob/trunk/AGENTS.md) | modify | Add "Upstream contribution policy" section. | [#23](https://github.com/Automattic/fosse/pull/23) |

### Ordering / Dependency

The two upstream PRs are independent — no inter-PR ordering. The FOSSE projector blocks on both.

```
[Upstream AP PR]                [Upstream Atmosphere PR]
       │                                 │
       │ merge                           │ merge
       ▼                                 ▼
[Bundle refresh: AP]            [Bundle refresh: Atmosphere]
       │                                 │
       └──────────────┬──────────────────┘
                      ▼
        [FOSSE Object_Type projector]
                      │
                      ▼
            [E2E facet parity]
                      │
                      ▼
         [Docs (AGENTS.md + DOTCOM-16812)]   (parallelizable any time)
```

If only one upstream PR merges first, FOSSE can refresh that one bundle and ship the corresponding half of the projector early — e.g. ship only the Atmosphere filter hook, leave the AP filter hook returning a no-op until AP merges. Not required, just an option.

## Out of Scope

- FOSSE composer / posting UI (DOTCOM-16794). The composer owns defaulting new posts to `post_format = status`, enforcing 300 graphemes in the UI, and (later) exposing `fosse_object_type` as a settings toggle.
- Unified onboarding (DOTCOM-16793).
- Inbound reactions (DOTCOM-16796) — already shipped upstream.
- Admin suppression of bundled settings (DOTCOM-16808).
- Threaded long-form — see alternatives.
- `app.bsky.embed.images` for posts with image attachments.
- Default `at.markpub.markdown` content parser — upstream `origin/add/markpub-parser` will ship.
- Option-2 record embeds for long-form posts — v2 future.
- Any UI for `fosse_object_type` — code/CLI configuration only in this epic.

## Open Questions Resolved

- **Discriminator.** Mirror AP's `get_type()` exactly: untitled OR has post format → short-form.
- **Cross-platform option.** FOSSE owns one option (`fosse_object_type`), values mirror AP's enum (`note` / `wordpress-post-format`), default unset/per-post detection. No FOSSE UI yet.
- **Projection mechanism.** Two new upstream filters (`activitypub_post_object_type`, `atmosphere_is_short_form_post`) + one FOSSE projector class. Each plugin stays unaware of the other.
- **Why not `activitypub_transform_set_type`.** It only changes the wire-format value being set; AP's `get_type()` drives multiple internal decisions that would still see the un-overridden value. Discriminator-level filter is the correct place.
- **Over-cap short-form posts.** Silently truncated with ellipsis via `truncate_text`. Composer UI prevents this in practice.
- **Long-form posts with a format (edge case).** Format wins — federate as short-form. Matches AP. Users can clear the format for long-form treatment.
- **Default content parser.** Wait on upstream `origin/add/markpub-parser`.

## Review Notes for Kraft / Ryan

Four notable calls:

1. **Two upstream PRs.** AP and Atmosphere both need new filters. They're independent — can be opened in parallel and merged in any order. The FOSSE projector blocks on both, but each upstream PR is independently useful (consumers other than FOSSE could hook the same filters).
2. **FOSSE owns the cross-platform option, not Atmosphere or AP.** Atmosphere doesn't read `activitypub_object_type`. AP doesn't know about Atmosphere. FOSSE is the only piece that ties them together. If you ever uninstall FOSSE and run AP+Atmosphere standalone, each plugin operates on its own settings cleanly.
3. **No UI for `fosse_object_type` in this epic.** Set via WP-CLI (`wp option update fosse_object_type note`) or programmatically. Composer epic (DOTCOM-16794) adds the toggle. If you'd rather have a tiny settings checkbox now, it's a half-day add — speak up.
4. **Refactor of `Document::get_text_content()` into a shared helper.** Bundled into the Atmosphere PR. Reviewer can ask to split.
