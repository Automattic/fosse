# Spec: Unified Homepage / Display

## Goal

Ship a FOSSE-owned homepage stream block that displays standard WordPress posts as a unified social-web stream: long-form articles, short notes, and photo-forward posts in one chronological list. On single posts, rely on the completed unified reactions work and WordPress comments so AP + Bluesky likes, reposts, and replies are visible without replacing the theme.

## Requirements Summary

- Unified chronological stream of standard WP `post` posts.
- Short notes use `post_format=status`; photos are attached/featured images on the same posts.
- No CPT, no theme replacement, no bundled UI leaks.
- Single posts show "Social Reactions" and approved AP/Bluesky replies.
- Visual defaults make notes, photos, and long-form posts feel like siblings.
- Deterministic PHPUnit and Playwright coverage.

## Chosen Approach

**Ship a dynamic block: `fosse/unified-homepage-stream`.**

The block is server-rendered by PHP and registered by a small FOSSE class. Site owners can insert it on the front page, a block theme template, or any page. It queries standard posts, classifies each result for display, renders theme-aware markup, and ships scoped CSS. It does not replace the homepage template or inject itself globally.

### Why This Is the Right v1 Surface

- **Plugin-appropriate.** A block gives FOSSE a real public surface without becoming a theme. The active theme still owns layout chrome, navigation, comments, template hierarchy, and typography.
- **Site-editor friendly.** Users can place the stream where they want. A shortcode is less discoverable in block themes, and a template hook/filter would either be invisible or too theme-dependent.
- **No new content model.** The block can query ordinary posts and inspect `post_format` plus attachments at render time. No CPT or migration.
- **Small build footprint.** The editor UI can be a tiny unbuilt script using WordPress globals (`wp.blocks`, `wp.element`, `wp.components`, `wp.serverSideRender`). No `@wordpress/scripts` build step is required for v1.

### Alternatives Considered

- **Shortcode.** Easy to implement, but it is a weaker Site Editor experience and pushes users toward manual syntax. Rejected as the primary v1 surface.
- **Template-part helper.** Too theme-shaped for FOSSE. It would work only for themes that opt into the helper intentionally and gives users no inserter UX.
- **Theme hook/filter.** Risks surprising users by changing their homepage automatically and depends on theme-specific hook surfaces. Rejected.
- **FOSSE theme or bundled template override.** Violates the core constraint. FOSSE is a plugin.
- **Core Query Loop variation only.** Attractive, but Query Loop does not give enough control over note/photo/article sibling rendering and image-attachment treatment without more theme/block complexity. A FOSSE dynamic block is clearer for v1.

## Technical Details

### Architecture

```
fosse.php
  -> init
     -> Automattic\Fosse\Homepage_Stream::register()
        -> wp_register_script( editor handle )
        -> register_block_type_from_metadata( src/Blocks/unified-homepage-stream )
           -> render_callback: Homepage_Stream::render()

Homepage_Stream::render()
  -> build_query_args( $attributes )
  -> WP_Query
  -> for each WP_Post:
       classify_post()
       get_post_images()
       render_item()
```

The block owns only its own markup. Single-post reactions remain separate:

- `activitypub/reactions` is already block-hooked after `core/post-content`.
- `Automattic\Fosse\Reactions_Label` relabels the block metadata to "Social Reactions".
- This SDD adds a narrow rendered-output guard for the legacy fallback so the auto-hooked block cannot expose "Fediverse Reactions" on a real single post.
- Replies remain approved WordPress comments rendered by the active theme's comments block/template.

### Block Contract

Block name: `fosse/unified-homepage-stream`

Block title: `Social Web Stream`

Attributes:

| Attribute | Type | Default | Purpose |
| --- | --- | --- | --- |
| `postsPerPage` | number | `10` | Number of stream items to render. Clamped to `1..50`. |
| `showExcerpt` | boolean | `true` | Show generated excerpt for article-shaped posts. |
| `showMedia` | boolean | `true` | Show featured/attached images. |
| `mediaLimit` | number | `4` | Maximum images per item. Clamped to `0..6`. |
| `showDates` | boolean | `true` | Show published date/permalink metadata. |

