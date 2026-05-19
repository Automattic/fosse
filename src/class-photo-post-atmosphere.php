<?php
/**
 * Photo-post AT Protocol federation-shape projector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

use WP_Post;

/**
 * Projects {@see Photo_Post::is_photo_post()} onto Atmosphere's
 * Bluesky transformer so a WordPress photo-shaped post lands in
 * Flashes / Pinksky as a native image post (`app.bsky.embed.images`)
 * instead of as the default `app.bsky.embed.external` link card.
 *
 * Pixelfed and the AT-protocol photo apps (Flashes, Pinksky) both
 * decide "this is a photo" purely from the wire envelope — the
 * external client has no way to read WordPress's CPT / taxonomy /
 * block structure, so the projection has to happen on the WP side.
 * Detection lives in `Photo_Post`; this class is the AT-side
 * projection, the AT counterpart of `Photo_Post::register()`'s AP
 * hooks.
 *
 * Two filter callbacks do the work:
 *
 *   1. `atmosphere_is_short_form_post` — for photo posts, force the
 *      short-form path so Atmosphere skips link-card / teaser-thread
 *      composition entirely. The transformer's "short-form" path is
 *      already "post body becomes the text, no external card" — the
 *      shape closest to a native Bluesky photo post.
 *   2. `atmosphere_transform_bsky_post` — on the short-form root
 *      record for a photo post, rewrite `text` to the caption-only
 *      plain text (same stripping the AP `activitypub_the_content`
 *      filter does) and replace `embed` with `app.bsky.embed.images`
 *      built from up to {@see self::MAX_IMAGES} uploaded blob refs.
 *
 * Upstream Atmosphere
 * (`Automattic/wordpress-atmosphere` PR 72) adds a focused
 * `atmosphere_post_embed` seam and a public `upload_image_blob()`
 * rename. This projector deliberately targets the *shipped*
 * Atmosphere surface (`atmosphere_transform_bsky_post` + the
 * already-public `Post::upload_thumbnail()`) so the photo-post AT
 * federation works as soon as this PR lands, with or without that
 * upstream change. When PR 72 lands and the bundled copy resyncs,
 * see the class docblock notes below for the cleaner two-line
 * switchover.
 *
 * Out of scope (separate tickets):
 *
 *   - >4 image overflow strategy (thread, fall back to link card,
 *     drop extras). For now the cap is enforced and a
 *     `fosse_photo_post_atmosphere_overflow` action fires so a
 *     follow-up projector can subscribe without changing this
 *     class.
 *   - Alt-text gap when the Featured Image lacks an `_wp_attachment_image_alt`
 *     postmeta — covered by `DOTCOM-16806`. The projector emits an
 *     empty string for `alt`; AT Protocol's image embed lexicon
 *     accepts it but the accessibility hit is on us until that
 *     ticket lands.
 *   - Animated GIF handling — AT Proto's `embed.images` doesn't
 *     animate; users who federate a GIF expecting motion get a still
 *     frame. Detection-time disqualification belongs in
 *     `Photo_Post::block_resolves_locally()`, not here.
 */
class Photo_Post_Atmosphere {

	/**
	 * Hard cap on images per `app.bsky.embed.images` embed.
	 *
	 * AT Protocol's lexicon caps the `images` array at 4
	 * ({@link https://github.com/bluesky-social/atproto/blob/main/lexicons/app/bsky/embed/images.json}).
	 * Posts with more resolvable image attachments emit the first
	 * four and fire `fosse_photo_post_atmosphere_overflow` with the
	 * remainder so a future projector can decide how to recover
	 * (split into a thread, fall back to a link card, etc.).
	 *
	 * @var int
	 */
	public const MAX_IMAGES = 4;

	/**
	 * Bluesky's per-record character budget for `text`.
	 *
	 * Atmosphere's transformer already clamps short-form text to 300
	 * graphemes; we re-truncate after stripping image markup because
	 * the strip changes the byte count.
	 *
	 * @var int
	 */
	private const TEXT_BUDGET = 300;

	/**
	 * Register the Atmosphere filter callbacks. Safe to call more than
	 * once per request — WordPress dedupes identical callable-as-array
	 * registrations.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_filter( 'atmosphere_is_short_form_post', array( self::class, 'filter_is_short_form_post' ), 10, 2 );
		\add_filter( 'atmosphere_transform_bsky_post', array( self::class, 'filter_transform_bsky_post' ), 10, 3 );
	}

	/**
	 * Force the short-form path for photo posts.
	 *
	 * Defaults to whatever Atmosphere's discriminator already returned
	 * — only upgrades a `false` to `true` when the post is a photo
	 * post. Never downgrades: an explicit upstream `true` (untitled
	 * post, post-format-status, etc.) stays `true`.
	 *
	 * @param mixed $is_short Whether Atmosphere's default classification is short-form.
	 * @param mixed $post     The post being evaluated (Atmosphere passes a WP_Post; defensive cast).
	 * @return bool
	 */
	public static function filter_is_short_form_post( $is_short, $post ): bool {
		if ( $post instanceof WP_Post && Photo_Post::is_photo_post( $post ) ) {
			return true;
		}

		return (bool) $is_short;
	}

