<?php
/**
 * Cross-network post-type projector for FOSSE.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Projects ActivityPub's `activitypub_support_post_types` option into
 * Atmosphere's `atmosphere_syncable_post_types` filter, so the post
 * types a user ticks in AP's settings also federate to Bluesky via
 * Atmosphere without a second configuration step.
 *
 * Deliberately asymmetric with Object_Type: object-type semantics
 * ("force short-form everywhere" vs "defer to each network") are
 * FOSSE-specific, so Object_Type owns its own option and drives two
 * filters. Post-type selection is not FOSSE-specific — it's "which
 * post types federate," which is exactly what AP already stores.
 * Reusing AP's option avoids a second source of truth that would
 * silently override AP's admin UI on read.
 */
class Post_Types {

	/**
	 * ActivityPub option holding the per-site post-type allowlist.
	 *
	 * @var string
	 */
	private const AP_OPTION = 'activitypub_support_post_types';

	/**
	 * Fallback when the option is missing or returns a non-array value.
	 * Matches AP's and Atmosphere's upstream defaults so first-install
	 * behavior is unchanged by FOSSE's presence.
	 *
	 * @var array<string>
	 */
	private const DEFAULT_TYPES = array( 'post' );

	/**
	 * Register the Atmosphere-side projection. Safe to call more than once
	 * per request — WordPress dedupes identical callable-as-array registrations.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! \class_exists( '\Activitypub\Activitypub' ) ) {
			return;
		}

		\add_filter( 'atmosphere_syncable_post_types', array( self::class, 'filter_atmosphere' ) );
	}

	/**
	 * Project AP's stored post-type list onto Atmosphere's filter.
	 *
	 * AP's option is the source of truth for the *option-derived* list, so
	 * FOSSE replaces the value Atmosphere built from its own option rather
	 * than appending to it — a site-level selection belongs in AP's settings.
	 *
	 * Native opt-ins via `\add_post_type_support( $type, 'atmosphere' )` are a
	 * documented public API of Atmosphere's upstream `get_supported()`, which
	 * merges `\get_post_types_by_support( 'atmosphere' )` before this filter
	 * runs. Those are merged back in here so a theme or plugin opting a post
	 * type in natively still federates — discarding them would silently break
	 * that contract. The merge is additive: an empty AP selection (user
	 * unchecked everything) still yields no AP-derived types, with native
	 * supports added on top.
	 *
	 * @param array<string> $types Upstream list from Atmosphere (replaced).
	 * @return array<string> AP's stored list merged with native `atmosphere`
	 *                      post-type supports, deduped and re-indexed. Falls
	 *                      back to the default when AP's option is unset or
	 *                      corrupted (non-array) — guards against a misbehaving
	 *                      `option_activitypub_support_post_types` filter
	 *                      returning a scalar.
	 */
	public static function filter_atmosphere( array $types ): array {
		unset( $types );

		$stored    = \get_option( self::AP_OPTION, self::DEFAULT_TYPES );
		$ap_stored = \is_array( $stored ) ? $stored : self::DEFAULT_TYPES;

		// Filter to strings before dedup so a misbehaving
		// `option_activitypub_support_post_types` filter that returns an
		// array containing non-string entries (e.g. nested arrays from a
		// rogue option_filter) doesn't trip `array_unique`'s implicit
		// `__toString` cast with an `Array to string conversion` warning.
		$ap_strings = \array_filter( $ap_stored, '\is_string' );

		return \array_values(
			\array_unique(
				\array_merge( $ap_strings, \get_post_types_by_support( 'atmosphere' ) )
			)
		);
	}
}
