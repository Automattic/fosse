<?php
/**
 * Photo-post detection + ActivityPub federation-shape projector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

use WP_Post;

/**
 * Detects "this WordPress post is a photo post" and projects the
 * detection onto ActivityPub's transformer filters so the outbound
 * activity matches what Pixelfed (and IG-style photo clients) render
 * natively in a photo grid.
 *
 * External AP consumers (Pixelfed, Mastodon, Misskey, …) have no
 * visibility into WordPress internals — CPTs, taxonomies, block
 * structure — so the photo-shape decision has to be made on the WP
 * side and projected onto the AP envelope. Pixelfed's "this is a
 * photo" gate is purely "does the Note have valid image attachments?"
 * (`pixelfed/app/Util/ActivityPub/Helpers.php::verifyAttachments`),
 * and the IG-feel rendering wants `type: Note` with a caption-only
 * `content` plus dimensionally-complete `attachment[]` entries.
 *
 * Detection (`is_photo_post()`) fires in three stages so callers can
 * intervene at either boundary:
 *
 *   1. `fosse_pre_is_photo_post` short-circuit. Return a non-null
 *      bool to hardwire the decision before any block parsing
 *      happens (the recipe for sites that maintain a custom photo
 *      CPT — they already know what's a photo and don't need FOSSE
 *      to look at blocks).
 *   2. Built-in detection: post format `image`/`gallery`, or block
 *      content shaped like "one image-like block plus at most one
 *      paragraph," or a featured image with an otherwise empty
 *      body. Title presence is intentionally not a disqualifier —
 *      "headline + photo + caption" is a common photo-post shape.
 *   3. `fosse_is_photo_post` final filter. Mutate or downgrade the
 *      detection result.
 *
 * When the discriminator returns true, the AP-side hooks fire:
 *
 *   - `activitypub_post_object_type` → force `Note` (Pixelfed only
 *     renders Notes in photo timelines; Articles drop into the
 *     long-form reader path).
 *   - `activitypub_the_content` → strip the image / gallery /
 *     featured-image block markup so the body is caption-only.
 *     Pixelfed accepts HTML in `content`, but the photo-grid feel
 *     wants a short caption, not an article that happens to lead
 *     with an image.
 *   - `activitypub_attachment` → add `width`/`height` to every
 *     image attachment from `wp_get_attachment_metadata()`. Fires
 *     unconditionally (not gated on `is_photo_post()`): dimensions
 *     are universally useful — Mastodon uses them for layout,
 *     Pixelfed enforces them in `verifyAttachments`, and bundled AP
 *     already emits them for video/audio. Closing the gap for
 *     images here is a strict superset.
 *
 * Out of scope (separate tickets):
 *   - `blurhash`: needs a PHP encoder dep + background job at
 *     upload time, can't run synchronously on the federation hot
 *     path.
 *   - AT-protocol `app.bsky.embed.images`: Atmosphere today only
 *     emits `app.bsky.embed.external` link cards. Switching photo
 *     posts to native image embeds is its own project (uploadBlob,
 *     blob caching, 4-image cap with overflow strategy).
 *   - `sensitive` / `summary` (content warnings): needs UX decision
 *     on the source of truth — taxonomy, postmeta, or per-network.
 */
class Photo_Post {

	/**
	 * Block names that count as image-like content for the body-shape
	 * detector. `core/post-featured-image` is included because the
	 * Featured Image block resolves to the post thumbnail and federates
	 * the same way as a `core/image` — from Pixelfed's point of view
	 * they're indistinguishable.
	 *
	 * @var array<int, string>
	 */
	private const IMAGE_LIKE_BLOCKS = array(
		'core/image',
		'core/gallery',
		'core/post-featured-image',
	);

	/**
	 * Block names that don't count as substantive content when deciding
	 * "is the body just image + caption?". Used to ignore structural
	 * filler so a post with `<image><spacer><paragraph>` still detects
	 * the same as `<image><paragraph>`.
	 *
	 * @var array<int, string>
	 */
	private const IGNORABLE_BLOCKS = array(
		'core/spacer',
		'core/separator',
	);

	/**
	 * Per-request memo of `is_photo_post()` results.
	 *
	 * Detection runs `parse_blocks()` against the post body and can be
	 * called from several hooks during a single federation pass (object
	 * type, content stripping, attachment dimension enrichment). Keyed
	 * by post ID; cleared between requests because the cache lives on a
	 * static.
	 *
	 * @var array<int, bool>
	 */
	private static array $decision_cache = array();

