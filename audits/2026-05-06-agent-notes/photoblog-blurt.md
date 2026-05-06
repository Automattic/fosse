# Photoblog + Blurt Workflow Audit

Target: FOSSE `origin/trunk` at `1437a14f3b3022050ba6625d31497828f581c07c`.

Scope note: bundled ActivityPub and Atmosphere code was inspected only as reference for what FOSSE projects into. Blurt was inspected read-only.

## Executive Assessment

Blurt is close to a pleasant local photoblog UI, but it is not yet a reliable Pixelfed-facing photoblog when paired with current FOSSE defaults.

The largest blocker is not image upload or alt text. Blurt already has a four-image compose affordance, per-image alt/caption editing, ordered attachment parenting, and hashtag-to-WordPress-tag extraction. The blocker is the publication boundary: Blurt stores uploaded photos as child attachments (`post_parent`) and renders them from theme helpers, while the bundled ActivityPub transformer builds federated attachments from featured images, enclosures, block media, and inline HTML images. In the inspected code path it does not query child attachments by `post_parent`. A Blurt post composed through the theme can therefore look photo-first locally while federating to Pixelfed as text-only.

The second blocker is object type. Blurt auto-generates a `post_title` from the body, and neither Blurt's federation toggle nor FOSSE onboarding sets `fosse_object_type=note`. With ActivityPub's default `wordpress-post-format` mode, titled posts without a post format become `Article`, not `Note`. Pixelfed's own ActivityPub docs say Create transforms `Note` and `Question` objects into statuses and its photo examples are `Create.Note` objects with `attachment` arrays. A Pixelfed-ready mode should force `Note` for Blurt-style posts, or otherwise ensure Blurt posts are short-form by post format or empty title.

## Photoblogger Workflow Assessment

### Setup

- Blurt exposes a Tools section that says enabled posts publish to "the Fediverse (Mastodon, Pixelfed, etc.)" and shows only an enable toggle plus the current handle (`/Users/kraft/code/wpcom-a8c-themes/blurt/functions.php:793-853`).
- The toggle is currently gated to two test hostnames (`testblurt.wordpress.com`, `jehervestreamofconsciousness.wordpress.com`), so a normal site owner cannot turn this on without code/platform intervention (`functions.php:501-507`).
- When toggled on, Blurt sets only `activitypub_use_hashtags=1`, `activitypub_max_image_attachments=4`, `activitypub_auto_approve_reactions=0`, and a default blog identifier. It does not set `activitypub_actor_mode`, `activitypub_support_post_types`, `activitypub_object_type`, `fosse_object_type`, or content-warning defaults (`functions.php:525-537`).
- FOSSE's own setup surface exposes post types and actor mode, and its AP provider panel shows handles plus a link to advanced ActivityPub settings. It does not expose object type, media behavior, alt text, content warnings, or Pixelfed-specific readiness (`src/Admin/templates/setup-page.php:54-90`, `src/Admin/class-ap-provider.php:118-186`).
- FOSSE's onboarding copy asks "What do you want to share?" but the control is only public post-type checkboxes with a future-posts hint. It does not distinguish posts from media attachments, warn about the `attachment` post type, or offer a photoblog/Pixelfed mode (`src/Admin/class-onboarding-wizard.php:1116-1208`).

### Compose And Local Display

- Blurt's global compose dialog supports up to four uploaded images via `data-max-images="4"` and a hidden `blurt_images` field (`/Users/kraft/code/wpcom-a8c-themes/blurt/footer.php:81-105`).
- The client upload path accepts `image/*`, uploads immediately to `blurt_upload_image`, stores IDs in a live attachment list, and includes the IDs on compose submit (`js/blurt.js:2387-2459`, `js/blurt.js:2254-2282`).
- Blurt has a real per-image "ALT" panel with alt text and caption fields. Saving calls `blurt_update_attachment_meta`, which writes `_wp_attachment_image_alt` and `post_excerpt` (`js/blurt.js:2471-2577`, `functions.php:4255-4293`). The upload endpoint can also save alt text if supplied (`functions.php:4184-4250`).
- Photo-only posting is blocked today. The client submit handler returns early on empty text (`js/blurt.js:2224-2229`), and the server compose endpoint also rejects empty `blurt_content` (`functions.php:2379-2390`).
- Blurt creates a normal published post with content and no explicit post type/title; title generation is centralized in `blurt_set_auto_post_title`, which trims the post body to an eight-word title (`functions.php:2398-2406`, `functions.php:3477-3503`).
- Uploaded images are attached by setting each media item's `post_parent` and `menu_order`; no gallery block, image block, inline image HTML, enclosure, featured image, or ActivityPub meta is written in the compose path (`functions.php:4295-4344`).
- Blurt renders local post media from `get_attached_media()` plus any featured image, capped at four, so the local UI can show images even when the post body has no image markup (`functions.php:4384-4426`, `functions.php:2221-2237`).
- The local gallery renders `alt` on the `<img>` and displays caption, falling back to alt text when no caption exists (`functions.php:3952-3988`).
- Blurt extracts hashtags from post content and assigns them as WordPress `post_tag` terms on save, which lines up with ActivityPub's tag generation (`functions.php:1043-1079`).

