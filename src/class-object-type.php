<?php
/**
 * Cross-network object-type bridge for FOSSE.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Bridges ActivityPub's `activitypub_object_type` option onto Atmosphere's
 * `atmosphere_is_short_form_post` filter so a site that wants short-form /
 * Note-style posts everywhere only has to set the canonical AP option in
 * one place — both networks then agree on the shape.
 *
 * The option is owned by ActivityPub: AP's own settings UI writes it, AP
 * reads it directly when picking the outbound `Note`/`Article` type, and
 * `Canonical_Options_Migrator` moved any pre-existing `fosse_object_type`
 * value into it on first run. FOSSE no longer keeps a parallel option.
 *
 * Recognized AP option values (per upstream):
 * - `note`                    — short-form / Note. Drives Atmosphere short-form too.
 * - `wordpress-post-format`   — defer to each network's own discriminator.
 * - default (`Article`)       — pass-through.
 */
class Object_Type {

	/**
	 * ActivityPub option whose value drives the bridge.
	 *
	 * @var string
	 */
	private const AP_OBJECT_TYPE_OPTION = 'activitypub_object_type';

	/**
	 * AP option value that means "short-form / Note everywhere".
	 *
	 * @var string
	 */
	private const NOTE_VALUE = 'note';

	/**
	 * Register the Atmosphere short-form bridge filter. Safe to call more
	 * than once per request — WordPress dedupes identical
	 * callable-as-array registrations.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_filter( 'atmosphere_is_short_form_post', array( self::class, 'filter_atmosphere' ), 10, 2 );
	}

	/**
	 * Project ActivityPub's object-type option onto Atmosphere's short-form
	 * discriminator.
	 *
	 * $post type is loose on purpose — upstream callers always pass a WP_Post
	 * in normal filter contexts, but loosening the hint keeps the bridge
	 * defensive if the upstream filter contract ever drifts.
	 *
	 * @param bool  $is_short Upstream-computed short-form default.
	 * @param mixed $post     The post being transformed (unused).
	 * @return bool Forced true when the AP option is `'note'`, else input.
	 */
	public static function filter_atmosphere( bool $is_short, $post ): bool {
		unset( $post );

		if ( self::NOTE_VALUE === \get_option( self::AP_OBJECT_TYPE_OPTION ) ) {
			return true;
		}

		return $is_short;
	}
}