	/**
	 * Register the AP transformer hooks. Safe to call more than once
	 * per request — WordPress dedupes identical callable-as-array
	 * registrations.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'after_setup_theme', array( self::class, 'register_post_format_support' ), 99 );
		\add_filter( 'activitypub_post_object_type', array( self::class, 'filter_object_type' ), 10, 2 );
		\add_filter( 'activitypub_the_content', array( self::class, 'filter_content' ), 10, 2 );
		\add_filter( 'activitypub_attachment', array( self::class, 'filter_attachment_dimensions' ), 10, 2 );
	}

	/**
	 * Ensure the `post` post type supports post formats so
	 * `get_post_format()` doesn't short-circuit before consulting the
	 * `post_format` taxonomy term — Rule 1 of `detect()` depends on
	 * that term being readable. Block themes increasingly skip
	 * `add_theme_support( 'post-formats', … )` in their setup, which
	 * silently disables `get_post_format()` even when the term is set.
	 *
	 * Runs at `after_setup_theme` priority 99 so the active theme has
	 * already had its chance to declare any post-type / theme support
	 * of its own. `add_post_type_support` is idempotent and additive —
	 * registering it here on top of a theme that already declared it
	 * is a no-op.
	 *
	 * Intentionally narrow: we register the *post-type* support flag
	 * that `get_post_format()` checks, but do NOT call
	 * `add_theme_support( 'post-formats', [...] )` to populate the
	 * Gutenberg format picker's dropdown. Doing so would either
	 * overwrite the theme's curated list or require a brittle merge.
	 * The federation-shape contract only needs the term to be
	 * readable; surfacing format UI for users on opinionated themes
	 * is a separate UX concern.
	 *
	 * @return void
	 */
	public static function register_post_format_support(): void {
		\add_post_type_support( 'post', 'post-formats' );
	}

	/**
	 * Discriminator: should this WP post federate as a photo post?
	 *
	 * Short-circuit order:
	 *
	 *   1. Resolve `$post` to a WP_Post; return false on failure
	 *      (defensive — any non-post input is treated as not-a-photo,
	 *      so a stray hook call can't flip the AP envelope).
	 *   2. Apply `fosse_pre_is_photo_post`. A callback returning a
	 *      bool wins outright; null means "no opinion, run detection."
	 *   3. Run the built-in detector (`detect()`).
	 *   4. Apply `fosse_is_photo_post` to the detected value so
	 *      callers can downgrade or upgrade after the fact.
	 *
	 * Memoized per post id — see {@see self::$decision_cache} for why.
	 *
	 * @param int|WP_Post $post Post ID or `WP_Post`.
	 * @return bool True if the post should federate as a photo post.
	 */
	public static function is_photo_post( $post ): bool {
		$resolved = \get_post( $post );
		if ( ! $resolved instanceof WP_Post ) {
			return false;
		}

		if ( isset( self::$decision_cache[ $resolved->ID ] ) ) {
			return self::$decision_cache[ $resolved->ID ];
		}

		/**
		 * Hardwire the photo-post decision before built-in detection runs.
		 *
		 * Return a bool to short-circuit (`true` to force photo-post
		 * treatment, `false` to suppress it). Return `null` (the
		 * default) to let `Photo_Post::detect()` run the post-format
		 * and block-shape checks. Use this when the site has its own
		 * authoritative signal — e.g. a custom photo CPT, a taxonomy
		 * term, or postmeta set by a composer — that's faster and
		 * more reliable than block introspection.
		 *
		 * @param bool|null $short_circuit Hardwired decision, or null to defer.
		 * @param \WP_Post  $post          The post being evaluated.
		 */
		$pre = \apply_filters( 'fosse_pre_is_photo_post', null, $resolved );
		if ( null !== $pre ) {
			$result = (bool) $pre;
		} else {
			$result = self::detect( $resolved );
		}

		/**
		 * Filter the final photo-post decision after built-in detection.
		 *
		 * Fires after `fosse_pre_is_photo_post` and any built-in
		 * detection. Use this to override a detected positive (e.g.
		 * exclude posts in a specific taxonomy term from being
		 * treated as photo posts) or to upgrade an undetected case
		 * (e.g. a CPT whose body happens to be a custom block FOSSE
		 * doesn't recognize as image-like).
		 *
		 * @param bool     $is_photo Whether the post is a photo post.
		 * @param \WP_Post $post     The post being evaluated.
		 */
		$result = (bool) \apply_filters( 'fosse_is_photo_post', $result, $resolved );

		self::$decision_cache[ $resolved->ID ] = $result;
		return $result;
	}

