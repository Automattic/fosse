<?php
/**
 * Bluesky domain-handle service.
 *
 * Lets a connected user replace their Bluesky handle with the site's
 * hostname via a `com.atproto.identity.updateHandle` call. Bluesky's PDS
 * verifies the change against FOSSE's `/.well-known/atproto-did` route.
 *
 * Changing a Bluesky handle is destructive — the previous handle stops
 * resolving — so the call ALWAYS requires an explicit user confirmation
 * action. There is no "automatic" mode: even hosted environments must
 * route the user through a confirm button so they understand the change.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Coordinates the "use my domain as my Bluesky handle" feature.
 *
 * The feature is gated by a single kill-switch filter
 * (`fosse_domain_handle_enabled`, default true). When disabled, neither
 * the wizard nor the Settings page surfaces the confirm button, and a
 * disconnect makes no attempt to revert.
 *
 * Subdirectory installs are excluded entirely: handle verification reads
 * `https://<host>/.well-known/atproto-did`, which a subdirectory install
 * cannot serve from the root path.
 */
class Bluesky_Domain_Handle {

	/**
	 * Filter name for the feature kill-switch.
	 *
	 * @var string
	 */
	public const FILTER_ENABLED = 'fosse_domain_handle_enabled';

	/**
	 * Filter name for short-circuiting the actual `updateHandle` call.
	 *
	 * Mirrors Atmosphere's `atmosphere_pre_apply_writes`: return null to
	 * proceed with the real PDS request, true to fake success, or a
	 * `WP_Error` to fake failure. Used by the test suite (where DPoP
	 * keys are unavailable) and by host integrations that want to gate
	 * the call on their own policy.
	 *
	 * @var string
	 */
	public const FILTER_PRE_UPDATE = 'fosse_pre_bluesky_update_handle';

	/**
	 * Settings-error slug used for surfacing notices in the FOSSE/wizard UI.
	 *
	 * @var string
	 */
	public const NOTICE_SETTING = 'atmosphere';

	/**
	 * Option storing the previous handle so disconnect can revert it.
	 *
	 * Recorded right before the `updateHandle` call swaps the value to the
	 * site hostname. Cleared on successful revert (or overwritten by a
	 * subsequent confirmed change) so the option only ever reflects "what
	 * we changed away from, and have not yet restored".
	 *
	 * @var string
	 */
	public const OPTION_PREVIOUS_HANDLE = 'fosse_bluesky_previous_handle';

	/**
	 * Whether the entire feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		/**
		 * Filter whether the FOSSE Bluesky domain-handle feature is enabled.
		 *
		 * Filter to false to fully disable: the wizard option does not render,
		 * the Settings panel suppresses the confirm button, and disconnect
		 * does not attempt to revert.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( self::FILTER_ENABLED, true );
	}

	/**
	 * Whether the site is at the root of its domain (or a subdomain).
	 *
	 * Subdirectory installs (e.g. `https://example.com/blog/`) cannot serve
	 * the AT Protocol verification endpoint at the domain root, so the
	 * feature must skip them — Bluesky's PDS would reject the handle
	 * change because it cannot fetch `/.well-known/atproto-did` at the
	 * top of the host.
	 *
	 * @return bool
	 */
	public static function is_root_install(): bool {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );

