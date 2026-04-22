<?php
/**
 * Cross-network object-type projector for FOSSE.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Translates the single `fosse_object_type` option into per-network
 * filter answers so the Atmosphere and ActivityPub backends stay in
 * sync without FOSSE reaching into either plugin's composition logic.
 *
 * Current option values:
 * - `note`                    — force short-form / Note everywhere.
 * - `wordpress-post-format`   — defer to each network's own discriminator.
 * - unset                     — same as `wordpress-post-format`.
 * - anything else             — pass-through (treated as the default).
 */
class Object_Type {

	/**
	 * Site option name holding the projected mode.
	 *
	 * @var string
	 */
	private const OPTION = 'fosse_object_type';

	/**
	 * Option value that forces short-form everywhere.
	 *
	 * @var string
	 */
	private const MODE_NOTE = 'note';

	/**
	 * ActivityPub object type returned when forcing short-form.
	 *
	 * @var string
	 */
	private const AP_TYPE_NOTE = 'Note';

	/**
	 * Register the two pass-through/force filters. Safe to call more than once
	 * per request — WordPress dedupes identical callable-as-array registrations.
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
	 * $post type is loose on purpose — upstream callers always pass a WP_Post
	 * in normal filter contexts, but loosening the hint keeps the projector
	 * defensive if the upstream filter contract ever drifts.
	 *
	 * @param bool  $is_short Upstream-computed short-form default.
	 * @param mixed $post     The post being transformed (unused).
	 * @return bool Forced true when the FOSSE option is `note`, else input.
	 */
	public static function filter_atmosphere( bool $is_short, $post ): bool {
		unset( $post );

		if ( self::MODE_NOTE === \get_option( self::OPTION ) ) {
			return true;
		}

		return $is_short;
	}

	/**
	 * Project the option onto ActivityPub's object type.
	 *
	 * $post type is loose on purpose — see filter_atmosphere().
	 *
	 * @param string $type Upstream-computed object type (Note/Article/Page).
	 * @param mixed  $post The post being transformed (unused).
	 * @return string Forced `Note` when the FOSSE option is `note`, else input.
	 */
	public static function filter_ap( string $type, $post ): string {
		unset( $post );

		if ( self::MODE_NOTE === \get_option( self::OPTION ) ) {
			return self::AP_TYPE_NOTE;
		}

		return $type;
	}
}
