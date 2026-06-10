<?php
/**
 * Test stub for ActivityPub's native Blurhash class.
 *
 * Newer ActivityPub ships `\Activitypub\Blurhash` (FOSSE's encoder
 * upstreamed). The bundled copy in this checkout may predate it, so the
 * hand-off tests load this minimal stand-in — guarded so that once the
 * bundled ActivityPub carries the real class, the stub silently steps
 * aside and the same tests exercise the genuine implementation.
 *
 * @package Automattic\Fosse
 */

namespace Activitypub;

if ( ! class_exists( __NAMESPACE__ . '\Blurhash' ) ) {
	/**
	 * Minimal mirror of AP's Blurhash storage surface.
	 */
	class Blurhash {

		/**
		 * AP's attachment postmeta key — matches the real class.
		 *
		 * @var string
		 */
		public const META_KEY = '_activitypub_blurhash';

		/**
		 * Read a stored blurhash, or null when absent.
		 *
		 * @param int $attachment_id Attachment post ID.
		 * @return string|null
		 */
		public static function get( int $attachment_id ): ?string {
			$value = \get_post_meta( $attachment_id, self::META_KEY, true );
			if ( ! \is_string( $value ) || '' === $value ) {
				return null;
			}
			return $value;
		}

		/**
		 * Persist a blurhash on the attachment.
		 *
		 * @param int    $attachment_id Attachment post ID.
		 * @param string $hash          Encoded blurhash string.
		 * @return void
		 */
		public static function set( int $attachment_id, string $hash ): void {
			\update_post_meta( $attachment_id, self::META_KEY, $hash );
		}
	}
}