No per-network attributes in v1. The block is about the site's public stream, not protocol filtering.

### Query Semantics

The block uses `WP_Query` with:

- `post_type => 'post'`
- `post_status => 'publish'`
- `orderby => 'date'`
- `order => 'DESC'`
- `posts_per_page => clamped postsPerPage`
- `ignore_sticky_posts => true`
- `no_found_rows => true`

There is no tax query for post formats because the stream intentionally includes all published posts in one chronological list. Classification happens after query.

### Display Classification

Each item is one standard `WP_Post`. FOSSE classifies display shape without changing storage:

1. **Photo-forward** when `post_format=image` or the post has one or more featured/attached image media and the rendered plain text is at most 280 characters, which is short enough to behave like a caption.
2. **Note** when `post_format=status` or the post has no title.
3. **Article** for the remaining titled posts.

Attached images can appear on every shape. "Photo-forward" is a display treatment, not a post type.

This intentionally keeps v1 narrow:

- `status` is the note signal because DOTCOM-16818 names it explicitly and future composer work can set it consistently.
- Other WordPress post formats can still render, but only `image` gets a special photo-forward treatment in v1.
- If publishing logic treats additional post formats as short-form, that does not require the homepage display to visually specialize every format immediately.

### Markup Shape

The rendered wrapper uses the normal block wrapper attributes and FOSSE-scoped classes:

```html
<div class="wp-block-fosse-unified-homepage-stream fosse-stream">
	<article class="fosse-stream__item is-note|is-article|is-photo">
		<a class="fosse-stream__permalink" href="...">
			<time class="fosse-stream__date" datetime="...">...</time>
		</a>
		<div class="fosse-stream__media">...</div>
		<h2 class="fosse-stream__title">...</h2>
		<div class="fosse-stream__content">...</div>
	</article>
</div>
```

Rules:

- Article items render a linked title and excerpt/content preview.
- Note items render body text first and never invent a title.
- Photo-forward items render media first, then caption/body text when present.
- Every item links to the canonical single post.
- Image output uses WordPress attachment APIs (`wp_get_attachment_image`) so alt text, responsive sizes, and lazy loading stay native.
- User-provided content is escaped through WordPress helpers and rendered with the same caution as excerpts. Do not echo raw post content.

### Visual System

The CSS is scoped to `.wp-block-fosse-unified-homepage-stream` / `.fosse-stream`.

The default style should be theme-aware rather than brand-heavy:

- Use `currentColor`, `--wp--preset--color--contrast`, `--wp--preset--color--base`, and `--wp--style--global--content-size` where available.
- Use a single shared item container rhythm for all shapes.
- Keep borders and separators quiet; no large marketing cards or full theme replacement.
- Use consistent metadata placement across notes, photos, and articles.
- Media grids use fixed aspect-ratio constraints so images do not shift layout.
- Mobile layout must not overflow at 390px width.

### Single-Post Reactions and Replies

DOTCOM-16819 is implemented as composition and verification, not as a new FOSSE reactions block:

- Likes/reposts: use `activitypub/reactions`, already verified as protocol-agnostic in `sdd/unified-reactions-display/`.
- Labeling: visible UI must say "Social Reactions". Extend `Reactions_Label` with a block-specific `render_block_activitypub/reactions` filter to rewrite only the legacy fallback title inside that rendered block output.
- Replies: AP and Bluesky replies are approved WordPress comments. They surface wherever the theme renders comments. FOSSE adds e2e coverage that seeds one AP reply and one Bluesky reply and verifies both appear on the single post under the default Playground theme.

FOSSE does not add a parallel comments list in v1. If replies fail to render because a bundled plugin stores them with nonstandard `comment_type` or filters them out generically, the fix belongs upstream.

