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
	 * Invalid returns (anything else) produce a
	 * `fosse_invalid_pre_update_handle_return` `WP_Error`.
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
	 * Error code attached to every settings notice this service posts.
	 *
	 * Doubles as the "let it through" marker the wizard reads when deciding
	 * which `atmosphere`-group success/info notices should bypass the
	 * top-of-step suppression filter (which exists to dedupe Atmosphere's
	 * own connect-success notice).
	 *
	 * @var string
	 */
	public const NOTICE_CODE = 'fosse_domain_handle';

	/**
	 * Option storing the previous handle so disconnect can revert it.
	 *
	 * Stored shape: `array{ did: string, handle: string }`. Binding the
	 * snapshot to a DID prevents two failure modes:
	 *
	 * 1. A reconnect-to-a-different-account followed by disconnect would
	 *    otherwise try to push the prior account's handle onto the new
	 *    account, which is at best a confusing API call and at worst
	 *    silently steals a handle the user no longer controls.
	 * 2. A disconnect that runs after the user manually changed their
	 *    handle to a third value (e.g. via the Bluesky app directly)
	 *    would otherwise revert that manual choice.
	 *
	 * Recorded on confirmed `updateHandle` success. Cleared on confirmed
	 * revert. Never written speculatively before the PDS call.
	 *
	 * @var string
	 */
	public const OPTION_PREVIOUS_HANDLE = 'fosse_bluesky_previous_handle';

	/**
	 * One-shot record of the snapshot consumed by the most recent successful
	 * {@see self::maybe_revert_on_disconnect()} call.
	 *
	 * Captured BEFORE the snapshot option is deleted so a caller (today
	 * `Bluesky_Provider::handle_disconnect`) can re-persist the snapshot if
	 * the subsequent OAuth disconnect fails. Without this hand-off, a
	 * disconnect failure leaves the PDS reverted but the local snapshot
	 * gone, so a retry of disconnect later has nothing to revert and the
	 * pre-revert (now-domain) handle becomes effectively permanent.
	 *
	 * Shape: `array{ did: string, handle: string }`. Reset to null on every
	 * `maybe_revert_on_disconnect()` invocation and on `restore_snapshot()`,
	 * so callers must read it once immediately after a true return — it is
	 * NOT durable cross-request state.
	 *
	 * @var array{did:string,handle:string}|null
	 */
	private static ?array $last_revert_snapshot = null;

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
	 * Whether the site's `home_url()` advertises a host Bluesky's PDS
	 * can actually verify via the well-known DID endpoint.
	 *
	 * Bluesky resolves a domain handle by fetching
	 * `https://<host>/.well-known/atproto-did`. That fetch:
	 *
	 *  - Targets the canonical HTTPS port — a `home_url` with an
	 *    explicit port (`https://example.com:8080/`) cannot be reached.
	 *  - Requires a publicly-resolvable DNS name — IP literals (`192.0.2.1`,
	 *    `[2001:db8::1]`), `localhost`, single-label hosts (`mybox`), and
	 *    `*.local` mDNS names will not resolve from Bluesky's perspective.
	 *
	 * Refusing the offer at this layer keeps the wizard / Settings panel
	 * from rendering a button that would only ever produce a confusing
	 * upstream error after the user clicks it.
	 *
	 * @return bool
	 */
	public static function is_resolvable_host(): bool {
		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		if ( '' === $host ) {
			return false;
		}

		// An explicit port — even 80 or 443 — implies the user has
		// configured a non-canonical setup. The PDS won't follow it.
		if ( $port > 0 ) {
			return false;
		}

		// IP literals (IPv4 + IPv6) cannot serve as AT Protocol handles.
		// `home_url()` strips the brackets around an IPv6 host, so the
		// raw `filter_var` check covers both families.
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		// Single-label hosts (no dot) — `localhost`, `mybox`, etc. — are
		// not publicly resolvable. AT Protocol handles must contain at
		// least one dot per the lexicon spec.
		if ( false === strpos( $host, '.' ) ) {
			return false;
		}

		// `*.local` mDNS / Bonjour and the literal `localhost.localdomain`
		// are local-only by definition. Bluesky's PDS will not reach them.
		$lower = strtolower( $host );
		if ( 'localhost' === $lower || 'localhost.localdomain' === $lower ) {
			return false;
		}
		if ( str_ends_with( $lower, '.local' ) || str_ends_with( $lower, '.localhost' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Compute the handle that the site would advertise.
	 *
	 * Reads the host portion of `home_url()`, lowercases it, and (when
	 * the `intl` extension is available) converts non-ASCII labels to
	 * their punycode form so the value matches the AT Protocol handle
	 * lexicon (LDH ASCII labels). Returns an empty string for any input
	 * that can't be normalized — a degraded `home` option, or an IDN
	 * host on a server without `intl` — so callers refuse the call
	 * rather than send a malformed payload to the PDS.
	 *
	 * @return string
	 */
	public static function get_target_handle(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		// Punycode-encode any non-ASCII labels. AT Protocol handles must
		// be ASCII-only; sending raw UTF-8 (`münchen.example`) would either
		// be rejected by the PDS or — worse — silently accepted as a
		// non-canonical value the resolver later can't match. Use
		// NON-transitional UTS-46 plus STD3 ASCII rules: that is the
		// processing WHATWG's URL Standard mandates (transitional processing,
		// the old `IDNA_DEFAULT`, maps a handful of characters — notably
		// `ß` and `ς` — differently and is deprecated). STD3 rules reject
		// labels containing characters outside LDH, matching the AT Protocol
		// handle lexicon.
		$has_non_ascii = (bool) preg_match( '/[\x80-\xff]/', $host );
		if ( $has_non_ascii ) {
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				// `intl` is unavailable and the host has non-ASCII bytes
				// we can't safely encode. Refuse rather than ship a
				// guaranteed-broken payload.
				return '';
			}
			$ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46 );
			if ( false === $ascii || '' === $ascii ) {
				return '';
			}
			$host = $ascii;
		}

		$host = strtolower( $host );

		// Defer to the bundled AT Protocol handle validator when available.
		// Its rule set is stricter than the per-label LDH grammar — it
		// caps total host length at 253 bytes, rejects digit-leading and
		// reserved TLDs (`.test`, `.example`, `.invalid`, `.onion`,
		// `.arpa`, `.alt`, etc.), and is the same gate Atmosphere uses
		// downstream. Sharing the gate avoids the case where this method
		// accepts a host that updateHandle then rejects after the admin
		// has already clicked through.
		if ( \class_exists( '\Atmosphere\OAuth\Resolver' )
			&& \method_exists( '\Atmosphere\OAuth\Resolver', 'is_valid_handle' ) ) {
			return \Atmosphere\OAuth\Resolver::is_valid_handle( $host ) ? $host : '';
		}

		// Fallback when bundled Atmosphere isn't loaded (e.g. unit tests
		// or a checkout with the standalone Atmosphere missing the
		// helper). Validates the LDH label grammar and the same coarse
		// total-length / TLD shape so the offer flow never crosses a
		// host the upstream validator would refuse.
		if ( '' === $host || \strlen( $host ) > 253 ) {
			return '';
		}
		$labels = explode( '.', $host );
		if ( \count( $labels ) < 2 ) {
			return '';
		}
		foreach ( $labels as $label ) {
			if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) {
				return '';
			}
		}
		$tld = end( $labels );
		if ( \ctype_digit( $tld[0] ) ) {
			return '';
		}
		// Reserved TLDs per the bundled validator. Kept in sync via the
		// `Resolver::is_valid_handle` path above when Atmosphere loads.
		if ( \in_array( $tld, array( 'alt', 'arpa', 'example', 'internal', 'invalid', 'local', 'localhost', 'onion', 'test' ), true ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * The previously-snapshotted handle for the currently-connected DID,
	 * or `''` when there's nothing to revert to (no snapshot, or one that
	 * belongs to a different account).
	 *
	 * Public-API wrapper around the same DID-bound lookup
	 * `maybe_revert_on_disconnect()` uses — kept public so the Settings
	 * page disconnect UI can render an informational note ("Disconnecting
	 * will restore your handle to ...") that mirrors the action the
	 * disconnect handler is about to take.
	 *
	 * @return string
	 */
	public static function get_pending_revert_handle(): string {
		return self::read_snapshot_for_current_did();
	}

	/**
	 * Whether the current state represents drift from a prior FOSSE-set handle.
	 *
	 * True when {@see self::should_offer()} is true AND a snapshot exists
	 * for the connected DID. Catches both "user changed their site
	 * domain after FOSSE set the handle" and "user changed their handle
	 * on bsky.app after FOSSE set it" — the two are indistinguishable
	 * from server state, and the caller renders neutral copy for both.
	 *
	 * Cheap drop-in for the wizard and Settings-page renders: when this
	 * returns true, surface a contextual explanation instead of the
	 * first-time-setup copy (the button does the same thing in both
	 * cases — only the framing changes).
	 *
	 * @param array<string, mixed> $bluesky_status Bluesky provider status snapshot.
	 * @return bool
	 */
	public static function is_drift( array $bluesky_status ): bool {
		if ( ! self::should_offer( $bluesky_status ) ) {
			return false;
		}
		return '' !== self::get_pending_revert_handle();
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

		if ( ! self::is_resolvable_host() ) {
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
	 * Echo the "replacing your handle is destructive" advisory paragraph.
	 *
	 * Shared between the FOSSE Settings page and the wizard's Bluesky step
	 * so the warning copy stays in lockstep across both surfaces. Renders
	 * a `<p class="description">` block matching the existing markup at
	 * each former call site.
	 *
	 * @return void
	 */
	public static function render_destructive_warning_notice(): void {
		?>
		<p class="description">
			<?php esc_html_e( 'Heads up: replacing your handle is destructive. Your previous handle will stop resolving immediately, and links to it will break. Bluesky verifies the new handle through this site automatically.', 'fosse' ); ?>
		</p>
		<?php
	}

	/**
	 * Replace the connected user's Bluesky handle with the site's hostname.
	 *
	 * Caller MUST have verified capability + nonce before invoking. This
	 * method does not check those preconditions: it validates the install
	 * is eligible, checks the connection, calls
	 * `com.atproto.identity.updateHandle` via Atmosphere's DPoP-authenticated
	 * client, snapshots the previous handle (only on confirmed success),
	 * syncs the locally-cached `atmosphere_connection['handle']` to the
	 * new value, and posts a settings notice describing the outcome.
	 *
	 * Every return path posts a settings notice — including the early
	 * `null` returns — so the admin-post handler that delegates here
	 * never has to invent a "nothing happened" message of its own.
	 *
	 * @return true|\WP_Error|null Null when no PDS call was attempted
	 *                              (feature disabled, subdirectory install,
	 *                              empty hostname, or the connection's
	 *                              handle already matched the site host).
	 *                              True on confirmed success. WP_Error on
	 *                              failure (network, rate limit, scope).
	 */
	public static function set_handle() {
		if ( ! self::is_enabled() ) {
			self::add_settings_notice(
				__( 'The "use your domain as your Bluesky handle" feature is disabled on this site.', 'fosse' ),
				'info'
			);
			return null;
		}

		if ( ! self::is_root_install() ) {
			self::add_settings_notice(
				__( 'This site is in a subdirectory, so it cannot serve the verification endpoint Bluesky needs to confirm a domain handle.', 'fosse' ),
				'error'
			);
			return null;
		}

		if ( ! self::is_resolvable_host() ) {
			self::add_settings_notice(
				__( 'This site\'s URL isn\'t a publicly-resolvable domain — Bluesky can\'t verify a handle pointed at localhost, an IP address, or a non-default port.', 'fosse' ),
				'error'
			);
			return null;
		}

		$target = self::get_target_handle();
		if ( '' === $target ) {
			self::add_settings_notice(
				__( 'Could not resolve this site\'s domain. Check the WordPress Address (URL) and try again.', 'fosse' ),
				'error'
			);
			return null;
		}

		if ( ! function_exists( '\Atmosphere\get_connection' ) || ! function_exists( '\Atmosphere\is_connected' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'FOSSE: set_handle: Atmosphere is not loaded.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
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
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'FOSSE: set_handle: not connected to Bluesky.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
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
		$current_did    = isset( $connection['did'] ) ? (string) $connection['did'] : '';

		// Already matches — record nothing, attempt nothing.
		if ( $current_handle === $target ) {
			self::add_settings_notice(
				sprintf(
					/* translators: %s: target handle = site host (e.g. example.com). */
					__( 'Your Bluesky handle is already %s.', 'fosse' ),
					$target
				),
				'info'
			);
			return null;
		}

		// Self-heal the well-known rewrite before the XRPC call. Bluesky's PDS
		// fetches `/.well-known/atproto-did` within milliseconds of
		// `updateHandle`, so the persisted rewrite rule must already be in
		// place by then. FOSSE loads Atmosphere programmatically, so the
		// activation-time flush may never have run on this site — mirror
		// upstream `\Atmosphere\Handle::set_handle()` and flush now.
		self::maybe_flush_wellknown_rewrites();

		$result = self::call_update_handle( $target );

		if ( is_wp_error( $result ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'FOSSE: set_handle: updateHandle failed: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
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

		// Persist the snapshot only AFTER confirmed success, bound to the
		// DID it applies to. A failed call must NOT leave a stale snapshot
		// behind that disconnect would later try to "revert" — at best a
		// wasted PDS call, at worst undoing a manual handle change the
		// user made through Bluesky directly.
		//
		// Keep the OLDEST snapshot for the current DID: a second handle
		// change (e.g. the user moves domains again before disconnecting)
		// must not overwrite the original always-revertible handle with an
		// intermediate FOSSE-set domain value. Once we've recorded
		// `alice.bsky.social`, a later move from `old.example` to
		// `new.example` would otherwise strand the only handle that points
		// back at the user's real, pre-FOSSE identity. Only write when no
		// snapshot exists yet for this DID. `read_snapshot_for_current_did()`
		// returns '' both when there's no snapshot and when an existing one
		// belongs to a different account — in the latter case we want to
		// replace it, since it can never be reverted to under this DID anyway.
		if ( '' !== $current_handle && '' !== $current_did && '' === self::read_snapshot_for_current_did() ) {
			update_option(
				self::OPTION_PREVIOUS_HANDLE,
				array(
					'did'    => $current_did,
					'handle' => $current_handle,
				),
				false
			);
		}

		// Sync the locally-cached connection handle so subsequent renders
		// (and Atmosphere's mention-facet special-case) reflect the change
		// immediately, instead of waiting for the next OAuth refresh.
		self::sync_local_connection_handle( $target );

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
	 * Returns null when the feature is disabled, when there is no snapshot,
	 * or when the snapshot belongs to a different account (DID) than the
	 * currently-connected one — never silently rewrites identity for an
	 * account FOSSE didn't previously change.
	 *
	 * On WP_Error, deliberately does NOT post a notice — the caller
	 * (`Bluesky_Provider::handle_disconnect`) composes a single combined
	 * "disconnected, but revert failed" message so the user doesn't see
	 * a cheerful "Disconnected" success stacked on top of a yellow warning
	 * that's easy to read past.
	 *
	 * On confirmed success, clears the snapshot, syncs the locally-cached
	 * connection handle, and posts an info notice summarizing the revert.
	 *
	 * Bluesky_Provider calls this BEFORE
	 * \Atmosphere\OAuth\Client::disconnect() so the OAuth token is still
	 * valid when the call goes out.
	 *
	 * @return true|\WP_Error|null Null when no revert was attempted.
	 */
	public static function maybe_revert_on_disconnect() {
		// Reset the one-shot revert record at the top of every invocation so
		// a caller reading `get_last_reverted_snapshot()` after a no-op call
		// (feature disabled, no snapshot, mismatched DID, failure) doesn't
		// see stale data from a prior request that handled the option-read
		// path differently.
		self::$last_revert_snapshot = null;

		if ( ! self::is_enabled() ) {
			return null;
		}

		// Read the raw snapshot (DID + handle) before checking DID match so
		// we can stash it for the caller's recovery path on success without
		// re-reading the option after deletion.
		$stored = get_option( self::OPTION_PREVIOUS_HANDLE, '' );

		$previous = self::read_snapshot_for_current_did();
		if ( '' === $previous ) {
			return null;
		}

		// Self-heal the well-known rewrite before the revert XRPC call too —
		// the PDS re-verifies against `/.well-known/atproto-did` on the way
		// back to the original handle, and FOSSE's programmatic Atmosphere
		// load means the activation-time flush may never have run. Mirrors the
		// flush upstream performs before its own `updateHandle` call.
		self::maybe_flush_wellknown_rewrites();

		$result = self::call_update_handle( $previous );

		if ( is_wp_error( $result ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'FOSSE: maybe_revert_on_disconnect: revert failed: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			// Caller composes the unified post-disconnect message. The
			// snapshot is intentionally preserved so a future disconnect
			// retry (after the user sorts out token / network) can still
			// attempt the revert.
			return $result;
		}

		// Capture the snapshot for the caller's recovery path BEFORE we
		// delete the option. If the OAuth disconnect that follows fails,
		// the caller can re-persist via `restore_snapshot()` so the now-
		// domain handle on the PDS isn't stranded with no revert path.
		if ( is_array( $stored ) ) {
			$snapshot_did    = isset( $stored['did'] ) ? (string) $stored['did'] : '';
			$snapshot_handle = isset( $stored['handle'] ) ? (string) $stored['handle'] : '';
			if ( '' !== $snapshot_did && '' !== $snapshot_handle ) {
				self::$last_revert_snapshot = array(
					'did'    => $snapshot_did,
					'handle' => $snapshot_handle,
				);
			}
		}

		delete_option( self::OPTION_PREVIOUS_HANDLE );
		self::sync_local_connection_handle( $previous );

		// Clear the persisted Atmosphere identity (DID + handle + PDS) when
		// the revert succeeded. Without this, `/.well-known/atproto-did`
		// keeps serving the DID against a domain the account no longer
		// claims — the PDS-side handle was just reverted away from this
		// site, so the DID's `alsoKnownAs` no longer lists `at://<host>`,
		// and the well-known route becomes a stale public claim that fails
		// bidirectional verification. The disconnect-preserves-identity
		// contract only makes sense when the binding still holds; once
		// the revert removes it, the verification anchor should go with
		// it. A reconnect with the original (now-restored) handle
		// rebuilds identity from the token exchange, so nothing is lost.
		delete_option( 'atmosphere_identity' );

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
	 * Re-persist a DID-bound snapshot of the previous handle.
	 *
	 * Used by `Bluesky_Provider::handle_disconnect()` to recover from a
	 * disconnect failure that follows a successful PDS revert: the revert
	 * has already cleared `OPTION_PREVIOUS_HANDLE`, so the next disconnect
	 * attempt has nothing to revert to. This method writes the snapshot
	 * back so the recovery path stays open.
	 *
	 * Resets the one-shot {@see self::get_last_reverted_snapshot()} hand-off
	 * so a follow-on read can't accidentally trigger a second restore.
	 *
	 * @param string $did    The connection DID the snapshot is bound to.
	 * @param string $handle The previous handle the snapshot should restore on revert.
	 * @return bool True on persisted, false on invalid input or option write failure.
	 */
	public static function restore_snapshot( string $did, string $handle ): bool {
		if ( '' === $did || '' === $handle ) {
			return false;
		}

		$persisted = (bool) update_option(
			self::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => $did,
				'handle' => $handle,
			),
			false
		);

		// One-shot semantics: a successful re-persist consumes the captured
		// snapshot so a follow-on read can't drive another restore.
		if ( $persisted ) {
			self::$last_revert_snapshot = null;
		}

		return $persisted;
	}

	/**
	 * Read the snapshot consumed by the most recent successful revert.
	 *
	 * One-shot accessor: returns the `{ did, handle }` array captured by the
	 * preceding `maybe_revert_on_disconnect()` call (immediately before that
	 * method deleted the option), or null when no revert ran in the current
	 * request, when the result was already consumed by `restore_snapshot()`,
	 * or when a subsequent `maybe_revert_on_disconnect()` invocation reset
	 * the slot.
	 *
	 * Intended for the disconnect-fail-after-revert recovery path in
	 * `Bluesky_Provider::handle_disconnect()` — the caller reads this once
	 * after `maybe_revert_on_disconnect()` returns true, then immediately
	 * calls `restore_snapshot()` to put the option back.
	 *
	 * @return array{did:string,handle:string}|null
	 */
	public static function get_last_reverted_snapshot(): ?array {
		return self::$last_revert_snapshot;
	}

	/**
	 * Read the snapshot for the currently-connected DID, if any.
	 *
	 * Returns the previous handle to revert to, or `''` when no snapshot
	 * exists for this DID — including when a snapshot exists but belongs
	 * to a different account (e.g. the user disconnected and reconnected
	 * to a different Bluesky account before disconnecting again).
	 *
	 * @return string
	 */
	private static function read_snapshot_for_current_did(): string {
		$stored = get_option( self::OPTION_PREVIOUS_HANDLE, '' );
		if ( ! is_array( $stored ) ) {
			return '';
		}

		$snapshot_did    = isset( $stored['did'] ) ? (string) $stored['did'] : '';
		$snapshot_handle = isset( $stored['handle'] ) ? (string) $stored['handle'] : '';

		if ( '' === $snapshot_did || '' === $snapshot_handle ) {
			return '';
		}

		if ( ! function_exists( '\Atmosphere\get_connection' ) ) {
			return '';
		}

		$connection  = \Atmosphere\get_connection();
		$current_did = isset( $connection['did'] ) ? (string) $connection['did'] : '';

		if ( '' === $current_did || ! hash_equals( $snapshot_did, $current_did ) ) {
			return '';
		}

		return $snapshot_handle;
	}

	/**
	 * Sync the locally-cached `atmosphere_connection['handle']` after a
	 * confirmed PDS handle change.
	 *
	 * Atmosphere's connection cache is the source of truth for both the
	 * Settings/wizard render and Atmosphere's own mention-facet builder
	 * (which special-cases the connected handle for `did:plc:` resolution).
	 * Leaving it stale after a successful `updateHandle` call would keep
	 * the destructive UI offer rendered AND let Atmosphere emit
	 * self-mentions resolving to a `did:web:<old-handle>` fallback that no
	 * longer points at the user's repo. Updates atomically via
	 * `update_option` so a single render won't see a partial write.
	 *
	 * Also mirrors the handle into `atmosphere_identity` when an identity
	 * record already exists. That option is the canonical store
	 * `\Atmosphere\get_identity()` / `has_identity()` read, and the public
	 * verification headers + publishing UI consult it directly; leaving it
	 * stale would let the new handle drift on the public surface even though
	 * the PDS accepted it. Mirrors upstream
	 * `\Atmosphere\Handle::sync_connection_handle()`. Deliberately does NOT
	 * create an identity when none exists — this is a sync, not a write of
	 * new identity state.
	 *
	 * @param string $handle Handle now in effect on the PDS.
	 * @return void
	 */
	private static function sync_local_connection_handle( string $handle ): void {
		$connection = get_option( 'atmosphere_connection', array() );
		if ( ! is_array( $connection ) ) {
			return;
		}

		$connection['handle'] = $handle;
		$updated              = update_option( 'atmosphere_connection', $connection );
		if ( false === $updated && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FOSSE: failed to sync local handle cache after updateHandle' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Mirror into the canonical identity store too, but only when an
		// identity already exists — never fabricate one here.
		$identity = get_option( 'atmosphere_identity', array() );
		if ( is_array( $identity ) && ! empty( $identity['did'] ) ) {
			$identity['handle'] = $handle;
			update_option( 'atmosphere_identity', $identity, true );

			// Read-after-write convergence check: `update_option()` can
			// silently fail to persist when a `pre_update_option_*`
			// filter returns the old value (e.g. site policy that pins
			// the identity) or when an options cache layer returns stale
			// data. Without this, the PDS-side change succeeds, the
			// success notice fires, and the canonical local identity
			// `\Atmosphere\get_identity()` reads keeps returning the old
			// handle — public verification headers and the publishing UI
			// then drift on the local surface even though the remote
			// account changed. Surface a warning notice so the operator
			// knows the local mirror did not converge, and log for
			// debug.
			$identity_after = get_option( 'atmosphere_identity', array() );
			$persisted      = is_array( $identity_after ) && isset( $identity_after['handle'] )
				? (string) $identity_after['handle']
				: '';
			if ( $persisted !== $handle ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'FOSSE: atmosphere_identity did not converge after sync; PDS handle change succeeded but local mirror is stale.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				add_settings_error(
					'atmosphere',
					'fosse_identity_sync_drift',
					sprintf(
						/* translators: %s: the handle the PDS now serves. */
						esc_html__( 'Your Bluesky account was updated to %s, but FOSSE could not refresh its local identity record. The new handle may not appear correctly until you reconnect.', 'fosse' ),
						esc_html( $handle )
					),
					'warning'
				);
			}
		}
	}

	/**
	 * Self-heal the AT Protocol well-known rewrite before an `updateHandle` call.
	 *
	 * Delegates to `\Atmosphere\Atmosphere::maybe_flush_wellknown_rewrites()`
	 * (a soft rewrite-rules flush) so the persisted rule serving
	 * `/.well-known/atproto-did` is guaranteed present before the PDS fetches
	 * it. FOSSE loads Atmosphere programmatically rather than as an active
	 * plugin, so Atmosphere's activation-time flush may never have run on this
	 * site; without this the first `updateHandle` after a fresh install can
	 * fail verification because the rewrite rule isn't yet persisted.
	 *
	 * Guards on the method's existence so a partial / future Atmosphere bundle
	 * degrades to the prior behaviour (no flush) rather than fatally erroring.
	 *
	 * @return void
	 */
	private static function maybe_flush_wellknown_rewrites(): void {
		if ( ! is_callable( array( '\Atmosphere\Atmosphere', 'maybe_flush_wellknown_rewrites' ) ) ) {
			return;
		}

		\Atmosphere\Atmosphere::maybe_flush_wellknown_rewrites();
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

		/*
		 * Called synchronously from the admin-post handler, so the submitting
		 * administrator waits on this HTTP round-trip. The PDS asks this
		 * host's `/.well-known/atproto-did` for the DID associated with the
		 * domain; that lookup usually finishes in seconds but can stretch to
		 * 15-45s for slow DNS / slow-origin combinations. The 60s timeout
		 * (versus `\Atmosphere\API::post()`'s inherited 30s default) gives a
		 * slow-but-eventual success room to land instead of failing mid-
		 * handshake. The wait is paid by an administrator who explicitly
		 * clicked the confirm button. Mirrors upstream
		 * `\Atmosphere\Handle::call_update_handle()`, which uses the same 60s
		 * value — `API::post()` hardcodes the default timeout, so we drop to
		 * `API::request()` to override it.
		 */
		$response = \Atmosphere\API::request(
			'POST',
			'/xrpc/com.atproto.identity.updateHandle',
			array(
				'body'    => array( 'handle' => $handle ),
				'timeout' => 60,
			)
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
	 * Stores via {@see User_Notices::persist()} (a per-user transient)
	 * so the message survives the `wp_safe_redirect` that admin-post
	 * handlers issue without leaking across users — the WP-default
	 * site-global `settings_errors` transient would let Admin B see
	 * Admin A's "Your Bluesky handle is now ..." message inside the
	 * 30-second TTL. Does not redirect — Bluesky_Provider owns redirect
	 * flow.
	 *
	 * Tags every notice with `NOTICE_CODE` so the wizard's Bluesky step
	 * can let our notices through its top-of-step success/info suppression
	 * filter (which exists to dedupe Atmosphere's own connect-success).
	 *
	 * @param string $message Translated message to surface.
	 * @param string $type    Notice type (`success`, `error`, `warning`, `info`).
	 * @return void
	 */
	private static function add_settings_notice( string $message, string $type ): void {
		add_settings_error( self::NOTICE_SETTING, self::NOTICE_CODE, esc_html( $message ), $type );
		User_Notices::persist();
	}
}
