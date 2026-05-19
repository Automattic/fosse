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
 * Failure-mode posture:
 *
 *   - If the *featured image* upload fails, the projector returns
 *     the record unchanged. Featured Image is the post's hero shot;
 *     silently shipping a gallery missing its hero shot is the
 *     worst failure mode for a photo feature.
 *   - If a non-featured image upload fails, that one attachment is
 *     dropped from the embed and an `error_log` line is written so
 *     operators can correlate with PDS errors.
 *   - If *every* upload fails, the projector returns the record
 *     unchanged so Atmosphere ships short-form text with no embed.
 *     When the post body is also empty (the canonical Featured-Image-
 *     only photo post), the projector additionally logs the empty-
 *     record outcome so a publish failure isn't silent.
 *   - Filter / upload exceptions are caught per-attachment so one
 *     misbehaving listener can't crater the entire federation event.
 *   - Synchronous blob uploads are bounded by
 *     `fosse_photo_post_atmosphere_upload_budget_seconds` (default
 *     30s) so a degraded PDS can't tie up a publish request for
 *     minutes.
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
	 * Intentionally public so downstream overflow listeners and
	 * tests can compare attachment counts against the cap without
	 * duplicating the magic number.
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
	 * Default wall-time budget for all synchronous blob uploads in a
	 * single federation event, in seconds. Overridable via the
	 * `fosse_photo_post_atmosphere_upload_budget_seconds` filter.
	 *
	 * @var int
	 */
	private const DEFAULT_UPLOAD_BUDGET_SECONDS = 30;

	/**
	 * Register the Atmosphere filter callbacks.
	 *
	 * Safe to call more than once per request because
	 * `add_filter()` keys callbacks by `(callable identity, priority)`
	 * — identical static-callable registrations collide on the same
	 * key and don't stack.
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
	 * Failure handling — see class docblock for the full rationale:
	 *   - Featured-image upload failure → return unchanged. A
	 *     gallery missing its hero shot is worse than no gallery.
	 *   - Non-featured upload failure → drop that attachment, log,
	 *     keep going.
	 *   - Every upload failed → return unchanged so Atmosphere ships
	 *     short-form text; if the body is also empty, log it.
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

		// `collect_image_attachment_ids()` contractually orders the
		// featured image first when it exists. Capture that decision
		// here so an early upload failure on the hero shot can bail
		// the whole projection rather than ship a gallery with the
		// canonical image silently missing.
		$featured_id      = (int) \get_post_thumbnail_id( $post );
		$has_featured     = $featured_id > 0 && ! empty( $image_ids ) && $image_ids[0] === $featured_id;
		$budget_seconds   = self::upload_budget_seconds( $post );
		$budget_deadline  = $budget_seconds > 0 ? \microtime( true ) + (float) $budget_seconds : null;
		$attached         = array();
		$overflow         = array();
		$attempted        = 0;
		$budget_exhausted = false;

		foreach ( $image_ids as $attachment_id ) {
			if ( $attempted >= self::MAX_IMAGES ) {
				$overflow[] = $attachment_id;
				continue;
			}

			if ( null !== $budget_deadline && \microtime( true ) >= $budget_deadline ) {
				$budget_exhausted = true;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reliability signal for operators; behind a budget that defaults to 30s.
				\error_log(
					\sprintf(
						'[fosse:photo-post-atmosphere] upload budget (%ds) exhausted on post %d; dropping remaining attachments.',
						$budget_seconds,
						$post->ID
					)
				);
				break;
			}

			$blob = self::upload_blob( $attachment_id );

			if ( null === $blob ) {
				if ( $has_featured && 0 === $attempted ) {
					// Featured image is the hero shot — refuse to ship
					// a gallery missing it. Return unchanged so
					// Atmosphere falls back to short-form text and the
					// operator gets a log line to act on.
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reliability signal for operators.
					\error_log(
						\sprintf(
							'[fosse:photo-post-atmosphere] featured image upload failed on post %d; aborting photo projection.',
							$post->ID
						)
					);
					return $record;
				}

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reliability signal for operators.
				\error_log(
					\sprintf(
						'[fosse:photo-post-atmosphere] attachment %d upload failed on post %d; dropping from embed.',
						$attachment_id,
						$post->ID
					)
				);

				++$attempted;
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
			++$attempted;
		}

		if ( empty( $attached ) ) {
			// Every upload failed. Return unchanged so Atmosphere ships
			// the caption with no embed (better than a malformed
			// embed); if the body is also empty, log so the silent
			// "user federated literally nothing" outcome surfaces.
			if ( '' === \trim( (string) ( $record['text'] ?? '' ) ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reliability signal for operators.
				\error_log(
					\sprintf(
						'[fosse:photo-post-atmosphere] all uploads failed and post %d has empty body; record will federate as an empty post.',
						$post->ID
					)
				);
			}
			return $record;
		}

		// Budget-exhausted attachments aren't surfaced via the overflow
		// action — overflow is documented as "more images than the
		// lexicon cap" and a downstream listener can't usefully retry
		// budget drops in the same federation event. The error_log
		// line above is the operator-facing signal.
		unset( $budget_exhausted );

		if ( ! empty( $overflow ) ) {
			self::fire_overflow_action( $post, $overflow );
		}

		$caption        = Photo_Post::caption_text( $post );
		$text           = self::truncate_text( $caption );
		$record['text'] = $text;

		$record['embed'] = array(
			'$type'  => 'app.bsky.embed.images',
			'images' => $attached,
		);

		// Re-extract facets against the rewritten caption so byte
		// offsets line up with the new text. Extract against the
		// *untruncated* caption first, then drop any facet whose byte
		// range falls past the truncated length — protects URLs that
		// straddle the 300-grapheme boundary.
		$new_facets = self::extract_facets_for_text( $caption, $text );
		if ( empty( $new_facets ) ) {
			unset( $record['facets'] );
		} else {
			$record['facets'] = $new_facets;
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
	 * extension seam. Three return shapes are honored:
	 *
	 *   - `null` (default): fall through to Atmosphere's
	 *     `upload_thumbnail()`.
	 *   - A validly-shaped blob-ref array: short-circuit with a
	 *     successful upload. The structure is validated before use.
	 *   - Anything else (`false`, `WP_Error`, string, int, malformed
	 *     array): short-circuit with a failed upload; the attachment
	 *     is dropped from the embed and Atmosphere is NOT called.
	 *     Use this when you want to suppress a specific attachment
	 *     without paying Atmosphere's upload cost.
	 *
	 * Filter exceptions are caught per-attachment so one misbehaving
	 * listener can't crater the entire federation event; the bad
	 * attachment is treated as a failed upload and the loop continues.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Validated blob reference array, or null on any failure.
	 */
	private static function upload_blob( int $attachment_id ): ?array {
		try {
			/**
			 * Filters the AT Protocol blob upload for a photo-post
			 * image attachment.
			 *
			 * Three return shapes are honored:
			 *
			 *   - `null` (default): fall through to Atmosphere's
			 *     `upload_thumbnail()`.
			 *   - A validly-shaped blob-ref array (`$type === 'blob'`,
			 *     `ref`, `mimeType` like `image/jpeg`, `size`):
			 *     short-circuit with a successful upload. The return
			 *     is validated structurally; a malformed array is
			 *     treated as a failed upload.
			 *   - Anything else (`false`, `WP_Error`, string, int):
			 *     short-circuit with a failed upload; the attachment
			 *     is dropped and Atmosphere is NOT called. Use this
			 *     when you want to suppress a specific attachment
			 *     without paying Atmosphere's upload cost.
			 *
			 * @param mixed $pre           Pre-resolved blob ref or null to fall through.
			 * @param int   $attachment_id Attachment id being uploaded.
			 */
			$pre = \apply_filters( 'fosse_photo_post_atmosphere_upload_blob', null, $attachment_id );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surface third-party filter crashes so operators can spot the bad plugin.
			\error_log(
				\sprintf(
					'[fosse:photo-post-atmosphere] fosse_photo_post_atmosphere_upload_blob listener threw for attachment %d: %s',
					$attachment_id,
					$e->getMessage()
				)
			);
			return null;
		}

		if ( \is_array( $pre ) ) {
			return self::is_valid_blob_ref( $pre ) ? $pre : null;
		}

		if ( null !== $pre ) {
			// Non-null non-array (`false`, `WP_Error`, string, int)
			// = explicit "skip this attachment, don't fall through to
			// Atmosphere." Useful for sites that want to suppress a
			// specific attachment without paying Atmosphere's upload
			// cost, and for tests that stub uploads without hitting
			// the live PDS.
			return null;
		}

		if ( ! \class_exists( '\Atmosphere\Transformer\Post' ) ) {
			return null;
		}

		try {
			$blob = \Atmosphere\Transformer\Post::upload_thumbnail( $attachment_id );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reliability signal: surface unexpected upload-pipeline crashes.
			\error_log(
				\sprintf(
					'[fosse:photo-post-atmosphere] Atmosphere upload_thumbnail threw for attachment %d: %s',
					$attachment_id,
					$e->getMessage()
				)
			);
			return null;
		}

		if ( ! \is_array( $blob ) ) {
			return null;
		}

		return self::is_valid_blob_ref( $blob ) ? $blob : null;
	}

	/**
	 * Structural validation for an AT Protocol blob reference.
	 *
	 * Required keys: `$type === 'blob'`, `mimeType` (image/*),
	 * `size` (positive int ≤ 1 MB, matching Atmosphere's blob cap),
	 * and `ref` (a string CID or an associative array containing a
	 * `$link` string — both shapes appear in the wild). Anything
	 * else is treated as a malformed return and rejected.
	 *
	 * Protects the federation envelope from a third-party filter that
	 * returns an arbitrary array — without validation, a forged ref
	 * pointing at unauthorized PDS content could ship unchanged.
	 *
	 * @param array $blob Candidate blob reference.
	 * @return bool True when the array matches the AT blob-ref shape.
	 */
	private static function is_valid_blob_ref( array $blob ): bool {
		if ( 'blob' !== ( $blob['$type'] ?? null ) ) {
			return false;
		}

		$mime = $blob['mimeType'] ?? null;
		if ( ! \is_string( $mime ) || 0 !== \strpos( $mime, 'image/' ) ) {
			return false;
		}

		$size = $blob['size'] ?? null;
		if ( ! \is_int( $size ) || $size <= 0 || $size > 1_000_000 ) {
			return false;
		}

		if ( ! isset( $blob['ref'] ) ) {
			return false;
		}
		$ref = $blob['ref'];
		if ( \is_string( $ref ) ) {
			return '' !== $ref;
		}
		if ( \is_array( $ref ) && isset( $ref['$link'] ) && \is_string( $ref['$link'] ) && '' !== $ref['$link'] ) {
			return true;
		}

		return false;
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
	 * Resolve the wall-time budget for synchronous blob uploads.
	 *
	 * Each `Post::upload_thumbnail()` call can take up to 60s on a
	 * degraded PDS; four of those in a row exceed typical PHP
	 * execution-time limits. The default 30s budget keeps the
	 * publish request from hanging when the PDS is slow.
	 *
	 * Filter return is coerced to non-negative int. Zero disables
	 * the budget (uploads run to completion regardless of wall
	 * time) — useful for CLI / background-job contexts that don't
	 * share the publish request's time budget.
	 *
	 * @param WP_Post $post The post being projected.
	 * @return int Budget in seconds (zero disables the budget).
	 */
	private static function upload_budget_seconds( WP_Post $post ): int {
		/**
		 * Filters the wall-time budget for synchronous photo-post
		 * blob uploads in a single federation event.
		 *
		 * @param int     $seconds Default budget in seconds.
		 * @param WP_Post $post    The post being projected.
		 */
		$seconds = \apply_filters( 'fosse_photo_post_atmosphere_upload_budget_seconds', self::DEFAULT_UPLOAD_BUDGET_SECONDS, $post );

		$seconds = (int) $seconds;
		return $seconds > 0 ? $seconds : 0;
	}

	/**
	 * Fire the overflow action with exception isolation.
	 *
	 * The action's documented use cases (split into a thread, fall
	 * back to a link card, surface a notice to the user) are I/O-
	 * flavored — a slow or throwing listener shouldn't crater the
	 * already-built embed.
	 *
	 * @param WP_Post $post     The post being projected.
	 * @param int[]   $overflow Attachment ids dropped from the embed.
	 * @return void
	 */
	private static function fire_overflow_action( WP_Post $post, array $overflow ): void {
		try {
			/**
			 * Fires when a photo post has more resolvable image
			 * attachments than {@see self::MAX_IMAGES}. Subscribers
			 * can implement an overflow strategy (split into a
			 * thread, fall back to a link card, surface a notice to
			 * the user) without modifying this projector.
			 *
			 * Listeners must be fast and non-throwing — exceptions
			 * are caught here so a misbehaving subscriber can't
			 * crater the already-built embed.
			 *
			 * @param WP_Post $post     The post being projected.
			 * @param int[]   $overflow Attachment ids dropped from the embed.
			 */
			\do_action( 'fosse_photo_post_atmosphere_overflow', $post, $overflow );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reliability signal: surface bad overflow listeners without breaking federation.
			\error_log(
				\sprintf(
					'[fosse:photo-post-atmosphere] fosse_photo_post_atmosphere_overflow listener threw for post %d: %s',
					$post->ID,
					$e->getMessage()
				)
			);
		}
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
	 * Re-extract Bluesky facets and align them with the truncated text.
	 *
	 * Extracts facets against the *untruncated* caption first, then
	 * drops any whose byte range falls past the truncated length.
	 * This protects URLs that straddle the 300-grapheme truncation
	 * boundary — without it, a URL starting before character 300 with
	 * its closing offset past 300 would produce either a silent
	 * facet drop, a facet pointing at a wrong target, or an offset
	 * that fails PDS validation entirely.
	 *
	 * Exceptions from `Facet::extract()` are caught — better to ship
	 * a record without facets than to fail the whole federation event
	 * over a regex hiccup.
	 *
	 * Returns an empty array when Atmosphere's `Facet` class isn't
	 * loaded (test environments, future structural change).
	 *
	 * @param string $caption_full Full caption text (pre-truncation).
	 * @param string $text_final   Final record text (post-truncation).
	 * @return array Facet entries usable against `$text_final`, or an empty array.
	 */
	private static function extract_facets_for_text( string $caption_full, string $text_final ): array {
		if ( ! \class_exists( '\Atmosphere\Transformer\Facet' ) ) {
			return array();
		}

		try {
			$facets = \Atmosphere\Transformer\Facet::extract( $caption_full );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reliability signal: surface unexpected facet-extractor crashes.
			\error_log(
				\sprintf(
					'[fosse:photo-post-atmosphere] Facet::extract threw: %s',
					$e->getMessage()
				)
			);
			return array();
		}

		if ( ! \is_array( $facets ) || empty( $facets ) ) {
			return array();
		}

		// When the caption fit inside the budget the truncated string
		// is byte-identical to the untruncated one; the byte-range
		// filter below is a no-op in that case but cheap.
		$final_byte_length = \strlen( $text_final );

		$kept = array();
		foreach ( $facets as $facet ) {
			if ( ! \is_array( $facet ) ) {
				continue;
			}
			$index = $facet['index'] ?? null;
			if ( ! \is_array( $index ) ) {
				continue;
			}
			$byte_end = isset( $index['byteEnd'] ) ? (int) $index['byteEnd'] : -1;
			if ( $byte_end < 0 || $byte_end > $final_byte_length ) {
				continue;
			}
			$kept[] = $facet;
		}

		return $kept;
	}
}
