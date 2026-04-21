# Bluesky Native Publishing — Requirements

## Goal

Move FOSSE's Bluesky output past "Jetpack Social with extra steps." Long-form posts today show up on Bluesky as link cards, which is correct for long-form — a reader doesn't want a chopped-up essay in their timeline, they want a card. But **short posts should publish as *a post*, not a card back to WordPress.** Same as the ActivityPub plugin already does for Mastodon (`Note` vs. `Article`).

The user model is "just post" — no new content type, no new CPT, no "is this a note or a post?" decision. WordPress already has `post_format` and the AP plugin already uses it as the short/long discriminator. Atmosphere should mirror that exact logic for Bluesky.

Source: [DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795/3-bluesky-native-publishing-beyond-link-cards). Sub-issues consolidated here: DOTCOM-16809 (confirm `site.standard.document` writes on the short-form path), DOTCOM-16811 (facet parity), DOTCOM-16812 (upstream-vs-FOSSE decision record).

## Requirements

1. **Atmosphere's `app.bsky.feed.post` composition must be post-format-aware**, mirroring the AP plugin's `get_type()` discriminator ([`includes/transformer/class-post.php`](https://github.com/Automattic/wordpress-activitypub/blob/trunk/includes/transformer/class-post.php) in `Automattic/wordpress-activitypub`):
   - A post is short-form (AP: `Note`) if it has no title, **or** if it has a non-empty `post_format` (e.g. `status`, `aside`, `link`, `quote`, `chat`).
   - Otherwise it's long-form (AP: `Article`).
2. **Short-form path**: the Bluesky post text is the rendered plain-text body of the post (no title prefix, no trailing permalink). No automatic `app.bsky.embed.external` link card. The text is defensively clamped to 300 graphemes via the existing `Atmosphere\truncate_text` helper.
3. **Long-form path unchanged**: current title + excerpt + permalink composition, truncated to 300 graphemes, with external link-card embed.
4. **`site.standard.document` writes stay unchanged.** Every syncable post already gets a document record regardless of post format — verify with a test, no code change expected.
5. **Facet extraction** (links, hashtags, mentions) runs on whatever the final composed text is. On the short-form path that means facets operate on the post body — verify end-to-end with a Playwright spec.
6. **Upstream-first.** All Atmosphere behavior changes land in `Automattic/wordpress-atmosphere` as a PR. FOSSE consumes the change by refreshing `bundled/atmosphere/` via `tools/sync-bundled.sh`. No FOSSE-layer filters for this epic.
7. **Decision record on DOTCOM-16812**: document the "post-type-agnostic correctness goes upstream; FOSSE-shape-specific stays in FOSSE" rule with this epic as the canonical example.

## Constraints

- **Symmetry with the AP plugin is mandatory.** If a user sets `post_format = status`, both Mastodon (via AP) and Bluesky (via Atmosphere) must treat the post as short-form. Divergent discriminators would be a UX bug.
- Upstream PR must preserve byte-identical behavior for every existing Atmosphere user on the **default (titled, no format) post** — the long-form path. A regression here would affect every current Atmosphere install.
- No FOSSE PHP code should be required for this epic's publishing path. FOSSE's sole code contribution is an e2e test under `tests/e2e/` for facet parity.
- FOSSE CI stays green: PHPUnit matrix, Jest, Playwright E2E, PHPCS, ESLint/Prettier.
- `site.standard.document` stays the central persistent artifact. Option 2 (swap the external-card embed for a record embed pointing at the doc) remains a future path — don't design anything that forecloses it.
- **No threaded long-form.** Discarded; see spec's alternatives.

## Out of Scope

- FOSSE composer / posting UI (DOTCOM-16794). The composer will default new posts to `post_format = status` and enforce 300 graphemes, but that's its own epic.
- Unified onboarding flow (DOTCOM-16793).
- Inbound reactions from Bluesky (DOTCOM-16796) — already shipped upstream on atmosphere trunk.
- Admin suppression / rebadging of bundled plugin settings (DOTCOM-16808).
- Threaded long-form publishing (discarded).
- `app.bsky.embed.images` support for posts with attached images. Atmosphere today ships only `app.bsky.embed.external`; adding `images` is a separate upstream improvement, not this epic's scope.
- Default `at.markpub.markdown` content parser — upstream has an open branch (`origin/add/markpub-parser`) we'll consume when merged.
- `site.standard.document` record embeds on long-form posts (Option 2 future bet).
- Any schema changes to `site.standard.publication`.

## Open Questions

- **Discriminator exact behavior.** Matches AP's `get_type()` exactly: `untitled OR has post_format → short-form`. Additionally, `page` post type federates as `Page` in AP — not relevant here since Atmosphere's `syncable_post_types` defaults to `['post']` and pages aren't in that list.
- **Over-cap short-form posts.** If the composer fails to enforce the 300-grapheme cap, Atmosphere truncates with an ellipsis (same as current long-form over-cap behavior). No fallback to long-form shape — that would be surprising.
- **Short-form posts with featured images / attachments.** Out of scope for this epic; see constraints. A short-form post with an image publishes with just text today (no thumbnail, no link card). That's the same as the pre-change behavior for titled posts with no format — Atmosphere already produces a plain link card without special image handling beyond the thumbnail on the card itself.
- **Long-form posts with `post_format = status`.** Edge case: user sets a format on a titled post. Per AP's logic, the format wins — federate as short-form. Accept this; users can clear the format if they want long-form treatment.

## Related Code / Patterns Found

Links point at `trunk` on GitHub for portability. The repos live locally under `~/code/<repo-name>` for any contributor following the FOSSE working-directory convention (e.g. `Automattic/wordpress-atmosphere` → `~/code/wordpress-atmosphere`). Where line numbers may drift, the function/method name is given so the reference survives upstream churn.

- [**`includes/transformer/class-post.php` `get_type()`**](https://github.com/Automattic/wordpress-activitypub/blob/trunk/includes/transformer/class-post.php) — **the reference implementation.** AP plugin's discriminator returns `Note`, `Article`, or `Page` based on title presence, post format, and post type. Atmosphere must match this logic for the short/long split.
- [**`includes/transformer/class-post.php` `transform()`**](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php) (atmosphere) — where the new format branch lands.
- [**`includes/transformer/class-post.php` `build_text()`**](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php) (atmosphere) — today composes title + excerpt + permalink. Becomes the long-form branch; short-form gets a sibling method.
- [**`includes/transformer/class-post.php` `build_embed()`**](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-post.php) (atmosphere) — today always attaches an external card. Returns `null` on the short-form branch.
- [**`includes/transformer/class-facet.php` `Facet::extract()`**](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-facet.php) (atmosphere) — runs on whatever text it's given; no change needed.
- [**`includes/transformer/class-document.php`**](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/transformer/class-document.php) (atmosphere) — document record builder. Post-format-agnostic today; no change. Verified by a new test.
- [**`includes/functions.php` `sanitize_text()` / `truncate_text()`**](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/functions.php) (atmosphere) — helpers the new short-form path reuses.
- [**`includes/class-publisher.php` `publish()`**](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/class-publisher.php) (atmosphere) — the atomic applyWrites call. Post-format-agnostic today; no change.
- [**`includes/class-backfill.php` `syncable_post_types()`**](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/includes/class-backfill.php) (atmosphere) — default `['post']`; no change.
- [**FOSSE `tools/sync-bundled.sh`**](https://github.com/Automattic/fosse/blob/trunk/tools/sync-bundled.sh) — refreshes `bundled/atmosphere/` after the upstream PR merges.
- [**FOSSE `sdd/bundled-backends/`**](https://github.com/Automattic/fosse/tree/trunk/sdd/bundled-backends) — SDD folder pattern this feature follows.