	/**
	 * Built-in photo-post detection. Runs after the pre-filter
	 * short-circuit declines to answer.
	 *
	 * Returns true when any of:
	 *
	 *   1. The post format is `image` or `gallery`.
	 *   2. The block content boils down to "one image-like block,
	 *      optionally followed by a single paragraph block,"
	 *      ignoring purely structural blocks (spacer, separator).
	 *   3. The post has a featured image and the body has at most
	 *      one paragraph block (so "set featured image, type one
	 *      sentence, hit publish" works without the user having to
	 *      know about Post Formats).
	 *
	 * Rule 2 is the catch-most-cases path for users who don't use
	 * Post Formats at all (modern block-editor flow). Rule 3 handles
	 * the classic-editor / mobile-app flow where the featured image
	 * is the photo and the body is the caption.
	 *
	 * Title presence is intentionally not a disqualifier — a post
	 * shaped like "Headline. Big photo. One-line caption." is still
	 * a photo post; the user explicitly requested this on
	 * `DOTCOM-17143`.
	 *
	 * @param \WP_Post $post The post being evaluated.
	 * @return bool True if the post matches one of the built-in rules.
	 */
	private static function detect( WP_Post $post ): bool {
		$format = \get_post_format( $post );
		if ( 'image' === $format || 'gallery' === $format ) {
			return true;
		}

		$has_thumbnail = \has_post_thumbnail( $post );

		// Empty body + featured image = "Set thumbnail, hit publish"
		// flow (Rule 3 with zero paragraphs). Catch this before the
		// block parser, which returns a single blank-blockName block
		// for empty content and would otherwise classify it as not a
		// photo post.
		if ( $has_thumbnail && '' === \trim( (string) $post->post_content ) ) {
			return true;
		}

		$blocks = \parse_blocks( $post->post_content );
		if ( empty( $blocks ) ) {
			return false;
		}

		$image_count     = 0;
		$paragraph_count = 0;
		$other_count     = 0;

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			// `parse_blocks()` emits a null-blockName entry for any
			// freeform / classic content sandwiched between blocks
			// (or for a post that's entirely classic content). Treat
			// whitespace-only freeform as ignorable; substantive
			// freeform counts as "other" content that disqualifies.
			if ( null === $name ) {
				$inner = \trim( $block['innerHTML'] ?? '' );
				if ( '' === $inner ) {
					continue;
				}
				++$other_count;
				continue;
			}

			if ( \in_array( $name, self::IGNORABLE_BLOCKS, true ) ) {
				continue;
			}

			if ( \in_array( $name, self::IMAGE_LIKE_BLOCKS, true ) ) {
				++$image_count;
				continue;
			}

			if ( 'core/paragraph' === $name ) {
				// An empty paragraph block ("<p></p>") is structural
				// padding from the editor, not a real caption.
				$inner = \trim( \wp_strip_all_tags( $block['innerHTML'] ?? '' ) );
				if ( '' === $inner ) {
					continue;
				}
				++$paragraph_count;
				continue;
			}

			++$other_count;
		}

		if ( $other_count > 0 ) {
			return false;
		}

		// Rule 2: exactly one image-like block, at most one caption paragraph.
		if ( 1 === $image_count && $paragraph_count <= 1 ) {
			return true;
		}

		// Rule 3: featured image + body = at most one paragraph.
		if ( $has_thumbnail && 0 === $image_count && $paragraph_count <= 1 ) {
			return true;
		}

