<?php
/**
 * Blurhash encoder + storage for image attachments.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

use kornrunner\Blurhash\Blurhash as Encoder;

/**
 * Compute, store, and inject Blurhash placeholder strings for image
 * attachments so federated ActivityPub posts emit
 * `attachment[].blurhash` — the colored-blur preview that Pixelfed,
 * Mastodon, and other fediverse clients paint while the full image
 * is still loading. Without it, federated WP photos sit on a grey
 * placeholder where native uploads paint instantly; with it, the
 * loading state matches native.
 *
 * Encoding runs at upload time via `wp_generate_attachment_metadata`,
 * deferred to cron so the upload UI returns immediately. The hash
 * is persisted as attachment postmeta ({@see self::META_KEY}) and
 * read back by the `activitypub_attachment` projector — zero
 * per-publish CPU cost, federation hot path stays cheap.
 *
 * Pure no-op when GD isn't available: the encoder needs an
 * `[r,g,b][][]` pixel array, and GD's `imagecreatefrom*` family is
 * the only host-portable way to build one. Sites running Imagick-only
 * just don't get blurhash placeholders — attachments still federate,
 * minus the field. Same posture for any other failure (unreadable
 * file, encoder exception, deleted attachment): never blocks the
 * upload, never blocks federation.
 *
 * The bundled ActivityPub `Activity::JSON_LD_CONTEXT` declares the
 * `toot:` namespace but does NOT map a `blurhash` term — a follow-up
 * one-line upstream change in `wordpress-activitypub` adds
 * `'blurhash' => 'toot:blurhash'` to make this strictly correct
 * JSON-LD. Real-world consumers (Mastodon, Pixelfed) read the
 * property at the application layer regardless, so this ships
 * working today without the upstream change.
 */
class Blurhash {

	/**
	 * Postmeta key holding the encoded blurhash for an attachment.
	 *
	 * @var string
	 */
	public const META_KEY = '_fosse_blurhash';

	/**
	 * Cron hook fired for each attachment that needs a hash computed.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'fosse_blurhash_compute';

	/**
	 * X-component count passed to the encoder. Wolt's reference
	 * recommends 4–5 for landscape and lower for portrait; 4 is the
	 * middle ground Mastodon also defaults to. Hash length grows with
	 * the product of X*Y, so 4×4 keeps the encoded string short.
	 *
	 * @var int
	 */
	private const COMPONENTS_X = 4;

	/**
	 * Y-component count. Mirror of {@see self::COMPONENTS_X}.
	 *
	 * @var int
	 */
	private const COMPONENTS_Y = 4;

	/**
	 * Image size used as the encoder's source. Blurhash encoding is
	 * O(N) over pixel count and the output is a few low-frequency DCT
	 * coefficients — feeding it a 12-megapixel original would burn
	 * CPU producing a hash that's perceptually identical to the one
	 * computed off the ~150px thumbnail. WP's `thumbnail` size is the
	 * smallest variant guaranteed to exist for every uploaded image.
	 *
	 * @var string
	 */
	private const ENCODE_SIZE = 'thumbnail';

	/**
	 * Register all hooks. Called from `fosse.php` on `init`, same
	 * posture as the sibling projectors.
	 */
	public static function register(): void {
		\add_filter( 'wp_generate_attachment_metadata', array( self::class, 'schedule_encode' ), 10, 2 );
		\add_action( self::CRON_HOOK, array( self::class, 'run_encode' ), 10, 1 );
		\add_filter( 'activitypub_attachment', array( self::class, 'inject_blurhash' ), 10, 2 );
	}

	/**
	 * Return the stored blurhash for an attachment, or null when
	 * none is stored. Trims whitespace and treats an empty string as
	 * absent so a manually-emptied postmeta doesn't leak into the
	 * federation envelope.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string|null
	 */
	public static function get( int $attachment_id ): ?string {
		$value = \get_post_meta( $attachment_id, self::META_KEY, true );
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		return '' === $value ? null : $value;
	}

	/**
	 * Persist a computed blurhash on the attachment.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $hash          Encoded blurhash string.
	 */
	public static function set( int $attachment_id, string $hash ): void {
		\update_post_meta( $attachment_id, self::META_KEY, $hash );
	}