### File Changes

| File | Change Type | Description |
| --- | --- | --- |
| `src/class-homepage-stream.php` | new | Registers and renders `fosse/unified-homepage-stream`; contains query, classification, image lookup, and rendering helpers. |
| `src/Blocks/unified-homepage-stream/block.json` | new | Block metadata. |
| `src/Blocks/unified-homepage-stream/editor.js` | new | Small unbuilt editor UI using WordPress globals and `ServerSideRender`. |
| `src/Blocks/unified-homepage-stream/style.css` | new | Frontend/editor block styles scoped to the stream block. |
| `fosse.php` | modify | Register `Homepage_Stream::register()` on `init` with existing `class_exists` guard pattern. |
| `src/class-reactions-label.php` | modify | Add a narrow rendered-output fallback rewrite for the auto-hooked reactions block's legacy fallback title. |
| `tests/php/Homepage_StreamTest.php` | new | PHPUnit coverage for query args, attribute clamping, classification, image lookup, rendering branches, and registration idempotency. |
| `tests/php/Reactions_LabelTest.php` | modify | Add coverage for the rendered-output fallback rewrite added for single posts. |
| `tests/e2e/mu-plugins/fosse-homepage-stream-seed.php` | new | Test-only REST seed endpoint for homepage posts, attachments, replies, and reactions. |
| `tests/e2e/homepage-stream.spec.ts` | new | Playwright coverage for homepage stream and single-post reactions/replies. |
| `tests/e2e/blueprint.json` | modify | Copy the new homepage stream seed mu-plugin into `wp-content/mu-plugins/`. |

## Verification Strategy

PHPUnit:

- Query args include only public standard posts and no CPT.
- `postsPerPage` and `mediaLimit` are clamped.
- `post_format=status` classifies as note.
- Titled posts without status/image classify as article.
- Image-format or short caption + attached image classifies as photo-forward.
- Featured image and attached images are deduplicated and ordered predictably.
- Render output uses escaped URLs/text and expected FOSSE classes.
- Block registration is idempotent.
- Reactions fallback rewrite affects only `activitypub/reactions`.

Playwright:

- Seed one article, one status note, one photo-forward post, and one status note with image attachment.
- Insert/use a page containing `<!-- wp:fosse/unified-homepage-stream /-->`.
- Assert the stream renders all seeded posts in reverse chronological order.
- Assert note, article, and photo-forward items share the same base class and have distinct shape classes.
- Assert no public homepage text says "ActivityPub", "Fediverse", or "Bluesky".
- Visit a seeded single post and assert "Social Reactions" appears, "Fediverse Reactions" does not, AP + Bluesky like/repost counts aggregate, and AP + Bluesky replies appear as comments.
- Check desktop and 390px mobile widths for no horizontal overflow and visible media.

## Upstream Policy

FOSSE owns:

- the homepage stream block,
- FOSSE-scoped display styling,
- FOSSE wording such as "Social Web Stream" and "Social Reactions",
- tests proving the bundled backends integrate cleanly in FOSSE.

Upstream owns:

- generic reaction query behavior,
- generic comment/reply storage,
- generic ActivityPub block bugs,
- generic Atmosphere reaction/reply sync bugs,
- bundled block APIs that would help any standalone AP/Atmosphere site.

## Known Risks

- The active theme can omit comments on single posts. FOSSE should document that replies appear through the theme's comments area; it should not inject a second comments UI to compensate.
- The AP reactions block currently has a legacy fallback title in its render path. This SDD explicitly tests the single-post auto-hook path so a visible "Fediverse Reactions" leak is caught.
- A no-build editor script is intentionally limited. If the block editor UI grows beyond simple controls and server preview, a separate build-tooling decision should be made rather than accreting complex unbuilt JS.
- Photo-forward classification is heuristic. The storage model stays simple, and future composer UI can make the author's intent clearer without requiring a migration.
