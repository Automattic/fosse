<?php
/**
 * FOSSE plugin lifecycle (uninstall cleanup).
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Deletes FOSSE-owned options, transients, and user meta on plugin uninstall.
 *
 * Upstream ActivityPub (`activitypub_*`) and Atmosphere (`atmosphere_*`)
 * options are intentionally preserved — FOSSE writes some of those values
 * during onboarding, but they are canonical settings for the standalone
 * plugins and may still be in use after FOSSE is gone. See
 * `sdd/deactivation-lifecycle/spec.md`.
 *
 * `uninstall()` is the only public entrypoint and is safe to call from:
 * - `uninstall.php` (the normal WP plugin-delete path),
 * - PHPUnit (no simulation of the WP plugin-deletion flow required),
 * - out-of-band tooling on wp.com Simple, where `uninstall.php` never
 *   fires because the load gate is a blog sticker, not a WP plugin entry.
 */
class Lifecycle {

	/**
	 * Exact option names FOSSE owns end-to-end and may delete on uninstall.
	 *
	 * Keep in lockstep with the Data Ownership table in
	 * `sdd/deactivation-lifecycle/spec.md`. Any new FOSSE-owned option
	 * shipped by a sibling SDD must be appended here before that SDD lands.
	 *
	 * @var string[]
	 */
	private const FOSSE_OWNED_OPTIONS = array(
		'fosse_object_type',
		'fosse_long_form_strategy',
		'fosse_onboarding_completed',
		'fosse_onboarding_destination',
		'fosse_activation_redirect',
		'fosse_bundled_ap_bootstrapped',
		'fosse_bundled_atmosphere_bootstrapped',
		'fosse_canonical_options_migrated',
		'fosse_metrics_consent',
		'fosse_metrics_last_observed_at',
		'fosse_metrics_first_observed_at',
		'fosse_metrics_funnel',
	);

	/**
	 * Exact transient names FOSSE owns.
	 *
	 * @var string[]
	 */
	private const FOSSE_OWNED_TRANSIENTS = array(
		'fosse_activation_redirect',
		'fosse_deactivation_handoff_pending',
	);

	/**
	 * Prefix matching every per-user OAuth return-context transient written
	 * by `Admin\Bluesky_Provider::OAUTH_RETURN_TRANSIENT_PREFIX`. Used as a
	 * `LIKE` prefix against the options table.
	 *
	 * @var string
	 */
	private const FOSSE_TRANSIENT_PREFIXES = 'fosse_bluesky_oauth_return_';

	/**
	 * FOSSE-owned user meta keys.
	 *
	 * @var string[]
	 */
	private const FOSSE_OWNED_USER_META = array(
		'_fosse_wizard_started_emitted',
	);

	/**
	 * Run FOSSE uninstall cleanup.
	 *
	 * Idempotent and safe to call when no FOSSE state exists. Never touches
	 * `activitypub_*` or `atmosphere_*` keys, even when FOSSE is the
	 * original writer.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		foreach ( self::FOSSE_OWNED_OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::FOSSE_OWNED_TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}

		self::delete_prefixed_transients( self::FOSSE_TRANSIENT_PREFIXES );

		self::delete_user_meta_keys( self::FOSSE_OWNED_USER_META );
	}

	/**
	 * Delete every transient whose name begins with `$prefix`.
	 *
	 * WordPress' transient API has no wildcard delete. Cleanup runs in two
	 * passes:
	 *
	 * 1. Walk autoloaded options (and, under the WorDBless dbless engine, the
	 *    full in-memory option store) and route every matching entry through
	 *    `delete_transient()` so object-cache layers see the invalidation.
	 * 2. Issue a raw `LIKE` delete to drop any non-autoloaded transient rows
	 *    the first pass missed — the canonical production case, since
	 *    transients with a TTL are stored with `autoload=no`. Both the value
	 *    row (`_transient_<name>`) and the matching timeout row
	 *    (`_transient_timeout_<name>`) are removed so a future
	 *    `get_transient()` doesn't see a half-expired entry.
	 *
	 * @param string $prefix Transient-name prefix (no `_transient_` prefix).
	 * @return void
	 */
	private static function delete_prefixed_transients( string $prefix ): void {
		$value_prefix   = '_transient_' . $prefix;
		$timeout_prefix = '_transient_timeout_' . $prefix;

		foreach ( array_keys( wp_load_alloptions() ) as $option_name ) {
			$option_name = (string) $option_name;
			if ( str_starts_with( $option_name, $value_prefix ) ) {
				delete_transient( substr( $option_name, strlen( '_transient_' ) ) );
			} elseif ( str_starts_with( $option_name, $timeout_prefix ) ) {
				delete_transient( substr( $option_name, strlen( '_transient_timeout_' ) ) );
			}
		}

		global $wpdb;

		$escaped_prefix = $wpdb->esc_like( $prefix );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot uninstall cleanup; no caching layer applies.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $escaped_prefix . '%',
				'_transient_timeout_' . $escaped_prefix . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Delete the named user meta keys from every user.
	 *
	 * Uses `delete_metadata()` with `$delete_all = true` so a single query
	 * covers the whole table per key. Avoids materializing the user list.
	 *
	 * @param string[] $keys User meta keys to remove.
	 * @return void
	 */
	private static function delete_user_meta_keys( array $keys ): void {
		foreach ( $keys as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}
}
