# Draft: upstream Atmosphere issue — `app.bsky.embed.images` support

**Status:** Draft for review. Not yet posted. Once approved, file at `Automattic/wordpress-atmosphere`.

**Suggested title:** Add `app.bsky.embed.images` support so posts with images federate as image embeds, not external link cards

**Suggested labels:** `enhancement`, `bluesky`, `at-protocol`

---

## Summary

When a WordPress post with one or more image attachments federates through Atmosphere, the resulting Bluesky record carries either a `app.bsky.embed.external` link card (with the post's permalink) or a plain text record — never a native `app.bsky.embed.images` embed. AT Protocol's lexicon supports up to four images per post with required alt text; for image-first content (photoblogs, gallery posts, anything where the image is the primary payload) the link-card path makes the post read as "a link with a thumbnail" instead of "a photo post."

Pixelfed and other AT-Proto image-aware clients render `app.bsky.embed.images` natively. Adding upstream support unlocks that audience for any site running Atmosphere.

## Reproduction

1. Install `wordpress-atmosphere` (current trunk) on a WordPress site.
2. Connect a Bluesky account via the OAuth flow.
3. Publish a post containing one or more image blocks (or a featured image with no other content).
4. Inspect the outbound record sent to the user's PDS via `com.atproto.repo.createRecord`.

**Observed:** the record carries `embed: { $type: 'app.bsky.embed.external', external: { uri: '<post permalink>', ... } }` (or no embed at all for short-form posts that fit under 300 chars).

**Expected:** when the post has image attachments and fits the lexicon constraints, the record carries `embed: { $type: 'app.bsky.embed.images', images: [...] }` with up to four images.

## Lexicon reference

Per [`app.bsky.embed.images`](https://raw.githubusercontent.com/bluesky-social/atproto/main/lexicons/app/bsky/embed/images.json) (current trunk):

-   `images` is required, max 4 entries.
-   Each entry needs `image` (`blob`, `image/*`, max 1MB) and `alt` (string, required, can be empty but the field must be present).
-   Optional `aspectRatio` ({ `width`, `height` }).

The blob upload step is `com.atproto.repo.uploadBlob`, which Atmosphere already exercises in other contexts.

## Proposed shape

A new `Transformer\Post::build_image_embed_record()` (or similar) that:

1. Discovers attached images via WordPress's existing primitives — `get_attached_media( 'image', $post_id )`, `get_post_thumbnail_id`, parsed image blocks, parsed inline `<img>` tags. Use the same pipeline ActivityPub uses today (`activitypub_attachment_ids`, with the same fallback chain) so a `add_filter('activitypub_attachment_ids', ...)` works for both backends.
2. Caps at four images per the lexicon constraint. When more attached, picks the first four in `menu_order` then attachment ID order.
3. Uploads each via `com.atproto.repo.uploadBlob`, passing through MIME type and size constraints (lexicon caps each blob at 1MB; oversize blobs need to be downscaled or skipped).
4. Reads alt text from the WordPress attachment's `_wp_attachment_image_alt` meta. Empty alt is allowed by the lexicon but should probably trip a warning surface (operator-visible) rather than fail silently.
5. Calculates `aspectRatio` from the attachment's stored width/height when present.
6. Wraps the assembled image entries into the `embed` field on the outbound `app.bsky.feed.post` record.

## Behavior selection

Suggest a new `atmosphere_post_embed_strategy` filter (paralleling `atmosphere_long_form_composition`) so consumers can pick:

-   `images` — when the post has attachments, embed images. Falls back to link-card or plain text when there are none.
-   `link-card` (current default) — today's behavior.
-   `none` — text-only.

Or, simpler: a per-post predicate `atmosphere_should_embed_images( $post )` that defaults to "true when the post has attachments and no significant body text" and lets consumers tune.

Worth a short RFC on the discriminator before implementing.

## Long-form interaction

Today's `atmosphere_long_form_composition` paths (`teaser-thread`, `truncate-link`, `link-card`) all assume text. For image embeds:

-   A short-form image post (< 300 chars body) should emit a single image-embed record.
-   A long-form image post (> 300 chars body, multiple images) is awkward — you can only embed one type per record. Probably: emit the long-form composition (thread / truncate / link card) as today, with images attached only to the first record (or a thread root). RFC territory.

## Out of scope

-   Video embeds (`app.bsky.embed.video` lexicon). Different upload pipeline; worth a separate issue.
-   Quote embeds (`app.bsky.embed.record`). Already partly covered by the document-card forward-compat slot.
-   Pixelfed-specific shape preferences beyond what the lexicon describes.

## References

-   AT Protocol image embed lexicon: https://github.com/bluesky-social/atproto/blob/main/lexicons/app/bsky/embed/images.json
-   AT Protocol post lexicon: https://github.com/bluesky-social/atproto/blob/main/lexicons/app/bsky/feed/post.json
-   Pixelfed ActivityPub docs: https://pixelfed.github.io/docs-next/spec/ActivityPub.html
-   FOSSE audit observation: https://github.com/Automattic/fosse/pull/103 (`audits/2026-05-06-fosse-plugin-audit-report.md`, "Photoblog And Blurt Assessment" + "Federation And Network Semantics" sections).

## Why upstream, not in FOSSE

Per FOSSE's `AGENTS.md` upstream-contribution policy: the image embed shape is post-type-agnostic correctness. Any consumer of `wordpress-atmosphere` benefits — it's not specific to FOSSE's "publish once, reach everywhere" model. The right place is `Automattic/wordpress-atmosphere`. FOSSE will consume via `tools/sync-bundled.sh` once it lands.

---

**Reviewer notes (Claude → Brandon):**

-   I left the discriminator question open (filter vs predicate). My weak preference is the filter, since it parallels `atmosphere_long_form_composition` and gives consumers a single hook surface for embed-shape decisions.
-   The 1MB blob cap is per-blob, but Bluesky also enforces a total post-size limit. Worth verifying the math before posting — I didn't dig into that.
-   The "long-form interaction" section is genuinely unresolved. Might be worth posting as a separate RFC issue / discussion rather than embedding in the implementation issue.
-   Pixelfed's actual rendering of `app.bsky.embed.images` (vs ActivityPub `attachment` arrays) is undocumented to me. Probably worth a smoke test against a real Pixelfed instance before assuming the embed-images path actually reaches Pixelfed users — it may only render for native Bluesky clients.