	/**
	 * Replace the record's `text` and `embed` for photo posts.
	 *
	 * Only acts when:
	 *   - `$post` is a `WP_Post`,
	 *   - it's a photo post per the shared discriminator,
	 *   - Atmosphere's composition context indicates a short-form
	 *     root record (`strategy === 'short-form'` and not a thread
	 *     reply).
	 *
	 * The strategy / thread-reply gate is defensive: a photo post
	 * forces `is_short_form_post() === true` via
	 * {@see self::filter_is_short_form_post()}, so the long-form
	 * branches never run. If a future Atmosphere change reroutes
	 * short-form through a thread shape, this gate prevents us from
	 * rewriting every entry in that thread.
	 *
	 * When at least one image blob uploads cleanly, the record's
	 * `text` is replaced with the plain-text caption
	 * ({@see Photo_Post::caption_text()}), facets are re-extracted
	 * against the new text, and `embed` becomes
	 * `app.bsky.embed.images` with up to {@see self::MAX_IMAGES}
	 * entries. Each image carries `alt` (from
	 * `_wp_attachment_image_alt` postmeta) and `aspectRatio` (from
	 * `wp_get_attachment_metadata()`).
	 *
	 * If every blob upload fails (network error, all attachments
	 * exceed AT Protocol's 1 MB blob cap, etc.) the record is
	 * returned unchanged — Atmosphere ships the short-form post body
	 * with no embed, which is a graceful degradation: the user's
	 * caption still federates, just without the image grid.
	 *
	 * @param array $record  Bsky post record under construction.
	 * @param mixed $post    The post being transformed.
	 * @param mixed $context Atmosphere's composition context.
	 * @return array Mutated record (or the input unchanged when no projection applies).
	 */
	public static function filter_transform_bsky_post( array $record, $post, $context = array() ): array {
		if ( ! $post instanceof WP_Post ) {
			return $record;
		}

		if ( ! Photo_Post::is_photo_post( $post ) ) {
			return $record;
		}

		$strategy        = \is_array( $context ) && isset( $context['strategy'] ) ? (string) $context['strategy'] : '';
		$is_thread_reply = \is_array( $context ) && ! empty( $context['is_thread_reply'] );

		if ( 'short-form' !== $strategy || $is_thread_reply ) {
			return $record;
		}

		$image_ids = Photo_Post::collect_image_attachment_ids( $post );
		if ( empty( $image_ids ) ) {
			return $record;
		}

		$attached = array();
		$overflow = array();
		$idx      = 0;

		foreach ( $image_ids as $attachment_id ) {
			if ( $idx >= self::MAX_IMAGES ) {
				$overflow[] = $attachment_id;
				continue;
			}

			$blob = self::upload_blob( $attachment_id );
			if ( null === $blob ) {
				continue;
			}

			$image = array(
				'image' => $blob,
				'alt'   => self::get_alt_text( $attachment_id ),
			);

			$aspect_ratio = self::get_aspect_ratio( $attachment_id );
			if ( null !== $aspect_ratio ) {
				$image['aspectRatio'] = $aspect_ratio;
			}

			$attached[] = $image;
			++$idx;
		}

		if ( empty( $attached ) ) {
			// Every upload failed — bail without mutating the record so
			// Atmosphere ships the caption with no embed (better than
			// shipping a broken images embed).
			return $record;
		}

		if ( ! empty( $overflow ) ) {
			/**
			 * Fires when a photo post has more resolvable image
			 * attachments than {@see self::MAX_IMAGES}. Subscribers
			 * can implement an overflow strategy (split into a
			 * thread, fall back to a link card, surface a notice to
			 * the user) without modifying this projector.
			 *
			 * @param WP_Post $post     The post being projected.
			 * @param int[]   $overflow Attachment ids dropped from the embed.
			 */
			\do_action( 'fosse_photo_post_atmosphere_overflow', $post, $overflow );
		}

		$caption         = Photo_Post::caption_text( $post );
		$text            = self::truncate_text( $caption );
		$record['text']  = $text;
		$record['embed'] = array(
			'$type'  => 'app.bsky.embed.images',
			'images' => $attached,
		);

		// Atmosphere's `transform()` already extracted facets against
		// the pre-strip text. Re-extract against the caption-only text
		// so byte offsets line up; drop the field if no facets exist
		// against the new text.
		$facets = self::extract_facets( $text );
		if ( empty( $facets ) ) {
			unset( $record['facets'] );
		} else {
			$record['facets'] = $facets;
		}

		return $record;
	}