		return false;
	}

	/**
	 * Force `type: "Note"` for photo posts. Hooked on
	 * `activitypub_post_object_type`.
	 *
	 * Pixelfed renders only Notes in its photo timeline; an Article
	 * lands in the long-form reader path regardless of how
	 * dimensionally-complete its attachments are. Mastodon treats
	 * Notes-with-attachments as native toots with media. Forcing
	 * `Note` is therefore the single highest-leverage change for
	 * photo-feel rendering across the AP ecosystem.
	 *
	 * Pass-through for non-photo posts so this filter is a no-op on
	 * everything else (Article / Page choices owned by bundled AP's
	 * own `get_type()` logic remain authoritative).
	 *
	 * @param string $object_type The AP-computed object type.
	 * @param mixed  $post        The post being transformed.
	 * @return string `Note` for photo posts, otherwise pass-through.
	 */
	public static function filter_object_type( $object_type, $post ): string {
		if ( ! $post instanceof WP_Post ) {
			return (string) $object_type;
		}

		if ( ! self::is_photo_post( $post ) ) {
			return (string) $object_type;
		}

		return 'Note';
	}

	/**
	 * Strip image-block markup from the rendered AP content for photo
	 * posts. Hooked on `activitypub_the_content`.
	 *
	 * By the time this filter fires, the body has been through the
	 * full `the_content` chain — blocks are already expanded to HTML.
	 * Image blocks render with stable wrapper classes
	 * (`wp-block-image`, `wp-block-gallery`,
	 * `wp-block-post-featured-image`), so a class-anchored regex pass
	 * is enough to remove them along with any nested `<img>` /
	 * `<figcaption>`. After stripping, we also drop standalone `<img>`
	 * tags as a defensive measure and clean up empty paragraph
	 * wrappers left behind by the editor.
	 *
	 * Why strip at all: Pixelfed accepts HTML in `content` and would
	 * happily render the figure markup inline, but the photo-grid
	 * feel wants a short caption next to the attachment, not an
	 * article that opens with an image and then duplicates that image
	 * via `attachment[]`. Stripping leaves the caption (paragraph
	 * text, headings, etc.) intact for downstream rendering.
	 *
	 * @param string $content The rendered AP content.
	 * @param mixed  $post    The post being transformed.
	 * @return string Content with image-block markup removed when the post is a photo post.
	 */
	public static function filter_content( $content, $post ): string {
		$content = (string) $content;

		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		if ( ! self::is_photo_post( $post ) ) {
			return $content;
		}

		// Drop the entire figure wrapper produced by core image-like blocks,
		// including any nested <img> / <figcaption>.
		$content = (string) \preg_replace(
			'#<figure\b[^>]*class="[^"]*\bwp-block-(?:image|gallery|post-featured-image)\b[^"]*"[^>]*>.*?</figure>#is',
			'',
			$content
		);

		// Defensive: strip any orphan <img> that survived (classic-editor
		// inline images, or themes/plugins that wrap images differently).
		$content = (string) \preg_replace( '#<img\b[^>]*>#is', '', $content );

		// Clean up empty paragraph shells the editor leaves behind once the
		// only child was the image we just removed.
		$content = (string) \preg_replace( '#<p\b[^>]*>\s*</p>#is', '', $content );

		return \trim( $content );
	}

	/**
	 * Add `width` / `height` to image attachments. Hooked on
	 * `activitypub_attachment`.
	 *
	 * Fires unconditionally (NOT gated on `is_photo_post()`) — image
	 * dimensions are useful to every AP receiver: Mastodon uses them
	 * for media-grid layout, Pixelfed enforces them in
	 * `verifyAttachments` (1–5000 pixels per side), and bundled AP
	 * already emits them for video/audio. Adding them for images
	 * closes a gap without changing any other behavior.
	 *
	 * The bundled transformer shapes images like
	 * `{type:"Image", url, mediaType, name?, exifData?}` — we add
	 * `width` / `height` from `wp_get_attachment_metadata()` when
	 * present and skip silently when missing (e.g. external images
	 * with no local attachment ID, or attachments whose metadata
	 * hasn't been generated yet).
	 *
	 * @param array $attachment The AP attachment array.
	 * @param mixed $id         The WP attachment ID being transformed.
	 * @return array The attachment with width/height when available.
	 */
	public static function filter_attachment_dimensions( $attachment, $id ): array {
		if ( ! \is_array( $attachment ) ) {
			return array();
		}

		if ( 'Image' !== ( $attachment['type'] ?? null ) ) {
			return $attachment;
		}

		if ( isset( $attachment['width'] ) && isset( $attachment['height'] ) ) {
			return $attachment;
		}

		if ( ! \is_numeric( $id ) ) {
			return $attachment;
		}

		$meta = \wp_get_attachment_metadata( (int) $id );
		if ( ! \is_array( $meta ) ) {
			return $attachment;
		}

		if ( ! isset( $meta['width'] ) || ! isset( $meta['height'] ) ) {
			return $attachment;
		}

		$attachment['width']  = (int) $meta['width'];
		$attachment['height'] = (int) $meta['height'];

		return $attachment;
	}

	/**
	 * Clear the per-request decision cache.
	 *
	 * Test-only entry point — production never needs to invalidate
	 * mid-request because the cache key is the post id and the post's
	 * shape (post format, content, featured image) is stable across
	 * a single federation pass. Public so test setUp can call it.
	 *
	 * @return void
	 */
	public static function reset_cache_for_testing(): void {
		self::$decision_cache = array();
	}
}