		return null === $path || '' === $path || '/' === $path;
	}

	/**
	 * Compute the handle that the site would advertise.
	 *
	 * Reads the host portion of `home_url()`. Returns an empty string if
	 * the host can't be resolved (extremely degraded `home` option) so
	 * callers can refuse the call rather than send an empty payload.
	 *
	 * @return string
	 */
	public static function get_target_handle(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Whether the confirm-handle UI should render for the given Bluesky status.
	 *
	 * Same gate for both the wizard step and the Settings page — both
	 * surfaces should agree on when the offer is meaningful.
	 *
	 * @param array<string, mixed> $bluesky_status Bluesky provider status snapshot.
	 * @return bool
	 */
	public static function should_offer( array $bluesky_status ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( ! self::is_root_install() ) {
			return false;
		}

		$target = self::get_target_handle();
		if ( '' === $target ) {
			return false;
		}

		// User must be connected — there's no handle to update otherwise.
		if ( empty( $bluesky_status['connected'] ) ) {
			return false;
		}

		// Don't offer when the handle already matches: there's nothing to
		// confirm. The wizard / Settings page can show "you're set" copy
		// instead of the button.
		$current_handle = isset( $bluesky_status['handle'] ) ? (string) $bluesky_status['handle'] : '';
		if ( '' !== $current_handle && strtolower( $current_handle ) === $target ) {
			return false;
		}

		return true;
	}

	/**
	 * Replace the connected user's Bluesky handle with the site's hostname.
	 *
	 * Caller MUST have verified capability + nonce + the user is connected
	 * before invoking. This method does not check those preconditions: it
	 * snapshots the current handle (so disconnect can revert), invokes
	 * `com.atproto.identity.updateHandle` via Atmosphere's DPoP-authenticated
	 * client, and posts a settings notice describing the outcome.
	 *
	 * @return true|\WP_Error|null Null when the feature is disabled, the
	 *                              install is ineligible, the user is not
	 *                              connected, or the connection handle
	 *                              already matches the site hostname.
	 *                              True on a successful change. WP_Error
	 *                              on failure (network, rate limit, scope).
	 */
	public static function set_handle() {
		if ( ! self::is_enabled() || ! self::is_root_install() ) {
			return null;
		}

		$target = self::get_target_handle();
		if ( '' === $target ) {
			return null;
		}

		if ( ! function_exists( '\Atmosphere\get_connection' ) || ! function_exists( '\Atmosphere\is_connected' ) ) {
			self::add_settings_notice(
				__( 'Cannot set the Bluesky handle: Atmosphere is not loaded.', 'fosse' ),
				'error'
			);
			return new \WP_Error(
				'fosse_atmosphere_unavailable',
				__( 'Atmosphere is not loaded; cannot update the Bluesky handle.', 'fosse' )
			);
		}

		if ( ! \Atmosphere\is_connected() ) {
			self::add_settings_notice(
				__( 'Connect to Bluesky before setting your domain handle.', 'fosse' ),
				'error'
			);
			return new \WP_Error(
				'fosse_not_connected',
				__( 'Not connected to Bluesky.', 'fosse' )
			);
		}

		$connection     = \Atmosphere\get_connection();
		$current_handle = isset( $connection['handle'] ) ? strtolower( (string) $connection['handle'] ) : '';

		// Already matches — record nothing, attempt nothing.
		if ( $current_handle === $target ) {
			return null;
		}

		// Snapshot the previous handle BEFORE the call so a successful
		// updateHandle has something to revert to. Even if the call
		// fails, the snapshot is harmless: a subsequent successful call
		// overwrites it, and disconnect's revert is no-op-safe.
		if ( '' !== $current_handle ) {
			update_option( self::OPTION_PREVIOUS_HANDLE, $current_handle, false );
		}

		$result = self::call_update_handle( $target );

		if ( is_wp_error( $result ) ) {
			self::add_settings_notice(
				sprintf(
					/* translators: 1: target handle (the site domain); 2: error message from the PDS. */
					__( 'Could not set %1$s as your Bluesky handle: %2$s', 'fosse' ),
					$target,
					$result->get_error_message()
				),
				'error'
			);
			return $result;
		}

		self::add_settings_notice(
			sprintf(
				/* translators: %s: the handle the site set itself to (e.g. example.com). */
				__( 'Your Bluesky handle is now %s.', 'fosse' ),
				$target
			),
			'success'
		);

		return true;
	}

	/**
	 * Attempt to revert to the previously snapshotted handle.
	 *
	 * No-op when the feature is disabled or when there is nothing to revert
	 * (we never set a handle, or already reverted). The OAuth token is
	 * still valid at the moment this runs because the disconnect has not
	 * yet happened — Bluesky_Provider calls this BEFORE
	 * \Atmosphere\OAuth\Client::disconnect().
	 *
	 * @return true|\WP_Error|null Null when no revert was attempted.
	 */
	public static function maybe_revert_on_disconnect() {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$previous = (string) get_option( self::OPTION_PREVIOUS_HANDLE, '' );
		if ( '' === $previous ) {
			return null;
		}

		$result = self::call_update_handle( $previous );

		if ( is_wp_error( $result ) ) {
			self::add_settings_notice(
				sprintf(
					/* translators: 1: previous handle to restore; 2: error message from the PDS. */
					__( 'Could not restore your previous Bluesky handle (%1$s): %2$s', 'fosse' ),
					$previous,
					$result->get_error_message()
				),
				'warning'
			);
			return $result;
		}

		delete_option( self::OPTION_PREVIOUS_HANDLE );

		self::add_settings_notice(
			sprintf(
				/* translators: %s: the handle that was restored (e.g. alice.bsky.social). */
				__( 'Restored your previous Bluesky handle: %s.', 'fosse' ),
				$previous
			),
			'info'
		);

		return true;
	}

	/**
	 * Issue the `com.atproto.identity.updateHandle` call.
	 *
	 * Runs the `fosse_pre_bluesky_update_handle` short-circuit filter first
	 * so tests and integrations can observe / mock the call without going
	 * through Atmosphere's DPoP layer (which would otherwise require real
	 * encrypted keys to even build a request).
	 *
	 * @param string $handle Handle to set on the connected account.
	 * @return true|\WP_Error
	 */
	private static function call_update_handle( string $handle ) {
		/**
		 * Short-circuits the `com.atproto.identity.updateHandle` call.
		 *
		 * Return `true` to fake success, a `WP_Error` to fake failure, or
		 * `null` (the default) to fall through to the real PDS request.
		 *
		 * @param null|true|\WP_Error $short_circuit Short-circuit value.
		 * @param string              $handle        Handle that would be set.
		 */
		$short_circuit = apply_filters( self::FILTER_PRE_UPDATE, null, $handle );

		if ( true === $short_circuit ) {
			return true;
		}

		if ( is_wp_error( $short_circuit ) ) {
			return $short_circuit;
		}

		if ( null !== $short_circuit ) {
			return new \WP_Error(
				'fosse_invalid_pre_update_handle_return',
				__( 'fosse_pre_bluesky_update_handle must return null, true, or a WP_Error.', 'fosse' )
			);
		}

		if ( ! class_exists( '\Atmosphere\API' ) ) {
			return new \WP_Error(
				'fosse_atmosphere_unavailable',
				__( 'Atmosphere is not loaded; cannot update the Bluesky handle.', 'fosse' )
			);
		}

		$response = \Atmosphere\API::post(
			'/xrpc/com.atproto.identity.updateHandle',
			array( 'handle' => $handle )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Persist a settings notice under the Atmosphere group so it surfaces
	 * on the FOSSE Setup page and the wizard's Bluesky step.
	 *
	 * Stores via the `settings_errors` transient so the message survives
	 * the `wp_safe_redirect` that admin-post handlers issue. Does not
	 * redirect — Bluesky_Provider owns redirect flow.
	 *
	 * @param string $message Translated message to surface.
	 * @param string $type    Notice type (`success`, `error`, `warning`, `info`).
	 * @return void
	 */
	private static function add_settings_notice( string $message, string $type ): void {
		add_settings_error( self::NOTICE_SETTING, 'fosse_domain_handle', $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}
}
