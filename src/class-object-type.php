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
	 * Falls back to the legacy `fosse_object_type` option when the
	 * canonical-options migrator has not yet completed. The migrator
	 * normally runs at `init` priority 5 (before this filter at priority 10),
	 * so the fallback should be unreachable in practice — it exists as
	 * defense in depth for the narrow window between FOSSE bootstrap
	 * registering this filter and the migrator copying the legacy value
	 * to the canonical option (e.g. an autoloader edge case where the
	 * migrator class is missing but Object_Type loaded).
	 *
	 * $post type is loose on purpose — upstream callers always pass a WP_Post
	 * in normal filter contexts, but loosening the hint keeps the bridge
	 * defensive if the upstream filter contract ever drifts.
	 *
	 * @param bool  $is_short Upstream-computed short-form default.
	 * @param mixed $post     The post being transformed (unused).
	 * @return bool Forced true when AP says `'note'` (or, pre-migration,
	 *              when the legacy FOSSE option says `'note'`), else input.
	 */
	public static function filter_atmosphere( bool $is_short, $post ): bool {
		unset( $post );

		if ( self::NOTE_VALUE === \get_option( self::AP_OBJECT_TYPE_OPTION ) ) {
			return true;
		}

		if ( '1' !== (string) \get_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION, '' )
			&& self::NOTE_VALUE === \get_option( 'fosse_object_type' )
		) {
			return true;
		}

		return $is_short;
	}
}