	/**
	 * Upload an image attachment to the AT Protocol PDS and return the
	 * blob reference.
	 *
	 * Delegates to Atmosphere's `Post::upload_thumbnail()` —
	 * misleadingly-named today (the body is attachment-agnostic), but
	 * the only public blob-upload surface on shipped Atmosphere.
	 * Upstream PR 72 (`Automattic/wordpress-atmosphere`) renames this
	 * to `upload_image_blob()`; once that lands and the bundled copy
	 * resyncs, switch the call site below to the new name.
	 *
	 * The `fosse_photo_post_atmosphere_upload_blob` filter is the
	 * extension seam: returning a non-null value short-circuits the
	 * default delegation. Useful for sites that want to inject a
	 * different upload pipeline (a media-CDN bridge, a backfill
	 * worker that pre-uploads blobs, etc.) and for tests that need
	 * to stub the upload without hitting the live PDS.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Blob reference array (`$type`, `ref`, `mimeType`, `size`) or null on failure.
	 */
	private static function upload_blob( int $attachment_id ): ?array {
		/**
		 * Filters the AT Protocol blob upload for a photo-post image
		 * attachment.
		 *
		 * Return a non-null array to short-circuit the default
		 * Atmosphere-backed upload — the array becomes the blob ref
		 * that lands inside the `app.bsky.embed.images` entry. Return
		 * an explicit `false` to force a failure (the projector treats
		 * the attachment as if upload failed and skips it). The
		 * default `null` falls through to Atmosphere.
		 *
		 * @param mixed $pre           Pre-resolved blob ref or null to fall through.
		 * @param int   $attachment_id Attachment id being uploaded.
		 */
		$pre = \apply_filters( 'fosse_photo_post_atmosphere_upload_blob', null, $attachment_id );
		if ( null !== $pre ) {
			return \is_array( $pre ) ? $pre : null;
		}

		if ( ! \class_exists( '\Atmosphere\Transformer\Post' ) ) {
			return null;
		}

		$blob = \Atmosphere\Transformer\Post::upload_thumbnail( $attachment_id );

		return \is_array( $blob ) ? $blob : null;
	}

	/**
	 * Read an attachment's stored alt text.
	 *
	 * WordPress stores alt text in `_wp_attachment_image_alt` postmeta
	 * — set by the Media Library's "Alternative Text" field, the
	 * Block Editor's image-block alt UI, and the REST API. Returns
	 * an empty string when missing; AT Protocol's `embed.images`
	 * lexicon requires the `alt` key but accepts an empty string.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return string
	 */
	private static function get_alt_text( int $attachment_id ): string {
		$alt = \get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return \is_string( $alt ) ? $alt : '';
	}

	/**
	 * Read an image attachment's intrinsic pixel dimensions.
	 *
	 * Returns `[ 'width' => int, 'height' => int ]` for use as
	 * `aspectRatio` in `app.bsky.embed.images`. The lexicon's
	 * `aspectRatio` is documented as "intended display aspect" — AT
	 * clients use it for layout before the blob downloads, so
	 * approximate is fine; pixel-perfect intrinsic dims (from
	 * `wp_get_attachment_metadata()`) are the most reliable source.
	 *
	 * Returns null when metadata is missing or non-positive — a
	 * newly-uploaded attachment whose subsizes haven't been generated
	 * yet, or a non-image attachment that slipped through somehow.
	 *
	 * Once Atmosphere PR 72 lands and the bundled copy resyncs, this
	 * delegate can be replaced with `Post::get_attachment_aspect_ratio()`.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null `[ 'width' => int, 'height' => int ]` or null.
	 */
	private static function get_aspect_ratio( int $attachment_id ): ?array {
		$meta = \wp_get_attachment_metadata( $attachment_id );

		if ( ! \is_array( $meta ) ) {
			return null;
		}

		$width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Truncate caption text to Bluesky's per-record budget.
	 *
	 * Uses `mb_substr` because AT Protocol counts graphemes / Unicode
	 * code points, not bytes. No fancy sentence / word break — the
	 * source is already a short caption in practice, and Atmosphere's
	 * upstream short-form path uses the same plain `mb_substr`-style
	 * truncate.
	 *
	 * @param string $text Caption text.
	 * @return string Truncated to {@see self::TEXT_BUDGET} graphemes.
	 */
	private static function truncate_text( string $text ): string {
		if ( \mb_strlen( $text ) <= self::TEXT_BUDGET ) {
			return $text;
		}

		return \mb_substr( $text, 0, self::TEXT_BUDGET );
	}

	/**
	 * Re-extract Bluesky facets (URLs, mentions, hashtags) against the
	 * rewritten text.
	 *
	 * Returns an empty array when Atmosphere's `Facet` class isn't
	 * loaded (test environments without the bundled plugin, or a
	 * future structural change). Atmosphere itself stores facets as
	 * an empty array → no facets, so returning empty is the safe
	 * fallback.
	 *
	 * @param string $text Caption text.
	 * @return array Facet entries, or an empty array.
	 */
	private static function extract_facets( string $text ): array {
		if ( ! \class_exists( '\Atmosphere\Transformer\Facet' ) ) {
			return array();
		}

		$facets = \Atmosphere\Transformer\Facet::extract( $text );

		return \is_array( $facets ) ? $facets : array();
	}
}
