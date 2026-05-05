<?php
/**
 * Per-user settings-notice transient plumbing.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Persists settings notices across `wp_safe_redirect()` in a per-user
 * transient instead of WordPress core's site-global `settings_errors`
 * one.
 *
 * The default WP flow stashes notices under `set_transient(
 * 'settings_errors', ... )` and `get_settings_errors()` consumes that
 * transient on the next admin request. Because the key is site-global,
 * a notice posted by Admin A (e.g. "Your Bluesky handle is now
 * alice.bsky.social") can be picked up by Admin B's next admin page
 * load within the 30-second TTL — leaking identity information across
 * users on a multi-admin install.
 *
 * This helper writes notices into a per-user key
 * (`fosse_settings_errors_{user_id}`) and consumes them on `admin_init`
 * before any `settings_errors()` render runs. Anonymous requests (no
 * authenticated user) deliberately fall back to `get_settings_errors()`
 * staying within the request-global only — no transient read/write —
 * because there's no stable per-user key to scope to.
 *
 * Migration note: this replaces direct
 * `set_transient( 'settings_errors', get_settings_errors(), 30 )` calls
 * across the FOSSE admin surface. Call {@see self::persist()} from any
 * handler that issues `wp_safe_redirect()` after `add_settings_error()`.
 */
class User_Notices {

	/**
	 * Per-user transient prefix.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'fosse_settings_errors_';

	/**
	 * Wire the consumer into the admin lifecycle.
	 *
	 * Hooked at `admin_init` priority 1 so the merge runs before any
	 * provider/handler that calls `settings_errors()` to render notices.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( static::class, 'consume' ), 1 );
	}

	/**
	 * Snapshot the current request's settings errors into the per-user
	 * transient, so the next admin request can render them.
	 *
	 * Anonymous requests (no logged-in user) skip the transient entirely
	 * — there's no stable key to scope to, and falling back to the
	 * site-global key would re-introduce the cross-user leak this helper
	 * exists to prevent. In practice this only matters for code paths
	 * that hit `add_settings_error()` without an authenticated user,
	 * which the FOSSE admin surface never does.
	 *
	 * @return void
	 */
	public static function persist(): void {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return;
		}

		$errors = get_settings_errors();
		if ( empty( $errors ) ) {
			return;
		}

		set_transient( self::key( $user_id ), $errors, 30 );
	}

	/**
	 * Merge any pending per-user notices into the request-global
	 * `$wp_settings_errors` and clear the transient.
	 *
	 * Hooked on `admin_init` so subsequent `settings_errors()` /
	 * `get_settings_errors()` calls see the merged set without each
	 * caller having to remember to consume the transient.
	 *
	 * @return void
	 */
	public static function consume(): void {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return;
		}

		$key    = self::key( $user_id );
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return;
		}

		delete_transient( $key );

		global $wp_settings_errors;
		if ( ! is_array( $wp_settings_errors ) ) {
			$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core global; initializing only when uninitialized.
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core global; merging persisted notices is the entire point of this helper.
		$wp_settings_errors = array_merge( $wp_settings_errors, $stored );
	}

	/**
	 * Clear any pending per-user notices for the current user.
	 *
	 * Useful when a handler decides mid-flight that it doesn't want
	 * to surface the notices it queued. No-op for anonymous users.
	 *
	 * @return void
	 */
	public static function forget(): void {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return;
		}

		delete_transient( self::key( $user_id ) );
	}

	/**
	 * Build the transient key for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function key( int $user_id ): string {
		return self::TRANSIENT_PREFIX . $user_id;
	}
}
