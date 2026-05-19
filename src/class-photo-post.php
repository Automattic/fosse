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

		$has_thumbnail = self::has_image_thumbnail( $post );

		// Empty body + featured image = "Set thumbnail, hit publish"
		// flow (Rule 3 with zero paragraphs). Catch this before the
		// block parser, which returns a single blank-blockName block
		// for empty content and would otherwise classify it as not a
		// photo post.
		// `$post->post_content` round-trips through WP's storage layer
		// with kses-applied slashes intact ("\"id\":42") — fine for
		// rendering, but `parse_blocks()` then deserializes block
		// attributes as `null` because the embedded JSON is malformed.
		// Unslashing here gives the block parser the same view of the
		// post as the editor sees, so attribute-driven checks
		// (`block_resolves_locally()` reads `attrs.id`) work
		// consistently across stored, autosaved, and revisioned
		// content.
		$content = (string) \wp_unslash( $post->post_content );

		if ( $has_thumbnail && '' === \trim( $content ) ) {
			return true;
		}

		$blocks = \parse_blocks( $content );
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
				} else {
					++$other_count;
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
	 * For `core/image`: presence of a positive `id` attribute is
	 * enough — we intentionally do NOT verify the attachment still
	 * exists, mirroring bundled AP's own posture (a deleted-but-id'd
	 * attachment is dropped silently downstream rather than blocking
	 * federation).
	 *
	 * For `core/gallery`: any positive id in the top-level `ids`
	 * attribute counts; for WP 5.9+ block-nested galleries, recurse
	 * into `innerBlocks`.
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
			return (int) ( $block['attrs']['id'] ?? 0 ) > 0;
		}

		if ( 'core/gallery' === $name ) {
			$ids = $block['attrs']['ids'] ?? array();
			if ( \is_array( $ids ) ) {
				foreach ( $ids as $id ) {
					if ( (int) $id > 0 ) {
						return true;
					}
				}
			}
			// WP 5.9+ galleries wrap individual `core/image` blocks
			// in `innerBlocks` rather than listing ids on the
			// gallery itself.
			$inner_blocks = $block['innerBlocks'] ?? array();
			if ( \is_array( $inner_blocks ) ) {
				foreach ( $inner_blocks as $sub_block ) {
					if ( \is_array( $sub_block ) && self::block_resolves_locally( $sub_block, $post ) ) {
						return true;
					}
				}
			}
			return false;
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
		// Pass 1: filename suffix. WP names resized derivatives
		// `<base>-<W>x<H>.<ext>`; the suffix survives most CDN
		// URL rewrites that keep the source basename intact.
		if ( \preg_match( '/-(\d+)x(\d+)\.(?:jpe?g|png|gif|webp|avif)(?:$|[\?\#])/i', $url, $matches ) ) {
			$width  = (int) $matches[1];
			$height = (int) $matches[2];
			if ( $width > 0 && $height > 0 ) {
				return array( $width, $height );
			}
		}

		if ( ! \is_numeric( $id ) ) {
			return null;
		}

		$meta = \wp_get_attachment_metadata( (int) $id );
		if ( ! \is_array( $meta ) ) {
			return null;
		}

		// Pass 2: registered intermediate sizes. `sizes[name].file` is
		// the basename of the resized file; matching it against the
		// URL handles renamed derivatives that don't carry the
		// `-WxH` suffix (e.g. some custom-size registrations).
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
				if ( \str_contains( $url, $file ) ) {
					return array( $width, $height );
				}
			}
		}

		// Pass 3: full-size original. Reached when neither the
		// filename suffix nor any registered intermediate matches.
		// Confirm the URL actually points at `$meta['file']` before
		// falling back — otherwise a CDN rewrite that preserves the
		// basename, or an `activitypub_get_image` callback that
		// substitutes a derivative URL without the size suffix, would
		// reintroduce the original/derivative dimension mismatch this
		// helper exists to prevent. Match against the URL's path
		// component only so query strings / fragments don't fool the
		// `str_ends_with` check.
		$file = (string) ( $meta['file'] ?? '' );
		if ( '' === $file ) {
			return null;
		}

		$parsed = \wp_parse_url( $url );
		$path   = (string) ( $parsed['path'] ?? '' );
		if ( '' === $path || ! \str_ends_with( $path, $file ) ) {
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