### ActivityPub / Pixelfed Wire Shape

- FOSSE has the right primitive for Pixelfed-like note publishing: `fosse_object_type=note` forces Atmosphere short-form and ActivityPub `Note` through `activitypub_post_object_type` (`src/class-object-type.php:10-20`, `src/class-object-type.php:50-93`).
- That primitive is hidden. The SDD explicitly says any UI for `fosse_object_type` is out of scope for the short-form epic (`sdd/bluesky-native-publishing/spec.md:171-181`), and the e2e test sets `fosse_object_type=note` explicitly through a test endpoint rather than exercising real settings UI (`tests/e2e/short-form-facets.spec.ts:38-59`).
- ActivityPub's default discriminator returns `Article` for titled posts with no post format, and `Note` for untitled posts or posts with any post format (`bundled/activitypub/includes/transformer/class-post.php:453-483`). Blurt's generated titles therefore push normal compose posts toward `Article` unless FOSSE note mode or a post format intervenes.
- ActivityPub attachments are assembled from featured image, enclosures, block media, or inline HTML images, then filtered and transformed (`bundled/activitypub/includes/transformer/class-post.php:356-443`). Blurt's parented child attachments do not match those sources.
- ActivityPub's HTML parser can derive attachment IDs and alt text from inline `<img>` tags (`bundled/activitypub/includes/transformer/class-base.php:423-497`). Its block parser can derive media IDs from `core/image`, `core/cover`, Jetpack slideshow/tiled-gallery, and related blocks (`bundled/activitypub/includes/transformer/class-post.php:904-1032`). This makes a theme-side "write image/gallery blocks into post_content" bridge plausible.
- ActivityPub transforms image attachments as `type: Image`, `mediaType`, `url`, and `name` from block/HTML alt or `_wp_attachment_image_alt` (`bundled/activitypub/includes/transformer/class-base.php:506-590`). Blurt's alt data is in the correct place once the attachment is included.
- ActivityPub emits WordPress post tags as ActivityPub `Hashtag` objects (`bundled/activitypub/includes/transformer/class-post.php:509-535`), so Blurt's hashtag auto-tagging should help Pixelfed discovery.
- ActivityPub supports sensitive media/content warnings through `activitypub_content_warning`: when present it sets `sensitive=true` and `summary` to the warning (`bundled/activitypub/includes/transformer/class-post.php:92-105`, `bundled/activitypub/includes/functions-post.php:334-346`). Blurt and FOSSE do not surface that field in the photoblog flow.
- The ActivityPub default max image attachment count is 4 (`bundled/activitypub/includes/constants.php:10-17`), and Blurt sets `activitypub_max_image_attachments=4` when federation is enabled (`functions.php:525-528`). This aligns with Pixelfed's default `MAX_ALBUM_LENGTH` of 4 in its current `dev` config.

### Pixelfed Compatibility Assumptions

- Pixelfed's official ActivityPub docs say `Create` transforms `Note` and `Question` objects into status models, and its Create.Note example uses a `Note` object with `summary`, `content`, `sensitive`, `attachment`, and `tag` fields. Source: https://pixelfed.github.io/docs-next/spec/ActivityPub.html
- The documented Pixelfed photo examples use `attachment` arrays containing `Image` objects with `mediaType`, `url`, and `name` for alt text. Source: https://pixelfed.github.io/docs-next/spec/ActivityPub.html
- Pixelfed documents the `sensitive` extension and says associated media are concealed by default when sensitive is set, with `summary` acting as the warning/collapse text. Source: https://pixelfed.github.io/docs-next/spec/ActivityPub.html
- Pixelfed's current `dev` config defaults `max_album_length` to `MAX_ALBUM_LENGTH` or 4, and defines `max_altext_length` from `PF_MEDIA_MAX_ALTTEXT_LENGTH` or 1000. Source: https://raw.githubusercontent.com/pixelfed/pixelfed/dev/config/pixelfed.php
- Not verified in this audit: live ingestion against a Pixelfed instance, remote media fetch behavior for WordPress/Photon/CDN URLs, exact handling of Article objects with image attachments, update/delete propagation, and whether all deployed Pixelfed versions match the `docs-next` examples.

### Bluesky / Atmosphere Side Effect

Photoblogging to Pixelfed is mostly ActivityPub work, but Blurt + FOSSE also implies Bluesky if Atmosphere is active. Current bundled Atmosphere does not emit `app.bsky.embed.images`: short-form posts have text and no embed, and long-form posts use `app.bsky.embed.external` with at most a thumbnail (`bundled/atmosphere/includes/transformer/class-post.php:112-160`, `bundled/atmosphere/includes/transformer/class-post.php:255-320`). The SDD also marks `app.bsky.embed.images` as out of scope and states short-form posts with images publish with just text today (`sdd/bluesky-native-publishing/requirements.md:39-48`).

## Missing Product Surfaces

