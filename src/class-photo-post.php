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
		\add_filter( 'activitypub_post_object_type', array( self::class, 'filter_object_type' ), 10, 2 );
		\add_filter( 'activitypub_the_content', array( self::class, 'filter_content' ), 10, 2 );
		\add_filter( 'activitypub_attachment', array( self::class, 'filter_attachment_dimensions' ), 10, 2 );
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
		// Reject obviously-unsupported input BEFORE handing off to
		// `get_post()`. The core helper falls back to
		// `$GLOBALS['post']` whenever its argument is empty — which
		// includes `null`, `0`, `'0'`, `false`, and `''` — so calling
		// it with those values during template rendering would
		// silently resolve to the current loop post instead of
		// returning the "no post" answer the discriminator's
		// contract promises. The numeric branch also rejects zero
		// since `is_numeric(0)` and `is_numeric('0')` are both true
		// but `get_post(0)` triggers the same fallback.
		if ( null === $post || false === $post || '' === $post ) {
			return false;
		}
		if ( $post instanceof WP_Post ) {
			$resolved = $post;
		} elseif ( \is_numeric( $post ) && (int) $post > 0 ) {
			$resolved = \get_post( (int) $post );
		} else {
			return false;
		}
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
		$format        = \get_post_format( $post );
		$has_format    = 'image' === $format || 'gallery' === $format;
		$has_thumbnail = self::has_image_thumbnail( $post );

		// Empty body + featured image = "Set thumbnail, hit publish"
		// flow (Rule 3 with zero paragraphs). Catch this before the
		// block parser, which returns a single blank-blockName block
		// for empty content and would otherwise classify it as not a
		// photo post.
		// Parse the post body the same way bundled AP's
		// `get_block_attachments()` does — directly off
		// `$post->post_content` without unslashing. Deliberately:
		// for non-admin authors (Contributors, multisite Authors
		// lacking `unfiltered_html`), `wp_filter_post_kses` slashes
		// block-attribute JSON in storage, so `parse_blocks()`
		// returns `null` attrs. A detector that unslashed before
		// parsing would find `attrs.id`, classify the post as
		// photo, and force `Note` — but bundled AP would then
		// extract zero attachments from the still-slashed
		// post_content, leaving a caption-only Note with no image.
		// Matching the view AP's extractor uses guarantees the
		// two stay in sync: when AP can't surface an image, FOSSE
		// doesn't either, and the post federates as an article
		// with its image markup intact in the body. Restoring
		// photo treatment for slashed content is upstream work in
		// bundled AP.
		$content = (string) $post->post_content;

		if ( $has_thumbnail && '' === \trim( $content ) ) {
			return true;
		}

		$blocks = \parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return false;
		}

		$image_count              = 0;
		$paragraph_count          = 0;
		$other_count              = 0;
		$unresolvable_image_count = 0;
		// Flattened count of individual images that would actually
		// federate as attachments. `image_count` counts image-like
		// BLOCKS for Rule 2's shape check ("exactly one image-like
		// block"), but `total_image_count` counts gallery children
		// individually so the `max_image_attachments` cap can be
		// compared against what bundled AP will really emit.
		$total_image_count = 0;
		// True when a `core/post-featured-image` block is present in
		// the body. Bundled AP unshifts the post thumbnail into the
		// media list AND extracts the featured-image block via
		// `get_block_attachments`, then dedupes by id — both resolve
		// to the same attachment. Tracking this lets the cap check
		// avoid double-counting one image as two.
		$has_featured_image_block = false;

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			// `parse_blocks()` emits a null-blockName entry for any
			// freeform / classic content sandwiched between blocks
			// (or for a post that's entirely classic content). The
			// mobile WP-app and classic-editor "set featured image,
			// type a one-line caption" flow lands here as a single
			// freeform block whose innerHTML is just `<p>Caption.</p>`
			// (no block-comment wrappers). Rule 3 explicitly targets
			// this shape, so we mirror the paragraph-block heuristic:
			// if the freeform body strips to plain text wrapped at
			// most in `<p>` / `<br>` (no figures, images, headings,
			// lists, tables — the structural tags that signal an
			// article body), count it as one paragraph. Otherwise
			// treat as "other" content that disqualifies.
			if ( null === $name ) {
				$inner_html = $block['innerHTML'] ?? '';
				$inner_text = \trim( \wp_strip_all_tags( $inner_html ) );
				if ( '' === $inner_text ) {
					continue;
				}
				if ( \preg_match( '#<(?:img|figure|h[1-6]|ul|ol|li|table|blockquote|pre|hr)\b#i', $inner_html ) ) {
					++$other_count;
					// Inline `<img>` / `<figure>` in freeform content
					// gets stripped by `filter_content()`'s orphan-img
					// pass and figure-class walker, but bundled AP
					// can't extract it (no `attrs.id`) — same cascade
					// as an unresolvable `core/image` block. Bump the
					// gate so Rule 1's format bypass treats this as
					// disqualifying.
					if ( \preg_match( '#<(?:img|figure)\b#i', $inner_html ) ) {
						++$unresolvable_image_count;
					}
					continue;
				}

				// Count substantive `<p>` paragraphs to catch the
				// `<p>Line 1.</p><p>Line 2.</p>` shape — multiple
				// paragraphs are article content, not a single
				// caption. Drop empty `<p></p>` shells first so
				// editor padding doesn't inflate the count.
				$cleaned = (string) \preg_replace( '#<p\b[^>]*>\s*</p>#i', '', $inner_html );
				$opens   = \preg_match_all( '#<p\b#i', $cleaned );

				// Zero `<p>` tags means a wrapper-less freeform
				// line — still one logical paragraph for Rule 3.
				if ( ( $opens > 0 ? (int) $opens : 1 ) > 1 ) {
					++$other_count;
					continue;
				}
				++$paragraph_count;
				continue;
			}

			if ( \in_array( $name, self::IGNORABLE_BLOCKS, true ) ) {
				continue;
			}

			if ( \in_array( $name, self::IMAGE_LIKE_BLOCKS, true ) ) {
				// Image-like blocks only count toward photo-post
				// detection when they resolve to local content
				// bundled AP can actually federate. An image block
				// with an external URL (no `id` attribute) would
				// have its markup stripped by `filter_content()` but
				// produce no attachment in the AP envelope — the
				// receiver sees a caption-only Note with no photo.
				// Treat unresolvable image blocks as "other" content
				// so detection falls through to article behavior and
				// the external URL stays in the body.
				if ( self::block_resolves_locally( $block, $post ) ) {
					++$image_count;
					$total_image_count += self::count_resolvable_images( $block, $post );
					if ( 'core/post-featured-image' === $name ) {
						$has_featured_image_block = true;
					}
				} else {
					++$other_count;
					++$unresolvable_image_count;
				}
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

		// Bundled AP caps attachment lists at
		// `activitypub_max_image_attachments` (default 4). When the
		// body carries more resolvable images than the cap, photo
		// treatment would strip every image figure but only ship
		// `cap` attachments — the overflow images vanish from the
		// federated post. Falling back to article behavior keeps
		// every figure inline AND still emits the first `cap`
		// images as supplementary attachments, so receivers never
		// see fewer images than the body declares.
		// Effective attachment count: resolvable images in the body
		// PLUS the featured image when set (bundled AP unshifts the
		// thumbnail into the media list before walking blocks).
		// Skip the thumbnail bump when a `core/post-featured-image`
		// block is already in the body — that block resolves to the
		// same attachment id, and bundled AP dedupes by id before
		// emitting attachments. Counting it twice would over-report
		// against the cap and reject otherwise-valid single-photo
		// posts when the cap is set tight (e.g., 1).
		$effective_image_count = $total_image_count;
		if ( $has_thumbnail && ! $has_featured_image_block ) {
			++$effective_image_count;
		}

		// Bundled AP supports disabling attachments entirely via
		// `activitypub_max_image_attachments = 0`. Photo treatment
		// makes no sense in that mode — the content stripper would
		// remove every image figure while AP emits zero attachments,
		// leaving a caption-only Note with no image. Reject before
		// any rule fires.
		$max_attachments = self::get_max_image_attachments( $post );
		if ( $max_attachments <= 0 ) {
			return false;
		}
		if ( $effective_image_count > $max_attachments ) {
			return false;
		}

		// Rule 1: explicit Image / Gallery post format. Fires only
		// when the body has SOME federatable image source — a
		// resolvable image-like block OR a live featured image —
		// AND no unresolvable image blocks. The latter gate is
		// important: `filter_content()` strips every
		// `wp-block-image` / `wp-block-gallery` /
		// `wp-block-post-featured-image` figure regardless of
		// resolvability, so allowing an unresolvable inline image
		// block to coexist with a thumbnail-only Rule 1 trigger
		// would silently drop that inline image from the federated
		// post. Other "other_count" content (headings, lists,
		// paragraphs the user wrote) is preserved by the stripper
		// and so still allowed under the Rule 1 bypass.
		if ( $has_format && ( $image_count > 0 || $has_thumbnail ) && 0 === $unresolvable_image_count ) {
			return true;
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
	 * Resolve the effective `activitypub_max_image_attachments` cap
	 * for a post. Mirrors bundled AP's read order
	 * (`bundled/activitypub/includes/transformer/class-post.php`
	 * `get_attachment()`): per-post meta → site option → upstream
	 * constant default, then run through the
	 * `activitypub_max_image_attachments` filter. Returning 0
	 * means attachments are disabled entirely.
	 *
	 * @param \WP_Post $post The post being evaluated.
	 * @return int The effective attachment cap.
	 */
	private static function get_max_image_attachments( WP_Post $post ): int {
		$meta = \get_post_meta( $post->ID, 'activitypub_max_image_attachments', true );
		if ( false === $meta || '' === $meta ) {
			$default = \defined( 'ACTIVITYPUB_MAX_IMAGE_ATTACHMENTS' ) ? ACTIVITYPUB_MAX_IMAGE_ATTACHMENTS : 4;
			$max     = \get_option( 'activitypub_max_image_attachments', $default );
		} else {
			$max = $meta;
		}

		/** This filter is documented in bundled/activitypub/includes/transformer/class-post.php */
		$max = \apply_filters( 'activitypub_max_image_attachments', (int) $max );

		return (int) $max;
	}

	/**
	 * True when an image-like block resolves to local content bundled
	 * AP can federate as an `attachment[]` entry.
	 *
	 * Block-shape detection without this gate would classify a
	 * `core/image` block carrying an external URL as a photo-post
	 * trigger, but bundled AP's attachment extraction (see
	 * `bundled/activitypub/includes/transformer/class-post.php::get_block_attachments`)
	 * only emits items with a resolvable local attachment id. The
	 * federated Note would then have its image markup stripped by
	 * `filter_content()` AND carry no attachment — the user loses the
	 * image entirely. Falling back to article behavior keeps the
	 * external URL intact in the body so receivers can still render
	 * it inline.
	 *
	 * For `core/image`: the `id` attribute must point at an
	 * attachment that still resolves to an image
	 * (`wp_attachment_is_image()`). Without this guard a
	 * deleted-but-id'd attachment would slip through detection and
	 * force `Note` shape, but bundled AP's `transform_attachment()`
	 * would drop the broken attachment silently — caption-only Note
	 * with no image. Mirrors the `has_image_thumbnail` posture for
	 * Rule 3.
	 *
	 * For `core/gallery`: only WP 5.9+ block-nested galleries
	 * (innerBlocks containing `core/image` children) count.
	 * Legacy galleries with a top-level `attrs.ids` array are NOT
	 * recognized — bundled AP's `get_media_from_blocks()` does not
	 * extract from `core/gallery` `attrs.ids` either (only
	 * `jetpack/slideshow` / `jetpack/tiled-gallery` and inner
	 * `core/image` blocks), so detecting on `ids` here would
	 * misclassify the post as photo while AP emits no
	 * attachment.
	 *
	 * For `core/post-featured-image`: defer to `has_image_thumbnail`,
	 * which validates the thumbnail attachment still exists.
	 *
	 * @param array<string, mixed> $block The parsed block.
	 * @param \WP_Post             $post  The post being evaluated.
	 * @return bool True when the block contributes a federatable image.
	 */
	private static function block_resolves_locally( array $block, WP_Post $post ): bool {
		$name = $block['blockName'] ?? '';

		if ( 'core/post-featured-image' === $name ) {
			return self::has_image_thumbnail( $post );
		}

		if ( 'core/image' === $name ) {
			$id = (int) ( $block['attrs']['id'] ?? 0 );
			return $id > 0 && (bool) \wp_attachment_is_image( $id );
		}

		if ( 'core/gallery' === $name ) {
			// WP 5.9+ galleries wrap individual `core/image` blocks
			// in `innerBlocks`. Legacy galleries (top-level
			// `attrs.ids`) are intentionally NOT recognized — see
			// class docblock.
			//
			// Require EVERY image-like child to resolve, not just
			// one: if a gallery mixes local and external images,
			// declaring it a photo post would strip the entire
			// gallery from content while bundled AP only emits
			// attachments for the local-id children — the external
			// images would silently disappear from the federated
			// post. Falling back to article behavior keeps every
			// figure intact in the body where receivers can render
			// the externally-hosted images inline. Children that
			// aren't image-like (paragraph captions, spacers
			// occasionally found inside galleries) are ignored.
			$inner_blocks = $block['innerBlocks'] ?? array();
			if ( ! \is_array( $inner_blocks ) || empty( $inner_blocks ) ) {
				return false;
			}

			$has_image = false;
			foreach ( $inner_blocks as $sub_block ) {
				if ( ! \is_array( $sub_block ) ) {
					continue;
				}
				$sub_name = $sub_block['blockName'] ?? '';
				if ( ! \in_array( $sub_name, self::IMAGE_LIKE_BLOCKS, true ) ) {
					continue;
				}
				$has_image = true;
				if ( ! self::block_resolves_locally( $sub_block, $post ) ) {
					return false;
				}
			}
			return $has_image;
		}

		return false;
	}

	/**
	 * Flattened count of individual resolvable images an image-like
	 * block would contribute as AP attachments. For `core/image` and
	 * `core/post-featured-image` that's 1; for `core/gallery` it's
	 * the count of resolvable inner `core/image` children. Used for
	 * the `activitypub_max_image_attachments` cap check — that cap
	 * operates on individual images, not on image-bearing blocks.
	 *
	 * @param array<string, mixed> $block The parsed block.
	 * @param \WP_Post             $post  The post being evaluated.
	 * @return int Count of resolvable images contributed by the block.
	 */
	private static function count_resolvable_images( array $block, WP_Post $post ): int {
		$name = $block['blockName'] ?? '';

		if ( 'core/post-featured-image' === $name ) {
			return self::has_image_thumbnail( $post ) ? 1 : 0;
		}

		if ( 'core/image' === $name ) {
			$id = (int) ( $block['attrs']['id'] ?? 0 );
			return $id > 0 && \wp_attachment_is_image( $id ) ? 1 : 0;
		}

		if ( 'core/gallery' === $name ) {
			$count        = 0;
			$inner_blocks = $block['innerBlocks'] ?? array();
			if ( \is_array( $inner_blocks ) ) {
				foreach ( $inner_blocks as $sub_block ) {
					if ( \is_array( $sub_block ) ) {
						$count += self::count_resolvable_images( $sub_block, $post );
					}
				}
			}
			return $count;
		}

		return 0;
	}

	/**
	 * True when the post has a Featured Image that still resolves to a
	 * live image attachment. `has_post_thumbnail()` only checks for a
	 * non-zero `_thumbnail_id` postmeta — the meta survives deletion of
	 * the underlying attachment, so it can be true for a featured image
	 * whose file is gone. Rule 3 would then classify the post as a
	 * photo post and force `Note` shape, but bundled AP's
	 * `transform_attachment` silently drops the broken attachment,
	 * leaving the user with a Note that says "look at my photo" but has
	 * no photo. Falling back to article behavior is the less surprising
	 * degradation.
	 *
	 * @param \WP_Post $post The post being evaluated.
	 * @return bool True when the featured image attachment exists and is an image.
	 */
	private static function has_image_thumbnail( WP_Post $post ): bool {
		$thumbnail_id = (int) \get_post_thumbnail_id( $post );
		if ( $thumbnail_id <= 0 ) {
			return false;
		}
		return (bool) \wp_attachment_is_image( $thumbnail_id );
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
	 * `wp-block-post-featured-image`), so a DOM walk that removes
	 * any matching `<figure>` plus its descendants handles nested
	 * gallery markup (gallery wraps individual image figures)
	 * without leaving orphan closing tags — the failure mode of a
	 * naive `.*?</figure>` regex on the same input.
	 *
	 * After the figure pass we also drop standalone `<img>` tags (a
	 * defensive measure for classic-editor inline images, or
	 * themes/plugins that wrap images differently) and clean up
	 * paragraph shells the editor leaves behind once their only
	 * child was the image we just removed.
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

		if ( '' === \trim( $content ) ) {
			return $content;
		}

		return self::strip_image_block_markup( $content );
	}

	/**
	 * DOM-walk pass that removes image-block figures, orphan `<img>`,
	 * and empty paragraph shells from rendered HTML. Returns the
	 * input unchanged if parsing fails — better to ship the caption
	 * alongside the image markup than to drop the content entirely on
	 * a parser hiccup.
	 *
	 * @param string $content Rendered HTML.
	 * @return string Content with image-block markup removed.
	 */
	private static function strip_image_block_markup( string $content ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The DOM API exposes camelCase properties (parentNode, childNodes); these are upstream PHP names, not ones we control.
		$doc                      = new \DOMDocument();
		$previous_internal_errors = \libxml_use_internal_errors( true );

		// Wrap with a UTF-8 hint so libxml doesn't mangle non-ASCII
		// captions, and with a <body> so we have a single root to
		// serialize children from. LIBXML_HTML_NOIMPLIED stops
		// libxml from wrapping the input in an <html>/<body> shell
		// of its own.
		$wrapped = '<?xml encoding="UTF-8"?><body>' . $content . '</body>';
		$loaded  = $doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		\libxml_clear_errors();
		\libxml_use_internal_errors( $previous_internal_errors );

		if ( ! $loaded ) {
			return $content;
		}

		$xpath = new \DOMXPath( $doc );

		// Match figures whose class list contains any of the image-like
		// block tokens. `concat(' ', normalize-space(@class), ' ')` is
		// the XPath idiom for whole-token matching — it sidesteps the
		// "wp-block-image-rounded" false-match a plain `contains()`
		// would hit.
		$figure_query = '//figure['
			. 'contains(concat(" ", normalize-space(@class), " "), " wp-block-image ") or '
			. 'contains(concat(" ", normalize-space(@class), " "), " wp-block-gallery ") or '
			. 'contains(concat(" ", normalize-space(@class), " "), " wp-block-post-featured-image ")'
			. ']';

		$figures = $xpath->query( $figure_query );
		if ( $figures instanceof \DOMNodeList ) {
			foreach ( \iterator_to_array( $figures ) as $figure ) {
				if ( $figure->parentNode ) {
					$figure->parentNode->removeChild( $figure );
				}
			}
		}

		// Drop any orphan <img> that survived (classic-editor inline
		// images, or themes that wrap images outside <figure>).
		$imgs = $doc->getElementsByTagName( 'img' );
		foreach ( \iterator_to_array( $imgs ) as $img ) {
			if ( $img->parentNode ) {
				$img->parentNode->removeChild( $img );
			}
		}

		// Empty paragraph shells left behind by the editor.
		$empty_paragraphs = $xpath->query( '//p[not(normalize-space())]' );
		if ( $empty_paragraphs instanceof \DOMNodeList ) {
			foreach ( \iterator_to_array( $empty_paragraphs ) as $paragraph ) {
				if ( $paragraph->parentNode ) {
					$paragraph->parentNode->removeChild( $paragraph );
				}
			}
		}

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( null === $body ) {
			return $content;
		}

		$result = '';
		foreach ( $body->childNodes as $child ) {
			$serialized = $doc->saveHTML( $child );
			if ( false !== $serialized ) {
				$result .= $serialized;
			}
		}

		return \trim( $result );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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
	 * Dimensions must match the URL bundled AP emitted, not the
	 * original attachment's metadata. Bundled AP defaults to the
	 * `large` derivative for image URLs
	 * (`wp_get_attachment_image_src( $id, 'large' )`), so populating
	 * `width`/`height` from `wp_get_attachment_metadata()` on a 4000-pixel
	 * original yields values that don't describe the linked 1024-pixel
	 * file — Pixelfed enforces dimensions server-side against the
	 * delivered bytes and rejects the mismatch. We resolve dimensions
	 * in three passes:
	 *
	 *   1. WP's resized-image filename suffix (`-WIDTHxHEIGHT.ext`).
	 *      Stable across core, Photon, and most CDN rewrites that
	 *      preserve the source filename.
	 *   2. The attachment's registered intermediate sizes (`sizes[]`
	 *      from `wp_get_attachment_metadata`), matching by filename
	 *      against the URL.
	 *   3. The attachment's original (full-size) metadata, used only
	 *      when neither suffix nor any intermediate size matches the
	 *      URL — i.e. the URL points at the original file.
	 *
	 * Zero / negative values are dropped rather than emitted: a
	 * width-0 image attachment is one Pixelfed will reject in
	 * `verifyAttachments` anyway, and Mastodon falls back to a
	 * sensible default when the keys are absent.
	 *
	 * @param array $attachment The AP attachment array.
	 * @param mixed $id         The WP attachment ID being transformed.
	 * @return array The attachment with width/height when resolvable.
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

		$url = (string) ( $attachment['url'] ?? '' );
		if ( '' === $url ) {
			return $attachment;
		}

		$dims = self::dimensions_for_url( $url, $id );
		if ( null === $dims ) {
			return $attachment;
		}

		$attachment['width']  = $dims[0];
		$attachment['height'] = $dims[1];

		return $attachment;
	}

	/**
	 * Resolve dimensions for an image URL. Returns `[width, height]`
	 * when both are positive integers, or `null` when the URL can't
	 * be confidently matched to a known size.
	 *
	 * @param string $url The image URL bundled AP emitted.
	 * @param mixed  $id  The WP attachment ID, if known.
	 * @return array{0:int, 1:int}|null
	 */
	private static function dimensions_for_url( string $url, $id ): ?array {
		$parsed = \wp_parse_url( $url );
		$path   = (string) ( $parsed['path'] ?? '' );

		// When we have a local attachment id, prefer metadata-driven
		// resolution. Metadata describes the actual files on disk; the
		// filename-suffix regex (below) is a heuristic that misfires
		// when a user-uploaded original happens to be named
		// `photo-1024x683.jpg` while its actual bytes are some other
		// size. Falling through to Pass 3 (URL-suffix parsing) for
		// local attachments only when metadata can't resolve keeps
		// authoritative numbers winning over inference.
		if ( \is_numeric( $id ) && (int) $id > 0 ) {
			$meta = \wp_get_attachment_metadata( (int) $id );
			if ( \is_array( $meta ) ) {
				$dims = self::dimensions_from_metadata( $meta, $path );
				if ( null !== $dims ) {
					return $dims;
				}
			}
		}

		// Pass 3 (external-URL fallback): filename suffix `-WxH.ext`.
		// WP names resized derivatives that way and the suffix
		// survives most CDN rewrites that keep the source basename
		// intact. Match against the URL's path component only —
		// matching against the full URL would pick up a `-WxH.ext`
		// fragment in a query string (e.g. a redirect / tracker
		// param `?next=photo-1024x768.jpg`), emitting dimensions
		// that describe a different image than the one actually
		// linked. This is the only resolution path for external
		// images (no local attachment id), so we treat it as
		// best-effort rather than authoritative.
		if ( '' !== $path && \preg_match( '/-(\d+)x(\d+)\.(?:jpe?g|png|gif|webp|avif|heic|heif|tiff?)$/i', $path, $matches ) ) {
			$width  = (int) $matches[1];
			$height = (int) $matches[2];
			if ( $width > 0 && $height > 0 ) {
				return array( $width, $height );
			}
		}

		return null;
	}

	/**
	 * Resolve dimensions from an attachment's stored metadata when the
	 * URL path matches a registered file. Returns `[width, height]` or
	 * `null` when no size's filename appears in the URL path.
	 *
	 * Pass A walks the registered intermediate sizes (`sizes[name].file`
	 * is the basename of each resized derivative); Pass B falls back
	 * to the full-size original (`meta['file']` is the relative path
	 * from `wp-content/uploads/`). Both passes anchor the basename
	 * with a leading `/` so a URL like `/uploads/some-photo.jpg`
	 * doesn't accidentally match a registered file named `photo.jpg`
	 * — important on sites that disable the year/month upload subdir
	 * option, where `meta['file']` is a bare basename.
	 *
	 * @param array<string, mixed> $meta The attachment metadata.
	 * @param string               $path The URL's path component (no query/fragment).
	 * @return array{0:int, 1:int}|null
	 */
	private static function dimensions_from_metadata( array $meta, string $path ): ?array {
		if ( '' === $path ) {
			return null;
		}

		// Pass A: registered intermediate sizes. Each entry's
		// `file` is just the basename, so we anchor with a slash
		// to require a directory boundary before the match.
		if ( ! empty( $meta['sizes'] ) && \is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_data ) {
				if ( ! \is_array( $size_data ) ) {
					continue;
				}
				$file   = (string) ( $size_data['file'] ?? '' );
				$width  = (int) ( $size_data['width'] ?? 0 );
				$height = (int) ( $size_data['height'] ?? 0 );
				if ( '' === $file || $width <= 0 || $height <= 0 ) {
					continue;
				}
				if ( \str_ends_with( $path, '/' . \ltrim( $file, '/' ) ) ) {
					return array( $width, $height );
				}
			}
		}

		// Pass B: full-size original. `meta['file']` is the
		// relative path under uploads (e.g. `2026/05/photo.jpg`),
		// but on sites with year/month folders disabled it's a
		// bare basename — anchor with `/` either way.
		$file = (string) ( $meta['file'] ?? '' );
		if ( '' === $file || ! \str_ends_with( $path, '/' . \ltrim( $file, '/' ) ) ) {
			return null;
		}

		$width  = (int) ( $meta['width'] ?? 0 );
		$height = (int) ( $meta['height'] ?? 0 );
		if ( $width > 0 && $height > 0 ) {
			return array( $width, $height );
		}

		return null;
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
