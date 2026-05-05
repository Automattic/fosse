# Unified Homepage / Display - Requirements

## Goal

Build the public display layer for FOSSE's "your home on the web" promise: a homepage stream that looks like the person/site, not like a protocol plugin. The stream combines long-form posts, short-form notes, and photo-forward posts in one chronological surface, while single posts surface social reactions and replies from ActivityPub and Bluesky without exposing bundled-plugin branding.

Linear:

- [DOTCOM-16797](https://linear.app/a8c/issue/DOTCOM-16797) - 5. Unified homepage / display
- [DOTCOM-16818](https://linear.app/a8c/issue/DOTCOM-16818) - Unified homepage stream
- [DOTCOM-16819](https://linear.app/a8c/issue/DOTCOM-16819) - Single-post reactions display
- [DOTCOM-16820](https://linear.app/a8c/issue/DOTCOM-16820) - Visual consistency

## Requirements

1. **One chronological homepage stream.** The display queries standard WordPress `post` posts in reverse chronological order and renders long-form posts, short-form notes, and photo-forward posts as siblings in the same list.
2. **No new content type.** Do not introduce a CPT. FOSSE's content model remains standard WP posts. Short notes are standard posts with `post_format=status`; photos are standard image attachments associated with those same posts.
3. **Long-form posts stay recognizable as articles.** Titled posts without `post_format=status` render with title, permalink, date, excerpt, and optional attached/featured media.
4. **Short-form notes stay note-like.** Posts with `post_format=status` and untitled posts render body-first, without forcing an article-card treatment or title placeholder.
5. **Photos belong to the post.** Featured images and attached image media render inside the stream item for any post shape. Photo-forward posts get a visual treatment that feels equal to notes and long-form posts, but still link to the canonical single post.
6. **Single-post reactions are visible.** Like and repost counts from both ActivityPub and Bluesky show on single posts via the completed `sdd/unified-reactions-display/` work. The visible label must be "Social Reactions", not "Fediverse Reactions".
7. **Single-post replies are visible.** ActivityPub and Bluesky replies stored as approved WordPress comments (`comment_type='comment'`, protocol metadata distinguishing source) appear in the site's normal single-post comments area. FOSSE verifies this path and avoids creating a parallel comments UI.
8. **Coherent default styling.** Notes, photos, and long-form posts share one FOSSE visual system: spacing, borders, metadata, media treatment, and link treatment should feel related. The design must be restrained and theme-aware, using WordPress/theme CSS variables where available.
9. **Plugin-appropriate placement.** FOSSE must not replace the active theme's homepage or single-post template. The homepage stream is an insertable block that site owners can place on a page or template. Single-post reactions use block hooks/standard comments rather than a theme override.
10. **No leaky bundled-plugin UI.** Public labels must say FOSSE/social-web concepts, not ActivityPub/Bluesky implementation details. Internal DOM classes from bundled blocks are acceptable only where they are not user-facing.
11. **Upstream-first for generic fixes.** Generic bugs in ActivityPub reaction blocks, reply rendering, comment registration, or block hook behavior go upstream to `wordpress-activitypub` or `wordpress-atmosphere`. FOSSE owns only the FOSSE-shaped display composition and wording.

## Constraints

- FOSSE is a WordPress plugin, not a theme. It cannot own full page chrome, typography, navigation, archive templates, or comment templates.
- Do not edit `bundled/` by hand. Any generic block/reaction/comment fix needed in a bundled plugin lands upstream, then FOSSE consumes it via `tools/sync-bundled.sh`.
- Preserve the existing PHP and JS toolchain. The repo currently has no `@wordpress/scripts` build step. Any editor script for the v1 block should be small, unbuilt JS using WordPress global packages, or the plan must explicitly justify build tooling.
- PHP remains PHP 8.2+ / WP 6.9+. PHP follows Jetpack PHPCS, tabs, Yoda conditions, `fosse` text domain, and `Automattic\Fosse` namespace.
- Tests must stay deterministic in Playground. E2E tests seed posts, attachments, comments, and reaction rows directly through test-only mu-plugin endpoints rather than relying on live ActivityPub or Bluesky network traffic.

## Out of Scope

- A FOSSE theme or full-site design replacement.
- A composer/posting UI for creating notes/photos. This SDD renders existing standard posts; composer defaults belong to the posting epic.
- A new CPT for notes, photos, replies, or reactions.
- Infinite scroll, client-side hydration, live reaction polling, or personalized timelines.
- Per-network badges such as "via Mastodon" or "via Bluesky" in v1.
- A bespoke comments system. Replies should use WordPress comments unless a later upstream gap proves that impossible.
- Replacing the bundled `activitypub/reactions` block with a FOSSE-owned reactions block. The completed unified reactions display SDD already chose the smaller path.
- Displaying private, password-protected, draft, scheduled, or non-public posts in the public stream.
- Attachment-heavy gallery management. V1 renders attached images; editing/reordering galleries remains WordPress media behavior.

## Dependencies

- `sdd/unified-reactions-display/` is a hard dependency for DOTCOM-16819. It verified that the bundled `activitypub/reactions` block aggregates `protocol='activitypub'` and `protocol='atproto'` rows and relabeled the block metadata to "Social Reactions".
- The homepage stream depends on the existing post-shape work from the Bluesky native publishing SDD: standard posts plus `post_format=status` are the content model. This SDD does not revisit that decision.

## Related Code / Patterns Found

- `src/class-reactions-label.php` - FOSSE-side relabel for the bundled `activitypub/reactions` block. Current implementation rewrites registered block metadata and intentionally does not rewrite the legacy render fallback.
- `tests/e2e/reactions-display.spec.ts` and `tests/e2e/mu-plugins/fosse-reactions-seed.php` - existing deterministic e2e pattern for seeding reaction comments and verifying unified Social Reactions behavior.
- `bundled/activitypub/build/reactions/block.json` - bundled reactions block includes `blockHooks: { "core/post-content": "after" }`, so single-post reactions are already auto-injected after post content.
- `bundled/activitypub/build/reactions/render.php` - protocol-agnostic reaction query; legacy fallback title still contains "Fediverse Reactions".
- `bundled/activitypub/build/posts-and-replies/` - upstream AP block pattern for a small server-rendered block and scoped CSS.
- `src/class-object-type.php`, `src/class-post-types.php`, `src/class-long-form-strategy.php` - FOSSE projector/registration pattern: small static class, `register()` method, `fosse.php` `init` hook with `class_exists` guard.
- `tests/e2e/blueprint.json` - mounts test-only mu-plugins into Playground and sets `FOSSE_E2E`.
- `.phpcs.xml.dist` - PHPCS checks `fosse.php`, `src/`, and `tests/php/`; bundled code is excluded.

## Open Questions Resolved

- **Block, shortcode, template part, or theme hook?** Resolved in `spec.md`: v1 ships a dynamic block, `fosse/unified-homepage-stream`. A block is the best plugin-shaped surface because it can be placed in the Site Editor or page editor without taking over the theme.
- **Should the homepage query multiple post types?** No. Query standard `post` only. No CPT and no union query.
- **Should reactions render in the homepage stream?** Not in v1. The stream links to canonical single posts. DOTCOM-16819 is single-post display.
- **Should FOSSE create a comments UI for replies?** No. AP and Bluesky replies are normal approved comments and should display through the theme's comments area. FOSSE adds deterministic verification and only fixes gaps if the standard path fails.