1. **Pixelfed-ready publishing mode.** A site owner needs a clear setting that says Blurt/photo posts federate as short-form `Note` objects with image attachments. Today `fosse_object_type=note` exists but is hidden, and Blurt does not set it.
2. **AP-visible gallery bridge.** Blurt parented-media galleries need to become ActivityPub attachments through one of: inserting image/gallery blocks, adding enclosures, setting featured/media meta intentionally, or hooking `activitypub_attachment_ids`.
3. **Photo-only posts.** Local compose and server validation require body text. A photoblog should allow image-only posts, with optional caption/description.
4. **Alt text prompting and publish readiness.** Blurt has alt UI, but posting does not require or warn on missing alt. A Pixelfed-oriented flow should at least show missing-alt state before publish.
5. **Sensitive media / content warning.** The underlying ActivityPub meta exists, but neither Blurt compose nor FOSSE setup exposes it.
6. **Federated preview / diagnostics.** There is no product surface showing the outgoing ActivityPub object type, attachment count/order, alt text, tags, or sensitive flag before publishing.
7. **Attachment order contract.** Blurt preserves order with `menu_order`, but ActivityPub's current extraction cannot see that order because it does not query parented attachments. Any bridge must preserve the compose order end-to-end.
8. **Pixelfed-oriented setup copy.** Current FOSSE setup is network-agnostic and post-type-oriented. A photoblogger needs plain-language guidance: "Posts" are the publish surface; do not enable `attachment` post type unless you intend every media-library upload to federate separately. ActivityPub's own helper warns about `attachment`; FOSSE's generic post-type list does not (`bundled/activitypub/includes/functions-post.php:195-203`, `src/Admin/templates/setup-page.php:63-76`).
9. **Media federation tests.** FOSSE tests cover object-type projection and Bluesky short-form facets, but I found no FOSSE test that publishes a post with image attachments and asserts ActivityPub attachment shape, alt text, order, or sensitive metadata.

## Implementation Difficulty By Layer

| Layer | Best fit work | Difficulty | Notes |
| --- | --- | --- | --- |
| Blurt theme extension | Remove/relax test-site allowlist; let image-only posts submit; keep or improve alt/caption UI; set `fosse_object_type=note` when enabling federation; write gallery/image blocks or hook ActivityPub attachment IDs from `blurt_get_post_media()`; optionally warn on missing alt. | Medium | Fastest path to a working Blurt photoblog because the theme owns compose, upload, alt UI, gallery order, and the federation toggle. The risk is coupling theme code directly to ActivityPub/FOSSE internals. |
| FOSSE plugin | Add a visible object-type/photoblog mode; expose media cap and content-warning fields or route users to the right AP controls; add ActivityPub preview/diagnostic checks; add FOSSE tests for media attachments and alt text. | Medium | FOSSE owns cross-network projection and setup, so it is the right place for product posture. It should avoid Blurt-specific storage assumptions unless this becomes an explicit Blurt integration. |
| Upstream ActivityPub | Support child attachments parented to a post, or provide a documented filter/helper recipe for themes that store galleries as parented media; preserve `menu_order`; keep existing alt extraction; possibly improve admin affordance for content warnings and media previews. | Medium | The parented-attachment gap is post-type/theme-agnostic WordPress correctness, so it is a good upstream candidate. Needs care to avoid unexpectedly federating media libraries where `post_parent` was not intended as gallery membership. |
| Upstream Atmosphere | Add native `app.bsky.embed.images` support for short-form posts with attachments, including blob upload, alt text, count limits, and fallback behavior. | Medium to high | Not needed for Pixelfed, but needed for a coherent cross-network photoblog where Blurt images also land as images on Bluesky. Current Atmosphere only emits text for short form and external cards for long form. |
| Pixelfed compatibility validation | Run live Create.Note ingestion tests against at least one current Pixelfed instance/version; verify image URL fetching, alt display, hashtag indexing, sensitive media behavior, update/delete semantics, and max attachment count. | Medium | Docs strongly suggest the desired wire shape, but compatibility should be treated as an assumption until tested against real Pixelfed deployments. |

## Practical Path To A First Working Photoblog

1. In Blurt, make federation enablement set `fosse_object_type=note` and keep `activitypub_max_image_attachments=4`.
2. Bridge Blurt parented media into ActivityPub attachment extraction. The least invasive first pass is a Blurt/FOSSE compatibility hook on `activitypub_attachment_ids` that appends `blurt_get_post_media()` in `menu_order` order for Blurt-authored posts. The longer-lived fix is upstream ActivityPub support for child attachments.
3. Allow image-only compose posts. If the post body is empty, create an empty-note photo post without an auto-generated title or force Note mode so Pixelfed sees the attachments as the primary object.
4. Keep Blurt's alt/caption editor, but add a missing-alt warning before publish and make sure edits happen before the ActivityPub publish transition.
5. Add a CW/sensitive control that writes `activitypub_content_warning`.
6. Add a local test that creates a Blurt-style post with two parented image attachments and asserts the outgoing ActivityPub object is `Note`, has two ordered `Image` attachments, preserves `name` from alt text, emits `Hashtag` tags, and sets `sensitive/summary` when requested.

