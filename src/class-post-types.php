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
	 * Replace Atmosphere's post-type list with AP's stored value.
	 *
	 * The upstream default is intentionally discarded: FOSSE's contract is
	 * that AP's option is the single source of truth, so any site-level
	 * post-type change belongs in AP's settings, not in AT's filter.
	 *
	 * @param array<string> $types Upstream default from Atmosphere (unused).
	 * @return array<string> AP's stored list, or the default when the option
	 *                      is unset or corrupted (non-array) — guards against
	 *                      a misbehaving `option_activitypub_support_post_types`
	 *                      filter returning a scalar.
	 */
	public static function filter_atmosphere( array $types ): array {
		unset( $types );

		$stored = \get_option( self::AP_OPTION, self::DEFAULT_TYPES );

		return \is_array( $stored ) ? $stored : self::DEFAULT_TYPES;
	}
}
