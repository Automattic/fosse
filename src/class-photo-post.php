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
 *   - `blurhash`: handled by the sibling {@see Blurhash} class —
 *     computed at upload time off `wp_generate_attachment_metadata`,
 *     stored as postmeta, injected via its own
 *     `activitypub_attachment` callback. Decoupled from this
 *     projector so non-photo image attachments also gain the field.
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
	 * by `"{blog_id}:{post_id}"` — the blog id prefix keeps the memo
	 * safe under `switch_to_blog()` on multisite, where post ids are not
	 * globally unique and two blogs can share an id. Cleared between
	 * requests because the cache lives on a static.
	 *
	 * @var array<string, bool>
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

		$cache_key = \get_current_blog_id() . ':' . $resolved->ID;
		if ( isset( self::$decision_cache[ $cache_key ] ) ) {
			return self::$decision_cache[ $cache_key ];
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

		self::$decision_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Built-in photo-post detection. Runs after the pre-filter
	 * short-circuit declines to answer.
	 *
	 * Returns true when any of:
	 *
	 *   1. The post format is `image` or `gallery` AND the body
	 *      carries a federatable image source (a resolvable
	 *      image-like block OR a live featured image) AND no
	 *      unresolvable image markup. Post format alone is not
	 *      enough — bare format would force `Note` even when
	 *      bundled AP can't emit any attachment, leaving a
	 *      caption-only Note with no photo.
	 *   2. The block content boils down to "one image-like block,
	 *      optionally followed by a single paragraph block,"
	 *      ignoring purely structural blocks (spacer, separator).
	 *      The image-like block must resolve to a local attachment
	 *      (`wp_attachment_is_image`); external-URL image blocks
	 *      don't qualify.
	 *   3. The post has a featured image (validated via
	 *      `wp_attachment_is_image`, not just postmeta) and the body
	 *      has at most one paragraph block — so "set featured image,
	 *      type one sentence, hit publish" works without the user
	 *      having to know about Post Formats.
	 *
	 * All three rules also enforce the
	 * `activitypub_max_image_attachments` cap. When the body
	 * carries more images than bundled AP will emit, detection
	 * falls through to article behavior so the overflow stays
	 * inline. A cap of 0 (attachments disabled) rejects photo
	 * treatment entirely.
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
		// Bundled AP supports disabling attachments entirely via
		// `activitypub_max_image_attachments = 0`. Photo treatment
		// makes no sense in that mode — the content stripper would
		// remove every image figure while AP emits zero attachments,
		// leaving a caption-only Note with no image. Reject before
		// ANY rule fires (including the empty-body / featured-image
		// early return below) so the cap-zero contract holds for
		// the "set thumbnail, hit publish" flow too.
		$max_attachments = self::get_max_image_attachments( $post );
		if ( $max_attachments <= 0 ) {
			return false;
		}

		$format        = \get_post_format( $post );
		$has_format    = 'image' === $format || 'gallery' === $format;
		$has_thumbnail = self::has_image_thumbnail( $post );
		$thumbnail_id  = $has_thumbnail ? (int) \get_post_thumbnail_id( $post ) : 0;

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
		// Resolvable attachment ids contributed by image-like blocks
		// in document order (`core/image` ids and `core/gallery`
		// inner `core/image` ids; `core/post-featured-image` blocks
		// contribute nothing here because their resolved id is the
		// thumbnail id, which is appended once after the loop). The
		// `activitypub_max_image_attachments` cap compares against
		// `count( array_unique( … ) )` of this list plus the
		// thumbnail id — bundled AP dedupes by id before applying
		// the cap, so any attachment that appears via multiple
		// paths (featured-image block + thumbnail, or `core/image`
		// block carrying the same id as the thumbnail) must count
		// once. Tracking ids rather than a flat count means the
		// dedup happens naturally on the cap comparison.
		$attachment_ids = array();

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
					// Collect attachment ids contributed by this
					// block. `block_image_ids()` returns the
					// resolvable `core/image` and gallery-child
					// ids; `core/post-featured-image` blocks
					// contribute nothing here because the thumbnail
					// id is appended once after the loop. Deduping
					// the combined list against the cap matches
					// what bundled AP actually emits after its own
					// id-based dedup.
					foreach ( self::block_image_ids( $block, $post ) as $id ) {
						$attachment_ids[] = $id;
					}
				} else {
					++$other_count;
					++$unresolvable_image_count;
				}
				continue;
			}

			if ( 'core/paragraph' === $name ) {
				$inner_html = $block['innerHTML'] ?? '';
				// Inline `<img>` / `<figure>` inside a paragraph
				// block lands in the same cascade as the freeform
				// branch above: `filter_content()`'s orphan-img
				// pass strips the image, but bundled AP's block
				// extractor only walks `core/image` /
				// `core/gallery` / `core/cover` — it doesn't scan
				// paragraph innerHTML — so the inline image
				// disappears entirely from the federated post.
				// Treat as unresolvable so Rule 1's format bypass
				// disqualifies the post and the figure stays
				// inline in the article body.
				//
				// Check the raw innerHTML BEFORE the empty-text
				// guard below: a paragraph whose only content is
				// `<img>` strips to empty text but still carries an
				// image that would vanish on federation. Reversing
				// the order would `continue` past this case.
				if ( \preg_match( '#<(?:img|figure)\b#i', $inner_html ) ) {
					++$other_count;
					++$unresolvable_image_count;
					continue;
				}
				// An empty paragraph block ("<p></p>") is structural
				// padding from the editor, not a real caption.
				$inner = \trim( \wp_strip_all_tags( $inner_html ) );
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
		// Effective attachment count: unique resolvable ids across
		// every source bundled AP considers — the post thumbnail
		// (unshifted into the media list) plus every id contributed
		// by image-like blocks. Deduping by id mirrors bundled AP's
		// own pre-cap dedup, so an attachment that appears via
		// multiple paths (featured image + matching `core/image`
		// block, or `core/post-featured-image` block + thumbnail)
		// counts once instead of inflating the cap comparison.
		if ( $thumbnail_id > 0 ) {
			$attachment_ids[] = $thumbnail_id;
		}
		$effective_image_count = \count( \array_unique( $attachment_ids ) );

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
		// Mirror bundled AP's read order
		// (`bundled/activitypub/includes/transformer/class-post.php`
		// `get_attachment()`): fall back to the site option whenever the
		// per-post meta isn't numeric — not just when it's `false`/`''`.
		// A meta of `'0'` is numeric and authoritative (attachments
		// disabled per-post); a non-numeric stray ('', a serialized
		// array from a buggy importer, etc.) defers to the option.
		if ( ! \is_numeric( $meta ) ) {
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
	 * Ordered list of resolvable image attachment ids for a photo post.
	 *
	 * Order is deterministic so downstream projectors emit attachments
	 * in a stable sequence across federation passes:
	 *
	 *   1. Featured image (when it resolves to a live image attachment),
	 *      since the Featured Image is the post's canonical hero shot
	 *      whether or not the body also embeds a `core/post-featured-image`
	 *      block.
	 *   2. Image-like blocks in document order: `core/image` ids,
	 *      `core/post-featured-image` (skipped because the featured
	 *      image is already first — avoids double-counting), and
	 *      `core/gallery` inner `core/image` ids walked left-to-right.
	 *
	 * Deduplicated: a post that includes its featured image both as
	 * postmeta and via a `core/image` block (a common publish path)
	 * yields one attachment id, not two.
	 *
	 * Only "resolves locally" blocks contribute — mirrors the
	 * detection-time gate so the projector can't propose an attachment
	 * that bundled AP / Atmosphere would later silently drop. External
	 * `core/image` blocks (no `id` attribute, URL points off-site)
	 * intentionally fall through here; they're handled by the
	 * detection-time disqualification, not the attachment list.
	 *
	 * @param WP_Post $post The photo post.
	 * @return int[] Resolvable image attachment ids in projection order.
	 */
	public static function collect_image_attachment_ids( WP_Post $post ): array {
		$ordered = array();

		$thumbnail_id = (int) \get_post_thumbnail_id( $post );
		if ( $thumbnail_id > 0 && \wp_attachment_is_image( $thumbnail_id ) ) {
			$ordered[] = $thumbnail_id;
		}

		$content = (string) ( $post->post_content ?? '' );
		if ( '' !== \trim( $content ) ) {
			$blocks = \function_exists( 'parse_blocks' ) ? \parse_blocks( $content ) : array();
			if ( \is_array( $blocks ) ) {
				foreach ( $blocks as $block ) {
					if ( \is_array( $block ) ) {
						$ordered = \array_merge( $ordered, self::block_image_ids( $block, $post ) );
					}
				}
			}
		}

		// Dedup while preserving order — the featured image may also
		// appear as a `core/image` block by id.
		return \array_values( \array_unique( $ordered ) );
	}

	/**
	 * Recursive helper: gather resolvable image attachment ids from a
	 * single parsed block. Mirrors the resolution rules in
	 * {@see self::block_resolves_locally()} but yields ids rather than
	 * a boolean.
	 *
	 * `core/post-featured-image` is intentionally elided — the
	 * featured image id is contributed by the caller's thumbnail pass,
	 * so reading it again here would double-add. `core/gallery` walks
	 * its `innerBlocks` recursively; legacy `attrs.ids` galleries are
	 * not recognized, matching detection.
	 *
	 * @param array<string, mixed> $block A parsed block.
	 * @param WP_Post              $post  The post being walked (for context).
	 * @return int[] Resolvable attachment ids contributed by this block.
	 */
	private static function block_image_ids( array $block, WP_Post $post ): array {
		$name = $block['blockName'] ?? '';

		if ( 'core/image' === $name ) {
			$id = (int) ( $block['attrs']['id'] ?? 0 );
			if ( $id > 0 && \wp_attachment_is_image( $id ) ) {
				return array( $id );
			}
			return array();
		}

		if ( 'core/gallery' === $name ) {
			$ids          = array();
			$inner_blocks = $block['innerBlocks'] ?? array();
			if ( \is_array( $inner_blocks ) ) {
				foreach ( $inner_blocks as $sub_block ) {
					if ( \is_array( $sub_block ) ) {
						$ids = \array_merge( $ids, self::block_image_ids( $sub_block, $post ) );
					}
				}
			}
			return $ids;
		}

		return array();
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
	 * Caption text for a photo post: rendered post content with all
	 * image-block markup stripped and tags removed.
	 *
	 * Shared helper used by both the AP `activitypub_the_content` path
	 * (where it lands as `content`) and the AT Protocol projector
	 * (where it lands as `text` on a `app.bsky.feed.post` record next
	 * to an `app.bsky.embed.images` embed). Renders the post body
	 * through `the_content`, runs the same DOM-strip the AP filter
	 * uses, and then collapses to plain text — caption-shaped output
	 * suitable for either an HTML-tolerant or plain-text backend.
	 *
	 * The return is normalized the same way Atmosphere normalizes text
	 * before publishing (`\Atmosphere\sanitize_text()`): entities
	 * decoded, tags stripped, Unicode whitespace collapsed to single
	 * spaces, trimmed. Callers apply their own truncation against an
	 * external character cap (Bluesky's 300, for instance) on top.
	 *
	 * @param WP_Post $post The photo post.
	 * @return string Plain-text caption with image markup removed.
	 */
	public static function caption_text( WP_Post $post ): string {
		// `the_content` callbacks (blocks, shortcodes, oEmbed) read
		// `$GLOBALS['post']` via `get_the_ID()` and similar helpers;
		// running this filter outside The Loop — which is exactly
		// what federation hot paths do, on cron and REST — would let
		// those callbacks resolve against whichever post happens to
		// be in the global, not the one we're rendering. Snapshot
		// the prior global so we can restore it on the way out, then
		// set up the global ourselves so the caption matches the
		// front-end render byte-for-byte.
		//
		// `setup_postdata()` populates the loop globals ($id, $authordata,
		// $page, $pages, …) but deliberately does NOT assign
		// `$GLOBALS['post']`, so callbacks that read `get_post()` /
		// `get_the_ID()` would still resolve against the stale (or null)
		// global. Assign it explicitly here so the render is anchored to
		// the post we're captioning.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Read-and-restore of the WP loop global.
		$previous_global = $GLOBALS['post'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Anchor the loop global to the post being captioned; restored in finally.
		$GLOBALS['post'] = $post;
		\setup_postdata( $post );

		try {
			$rendered = \apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
		} catch ( \Throwable $e ) {
			// A misbehaving `the_content` listener shouldn't crater
			// the federation event — fall back to an empty caption
			// so the AT projector ships images with no caption, and
			// the AP projector ships caption-less content.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surface third-party filter crashes so operators can spot the bad plugin.
			\error_log(
				\sprintf(
					'[fosse:photo-post] the_content filter threw while building caption for post %d: %s',
					$post->ID,
					$e->getMessage()
				)
			);
			$rendered = '';
		} finally {
			// `wp_reset_postdata()` restores the loop globals from
			// `$GLOBALS['post']` — which we just overwrote — so it cannot
			// undo our override on its own. Restore the snapshot plainly:
			// reassign the captured value (including null, which is the
			// correct "there was no loop post" state) rather than
			// unsetting, so we don't fight `wp_reset_postdata()` or leave
			// the global in a shape it never had.
			\wp_reset_postdata();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the prior global we explicitly captured.
			$GLOBALS['post'] = $previous_global;
		}

		$stripped = '' === \trim( (string) $rendered ) ? '' : self::strip_image_block_markup( (string) $rendered );

		// Decode HTML entities BEFORE stripping tags, then collapse
		// whitespace. `the_content` runs `wptexturize`, so curly quotes,
		// dashes, and ampersands arrive entity-encoded (`&#8217;`,
		// `&amp;`, `&nbsp;`); a bare `wp_strip_all_tags()` would leave
		// those literal entities in the Bluesky record text and the AP
		// content. `\Atmosphere\sanitize_text()` decodes first (so an
		// entity-encoded tag becomes a real tag the strip then removes),
		// strips tags, and collapses Unicode whitespace — the same
		// normalization Atmosphere applies before publishing. Mirror
		// `Bsky_Short_Form_Fit`'s guarded fallback for when Atmosphere
		// isn't loaded.
		if ( \function_exists( '\Atmosphere\sanitize_text' ) ) {
			return \Atmosphere\sanitize_text( $stripped );
		}

		// Fallback mirrors sanitize_text()'s ORDER: decode, then strip,
		// then collapse. Stripping before decoding would leave an
		// entity-encoded tag (`&lt;script&gt;`) untouched and the decode
		// would then materialize live markup into the record text —
		// the exact failure the upstream order comment warns about.
		$plain     = \wp_strip_all_tags( \html_entity_decode( $stripped, ENT_QUOTES, 'UTF-8' ) );
		$collapsed = \preg_replace( '/\s+/u', ' ', $plain );
		$plain     = \is_string( $collapsed ) ? $collapsed : $plain;

		return \trim( $plain );
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
				if ( ! $figure->parentNode ) {
					continue;
				}

				// Image-block figures often nest a `<figcaption>`
				// that carries the user's caption text — losing it
				// when we strip the figure would mangle the user's
				// intent (Pixelfed and Mastodon both render figcaption
				// content alongside attached media). Lift each non-
				// empty figcaption out as a `<p>` in the figure's
				// place before removing the wrapper so the caption
				// survives stripping.
				$figcaptions = $figure->getElementsByTagName( 'figcaption' );
				foreach ( \iterator_to_array( $figcaptions ) as $figcaption ) {
					$caption_text = \trim( (string) $figcaption->textContent );
					if ( '' === $caption_text ) {
						continue;
					}
					$paragraph = $doc->createElement( 'p' );
					$paragraph->appendChild( $doc->createTextNode( $caption_text ) );
					$figure->parentNode->insertBefore( $paragraph, $figure );
				}

				$figure->parentNode->removeChild( $figure );
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
	 * in four passes:
	 *
	 *   0. Photon / Jetpack Site Accelerator query-arg transforms
	 *      (`?w=`, `?h=`, `?resize=W,H`, `?fit=W,H`). Photon has no
	 *      named-size files — it serves the original filename and encodes
	 *      the target size as query args — so this pass runs FIRST. Were
	 *      it not, a Photon URL (whose path ends with the original
	 *      `meta['file']`) would fall through to Pass 3 and emit the
	 *      full-size original dimensions instead of the delivered ones.
	 *   1. WP's resized-image filename suffix (`-WIDTHxHEIGHT.ext`).
	 *      Stable across core and most CDN rewrites that preserve the
	 *      source filename.
	 *   2. The attachment's registered intermediate sizes (`sizes[]`
	 *      from `wp_get_attachment_metadata`), matching by filename
	 *      against the URL.
	 *   3. The attachment's original (full-size) metadata, used only
	 *      when no query transform, suffix, or intermediate size matches
	 *      the URL — i.e. the URL points at the unresized original file.
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
		$query  = (string) ( $parsed['query'] ?? '' );

		// Pass 0 (Photon / Jetpack Site Accelerator query args). Photon
		// has no named-size files: it serves the ORIGINAL filename and
		// encodes the target dimensions as query-arg transforms
		// (`?w=`, `?h=`, `?resize=W,H`, `?fit=W,H`). On a Photon URL the
		// path therefore ends with `meta['file']`, so the metadata passes
		// below would match Pass B and emit the full-size original
		// dimensions — describing a 4000px original while the delivered
		// bytes are the 1024px derivative Photon actually served. Recover
		// the delivered size from the query args first; only fall through
		// to the metadata / suffix passes when there are no resize args
		// (a plain original URL with no Photon transform).
		if ( '' !== $query ) {
			$dims = self::dimensions_from_query( $query );
			if ( null !== $dims ) {
				return $dims;
			}

			// A lone `w=` / `h=` is still a resize transform — the
			// delivered bytes are NOT the file the metadata passes below
			// describe. Photon scales the other axis to preserve aspect
			// (and never upscales), so when we have the original's
			// metadata we can derive the delivered size exactly; without
			// it we decline rather than fall through, which would emit
			// the full-size original's dimensions for a resized delivery
			// — the exact mismatch Pass 0 exists to prevent.
			$lone = self::lone_resize_axis_from_query( $query );
			if ( null !== $lone ) {
				return self::dimensions_from_lone_axis( $lone[0], $lone[1], $id );
			}
		}

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
	 * Recover the delivered dimensions encoded in a Photon / Jetpack
	 * Site Accelerator URL query string. Returns `[width, height]` when
	 * both are positive integers, or `null` when the query carries no
	 * dimension-bearing resize transform.
	 *
	 * Photon maps each registered image size onto query-arg transforms
	 * against the original file rather than serving a named-size
	 * derivative, so these args are the only signal for the delivered
	 * dimensions. Supported transforms, in precedence order:
	 *
	 *   - `resize=W,H` — crop-to-fill at exactly W×H. Both values
	 *     describe the output bytes, so this is the most authoritative.
	 *   - `fit=W,H` — scale to fit inside the W×H box (aspect preserved,
	 *     so the output may be smaller on one axis). We emit W×H as the
	 *     best available bound; it matches what Pixelfed validates the
	 *     delivered file against in the common "image larger than the
	 *     box" case, and AP/Pixelfed treat dimensions as a hint, not a
	 *     contract on the exact pixel count.
	 *   - `w=W` and `h=H` — single-axis caps. Only resolvable here as a
	 *     pair; a lone `w` or `h` leaves the other axis unknown (Photon
	 *     scales it to preserve aspect), so this helper declines and the
	 *     caller derives the missing axis from the attachment's original
	 *     metadata via {@see self::dimensions_from_lone_axis()} instead.
	 *
	 * Values are clamped to positive integers; a `0`/negative/non-numeric
	 * arg is treated as absent.
	 *
	 * @param string $query The URL's query component (no leading `?`).
	 * @return array{0:int, 1:int}|null
	 */
	private static function dimensions_from_query( string $query ): ?array {
		$args = array();
		\wp_parse_str( $query, $args );

		// `resize` / `fit` carry a `W,H` pair directly. `resize` wins
		// over `fit` when both are somehow present — it pins exact
		// output dimensions, `fit` only bounds them.
		foreach ( array( 'resize', 'fit' ) as $key ) {
			if ( ! isset( $args[ $key ] ) || ! \is_string( $args[ $key ] ) ) {
				continue;
			}
			$parts = \explode( ',', $args[ $key ] );
			if ( 2 !== \count( $parts ) ) {
				continue;
			}
			$width  = (int) \trim( $parts[0] );
			$height = (int) \trim( $parts[1] );
			if ( $width > 0 && $height > 0 ) {
				return array( $width, $height );
			}
		}

		// `w` + `h` as a pair. A lone `w` or `h` leaves the other axis
		// scaled-to-aspect and therefore unknown, so require both.
		$has_w = isset( $args['w'] ) && \is_numeric( $args['w'] );
		$has_h = isset( $args['h'] ) && \is_numeric( $args['h'] );
		if ( $has_w && $has_h ) {
			$width  = (int) $args['w'];
			$height = (int) $args['h'];
			if ( $width > 0 && $height > 0 ) {
				return array( $width, $height );
			}
		}

		return null;
	}

	/**
	 * Detect a single-axis Photon resize transform (`?w=` XOR `?h=`)
	 * in a URL query string. Returns `[axis, value]` (axis `'w'` or
	 * `'h'`, value a positive int) when exactly one axis is pinned, or
	 * `null` when neither — or both — axes carry a usable value (the
	 * both-axes case is handled by {@see self::dimensions_from_query()}).
	 *
	 * Zero / negative / non-numeric values are treated as absent:
	 * Photon ignores them and serves the untransformed file, so falling
	 * through to the metadata passes is correct for those.
	 *
	 * @param string $query The URL's query component (no leading `?`).
	 * @return array{0:string, 1:int}|null
	 */
	private static function lone_resize_axis_from_query( string $query ): ?array {
		$args = array();
		\wp_parse_str( $query, $args );

		$width  = isset( $args['w'] ) && \is_numeric( $args['w'] ) ? (int) $args['w'] : 0;
		$height = isset( $args['h'] ) && \is_numeric( $args['h'] ) ? (int) $args['h'] : 0;

		if ( $width > 0 && $height <= 0 ) {
			return array( 'w', $width );
		}
		if ( $height > 0 && $width <= 0 ) {
			return array( 'h', $height );
		}

		return null;
	}

	/**
	 * Derive delivered dimensions for a single-axis Photon transform
	 * from the attachment's original metadata.
	 *
	 * Photon scales the unpinned axis to preserve the original's aspect
	 * ratio and never upscales — a `w=` at or above the original width
	 * serves the original unchanged. With the original's dimensions in
	 * metadata the delivered size is therefore fully determined; without
	 * them (no local id, missing/zero metadata) we return `null` so the
	 * attachment ships without dimension claims rather than with the
	 * untransformed original's.
	 *
	 * @param string $axis  `'w'` or `'h'` — the pinned axis.
	 * @param int    $value The pinned axis value (positive).
	 * @param mixed  $id    The WP attachment ID, if known.
	 * @return array{0:int, 1:int}|null
	 */
	private static function dimensions_from_lone_axis( string $axis, int $value, $id ): ?array {
		if ( ! \is_numeric( $id ) || (int) $id <= 0 ) {
			return null;
		}

		$meta = \wp_get_attachment_metadata( (int) $id );
		if ( ! \is_array( $meta ) ) {
			return null;
		}

		$orig_width  = (int) ( $meta['width'] ?? 0 );
		$orig_height = (int) ( $meta['height'] ?? 0 );
		if ( $orig_width <= 0 || $orig_height <= 0 ) {
			return null;
		}

		if ( 'w' === $axis ) {
			if ( $value >= $orig_width ) {
				return array( $orig_width, $orig_height );
			}
			return array( $value, \max( 1, (int) \round( $value * $orig_height / $orig_width ) ) );
		}

		if ( $value >= $orig_height ) {
			return array( $orig_width, $orig_height );
		}
		return array( \max( 1, (int) \round( $value * $orig_width / $orig_height ) ), $value );
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