	/**
	 * Delete any stored blurhash for an attachment. Used by the
	 * WP-CLI backfill `--force` path.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public static function delete( int $attachment_id ): void {
		\delete_post_meta( $attachment_id, self::META_KEY );
	}

	/**
	 * Compute (synchronously) the blurhash for an attachment by
	 * loading the configured size's file through GD and feeding the
	 * pixel array to the encoder. Returns null on any failure path
	 * — never throws, never warns. Used by both the cron handler
	 * and the WP-CLI backfill.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string|null
	 */
	public static function encode_from_attachment( int $attachment_id ): ?string {
		if ( ! \wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}

		if ( ! \function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}

		$path = self::resolve_encode_path( $attachment_id );
		if ( null === $path ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- local absolute path read; corrupt/missing file returns false and we handle.
		$bytes = @file_get_contents( $path );
		if ( false === $bytes || '' === $bytes ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt image returns false and we handle.
		$image = @\imagecreatefromstring( $bytes );
		if ( false === $image ) {
			return null;
		}

		try {
			$width  = \imagesx( $image );
			$height = \imagesy( $image );
			if ( $width < 1 || $height < 1 ) {
				return null;
			}

			$pixels = array();
			for ( $y = 0; $y < $height; $y++ ) {
				$row = array();
				for ( $x = 0; $x < $width; $x++ ) {
					$index  = \imagecolorat( $image, $x, $y );
					$colors = \imagecolorsforindex( $image, $index );
					$row[]  = array( $colors['red'], $colors['green'], $colors['blue'] );
				}
				$pixels[] = $row;
			}

			$hash = Encoder::encode( $pixels, self::COMPONENTS_X, self::COMPONENTS_Y );
			return \is_string( $hash ) && '' !== $hash ? $hash : null;
		} catch ( \Throwable $e ) {
			return null;
		}
		// PHP 8.0+ collects GdImage when it leaves scope; no manual imagedestroy() needed.
	}

	/**
	 * Resolve the absolute filesystem path of the size we use as
	 * encoder input, falling back to the original when the
	 * intermediate doesn't exist (small uploads that core didn't
	 * generate downscales for).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string|null Absolute file path, or null when nothing readable resolved.
	 */
	private static function resolve_encode_path( int $attachment_id ): ?string {
		$sized = \image_get_intermediate_size( $attachment_id, self::ENCODE_SIZE );
		if ( \is_array( $sized ) && ! empty( $sized['path'] ) ) {
			$upload = \wp_upload_dir();
			if ( \is_array( $upload ) && ! empty( $upload['basedir'] ) ) {
				$path = \trailingslashit( $upload['basedir'] ) . $sized['path'];
				if ( \is_readable( $path ) ) {
					return $path;
				}
			}
		}

		$path = \get_attached_file( $attachment_id );
		return ( \is_string( $path ) && \is_readable( $path ) ) ? $path : null;
	}

	/**
	 * `wp_generate_attachment_metadata` filter callback. Schedules
	 * a single-event cron run to compute the blurhash for image
	 * attachments. The filter return value is the metadata unchanged
	 * — we use the hook purely as an "image is ready" notifier.
	 *
	 * @param array $metadata      Attachment metadata as built by WP.
	 * @param int   $attachment_id Attachment post ID.
	 * @return array
	 */
	public static function schedule_encode( $metadata, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id > 0 && \wp_attachment_is_image( $attachment_id ) ) {
			self::schedule( $attachment_id );
		}
		return $metadata;
	}

	/**
	 * Queue (or skip, if already queued) a cron event to compute
	 * the blurhash for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public static function schedule( int $attachment_id ): void {
		if ( false !== \wp_next_scheduled( self::CRON_HOOK, array( $attachment_id ) ) ) {
			return;
		}
		\wp_schedule_single_event( \time(), self::CRON_HOOK, array( $attachment_id ) );
	}

	/**
	 * Cron callback: compute and store the blurhash. No-op when a
	 * hash is already stored; callers wanting a re-encode delete
	 * the postmeta first ({@see self::delete()}).
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public static function run_encode( int $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 ) {
			return;
		}
		if ( null !== self::get( $attachment_id ) ) {
			return;
		}
		$hash = self::encode_from_attachment( $attachment_id );
		if ( null !== $hash ) {
			self::set( $attachment_id, $hash );
		}
	}

	/**
	 * `activitypub_attachment` filter callback. Injects `blurhash`
	 * into image attachment arrays when postmeta exists. No-op on
	 * anything else (non-image attachments, missing meta, malformed
	 * arrays) so non-photo federation paths are untouched.
	 *
	 * @param mixed $attachment    The attachment array as built by bundled AP.
	 * @param mixed $attachment_id The attachment post ID (mixed because the upstream filter is loosely typed).
	 * @return mixed
	 */
	public static function inject_blurhash( $attachment, $attachment_id ) {
		if ( ! \is_array( $attachment ) ) {
			return $attachment;
		}
		if ( 'Image' !== ( $attachment['type'] ?? '' ) ) {
			return $attachment;
		}
		$hash = self::get( (int) $attachment_id );
		if ( null === $hash ) {
			return $attachment;
		}
		$attachment['blurhash'] = $hash;
		return $attachment;
	}
}
