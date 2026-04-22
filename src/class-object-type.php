<?php
/**
 * Cross-network object-type projector for FOSSE.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

use WP_Post;

/**
 * Translates the single `fosse_object_type` option into per-network
 * filter answers so the Atmosphere and ActivityPub backends stay in
 * sync without FOSSE reaching into either plugin's composition logic.
 *
 * Current option values:
 * - `note`                    — force short-form / Note everywhere.
 * - `wordpress-post-format`   — defer to each network's own discriminator.
 * - unset                     — same as `wordpress-post-format`.
 */
class Object_Type {

	/**
	 * Register the two pass-through/force filters. Idempotent-safe.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_filter( 'atmosphere_is_short_form_post', array( self::class, 'filter_atmosphere' ), 10, 2 );
		\add_filter( 'activitypub_post_object_type', array( self::class, 'filter_ap' ), 10, 2 );
	}

	/**
	 * Project the option onto Atmosphere's short-form discriminator.
	 *
	 * @param bool    $is_short Upstream-computed short-form default.
	 * @param WP_Post $post     The post being transformed (unused).
	 * @return bool Forced true when the FOSSE option is `note`, else input.
	 */
	public static function filter_atmosphere( bool $is_short, WP_Post $post ): bool {
		unset( $post );

		if ( 'note' === \get_option( 'fosse_object_type' ) ) {
			return true;
		}

		return $is_short;
	}

	/**
	 * Project the option onto ActivityPub's object type.
	 *
	 * @param string  $type Upstream-computed object type (Note/Article/Page).
	 * @param WP_Post $post The post being transformed (unused).
	 * @return string Forced `Note` when the FOSSE option is `note`, else input.
	 */
	public static function filter_ap( string $type, WP_Post $post ): string {
		unset( $post );

		if ( 'note' === \get_option( 'fosse_object_type' ) ) {
			return 'Note';
		}

		return $type;
	}
}
