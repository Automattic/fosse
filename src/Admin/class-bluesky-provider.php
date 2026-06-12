<?php
/**
 * Bluesky connection provider.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Integrates the bundled Atmosphere plugin into FOSSE's admin UI.
 *
 * Uses Atmosphere's public APIs for OAuth, connection storage, and
 * publication setup. FOSSE owns only the wrapper admin flow.
 *
 * When this provider is active, it takes ownership of the Bluesky
 * OAuth flow by unconditionally filtering atmosphere_oauth_redirect_uri.
 * This means OAuth flows initiated from Atmosphere's own settings page
 * will also redirect to FOSSE. This is intentional: FOSSE hides the
 * bundled plugin menus and presents itself as the single admin surface.
 */
class Bluesky_Provider implements Connection_Provider {

	/**
	 * Hidden form field used to identify wizard-origin connect flows.
	 *
	 * Exposed so callers (e.g. the onboarding wizard template) can render a
	 * hidden input that round-trips through admin-post and is read back by
	 * `get_connect_return_context()` without duplicating the literal string.
	 */
	public const RETURN_CONTEXT_FIELD = 'fosse_bluesky_return';

	/**
	 * Return context value for the first-run wizard.
	 *
	 * Public for the same reason as {@see self::RETURN_CONTEXT_FIELD}.
	 */
	public const RETURN_CONTEXT_WIZARD = 'wizard';

	/**
	 * Per-user transient prefix for pending OAuth return context.
	 */
	private const OAUTH_RETURN_TRANSIENT_PREFIX = 'fosse_bluesky_oauth_return_';

	/**
	 * Transient prefix for cached Bluesky profile data keyed by sanitized DID.
	 */
	private const PROFILE_TRANSIENT_PREFIX = 'fosse_bluesky_profile_';

	/**
	 * TTL applied when the profile fetch succeeds.
	 */
	private const PROFILE_SUCCESS_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Short negative-cache TTL applied when the profile fetch fails.
	 *
	 * A sentinel value of -1 is written into the transient so subsequent
	 * admin page renders during a sustained PDS outage skip the live HTTP
	 * call until the negative cache expires.
	 */
	private const PROFILE_FAILURE_TTL = 2 * MINUTE_IN_SECONDS;

	/**
	 * HTTP timeout (seconds) for the optional follower-count enrichment.
	 *
	 * Overrides Atmosphere's 30s default. The Followers row is decorative
	 * and is omitted entirely on failure, so admin rendering should not
	 * wait longer than this for it even on the first uncached request.
	 */
	private const PROFILE_REQUEST_TIMEOUT = 5;

	/**
	 * Hook into fosse_register_providers to self-register.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'fosse_register_providers', array( static::class, 'register_provider' ) );
	}

	/**
	 * Register this provider if Atmosphere is available.
	 *
	 * @return void
	 */
	public static function register_provider(): void {
		$provider = new self();
		if ( $provider->is_available() ) {
			Connection_Provider_Registry::register( $provider );
		}
	}

	/**
	 * Get the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'bluesky';
	}

	/**
	 * Get the provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Bluesky';
	}

	/**
	 * Check if Atmosphere is loaded.
	 *
	 * Requires the main class plus the procedural helpers `get_status()`
	 * relies on. Half-loaded Atmosphere (class present but functions
	 * missing) reports unavailable so the wizard / Setup page render the
	 * unavailable notice instead of a connect form that can't be backed.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( '\Atmosphere\Atmosphere' )
			&& function_exists( '\Atmosphere\get_connection' )
			&& function_exists( '\Atmosphere\is_connected' );
	}

	/**
	 * Per-request memo for {@see self::get_status()}.
	 *
	 * The Status page renders each provider's connection status twice in
	 * the same request — once when filtering available providers down to
	 * connected ones, and again inside `render_status_card()`. The
	 * underlying call decrypts the access token (`Atmosphere\OAuth\Client::access_token`),
	 * which is cheap individually but worth caching when render paths
	 * multiply that work across providers and screens.
	 *
	 * No invalidation hook today: every connect/disconnect/auto-publish
	 * mutator on this class ends in `wp_safe_redirect(); exit;`, so the
	 * cache never has to survive an in-request mutation. If a future
	 * caller needs to mutate the connection and re-render in the same
	 * request, set this property back to null at the mutation site.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $status_cache = null;

	/**
	 * Get current Bluesky connection status from Atmosphere.
	 *
	 * Memoized for the request — see {@see self::$status_cache}.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		if ( null !== $this->status_cache ) {
			return $this->status_cache;
		}

		// Run the token-health probe first because Atmosphere's
		// `OAuth\Client::access_token()` is not read-only — on a permanent
		// OAuth failure (`invalid_grant`, `invalid_client`,
		// `unauthorized_client`) it deletes `atmosphere_connection` to
		// prevent silent re-use of dead credentials. Reading the
		// connection BEFORE that probe and caching the pre-deletion view
		// would freeze the admin's status as connected for the rest of
		// the request even after the underlying state was invalidated.
		$token_error = null;
		if ( \Atmosphere\is_connected() && method_exists( '\Atmosphere\OAuth\Client', 'access_token' ) ) {
			$token = \Atmosphere\OAuth\Client::access_token();
			if ( is_wp_error( $token ) ) {
				$token_error = $token->get_error_message();
			}
		}

		// Re-read after the probe so a deleted connection is reflected.
		$connection = \Atmosphere\get_connection();
		$connected  = \Atmosphere\is_connected();

		$this->status_cache = array(
			'connected'    => $connected,
			'handle'       => is_string( $connection['handle'] ?? null ) ? $connection['handle'] : '',
			'did'          => is_string( $connection['did'] ?? null ) ? $connection['did'] : '',
			'pds_endpoint' => is_string( $connection['pds_endpoint'] ?? null ) ? $connection['pds_endpoint'] : '',
			'token_error'  => $token_error,
		);

		return $this->status_cache;
	}

	/**
	 * Whether Bluesky currently has a working connection.
	 *
	 * Mirrors the `connected` key of {@see self::get_status()}. Reads
	 * through the memoized status so the token-health probe in
	 * {@see self::get_status()} still runs and clears stale connections
	 * before the answer is returned.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return (bool) $this->get_status()['connected'];
	}

	/**
	 * Whether Bluesky auto-publishing is enabled for this site.
	 *
	 * Centralizes the "absent option reads as enabled" rule that
	 * `Atmosphere` itself follows: with the FOSSE Settings toggle
	 * removed, brand-new sites never materialize the option, so the
	 * default has to be on — anything else would silently break
	 * publishing for the silent majority.
	 *
	 * Static so callers (wizard CTA copy, future per-post UI) can read
	 * the state without resolving a provider instance through the
	 * registry — the option is global to the site, not provider-instance
	 * state. Do NOT use this to gate the recovery notice: that path needs
	 * to distinguish "explicit `'0'`" from "absent" and reads the option
	 * raw via `get_option( ..., null )`. Conflating absent with the
	 * default would surface the recovery banner on every fresh install.
	 *
	 * @return bool True when the option is absent or `'1'`; false when explicitly `'0'`.
	 */
	public static function is_auto_publish_enabled(): bool {
		return '1' === get_option( 'atmosphere_auto_publish', '1' );
	}

	/**
	 * Build a public bsky.app profile URL.
	 *
	 * Prefers the DID when available so the link survives a handle
	 * reassignment on Bluesky's side (handles can be moved between
	 * accounts; DIDs are stable). Falls back to the handle when no DID
	 * is available — typically only the brief pre-OAuth window.
	 *
	 * @param string $did    Stable DID identifier (empty when unknown).
	 * @param string $handle Bluesky handle.
	 * @return string Escaped URL.
	 */
	private static function get_profile_url( string $did, string $handle ): string {
		$identifier = '' !== $did ? $did : ltrim( $handle, '@' );

		return esc_url( 'https://bsky.app/profile/' . rawurlencode( $identifier ) );
	}

	/**
	 * Format a linked Bluesky handle token.
	 *
	 * Callers own the class set so this method stays free of per-surface
	 * branching. The href is built from the DID when present (see
	 * {@see self::get_profile_url()}) so handle reassignments don't point
	 * the link at a different account than the one shown.
	 *
	 * @param array<string, mixed> $status  Bluesky status snapshot.
	 * @param string[]             $classes CSS classes to apply to the inner <code> token.
	 * @return string Escaped HTML safe to echo.
	 */
	private static function format_handle_link( array $status, array $classes ): string {
		$did    = isset( $status['did'] ) ? (string) $status['did'] : '';
		$handle = isset( $status['handle'] ) ? (string) $status['handle'] : '';

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer"><code class="%2$s">@%3$s</code></a>',
			self::get_profile_url( $did, $handle ),
			esc_attr( implode( ' ', $classes ) ),
			Status_Formatter::handle( ltrim( $handle, '@' ) )
		);
	}

	/**
	 * Fetch the connected Bluesky follower count from the profile API.
	 *
	 * The Followers row is an optional UI enrichment, so the fetch uses
	 * {@see Atmosphere\API::request()} directly with a short
	 * {@see self::PROFILE_REQUEST_TIMEOUT} override instead of the
	 * `::get()` helper that inherits Atmosphere's 30s default — a slow
	 * PDS must not stall the entire Settings/Status render for a row
	 * that the UI omits on failure anyway.
	 *
	 * Successful counts are cached for {@see self::PROFILE_SUCCESS_TTL}.
	 * Remote-call failures are negative-cached for
	 * {@see self::PROFILE_FAILURE_TTL} using a `-1` sentinel so a
	 * sustained outage cannot turn every uncached admin render into a
	 * fresh HTTP wait. The UI omits the row in both the null-return and
	 * negative-cache-hit cases.
	 *
	 * @param array<string, mixed> $status Bluesky status snapshot.
	 * @return int|null Followers count, or null when unavailable.
	 */
	private static function get_followers_count( array $status ): ?int {
		if ( empty( $status['connected'] ) || empty( $status['did'] ) || ! empty( $status['token_error'] ) ) {
			return null;
		}

		$cache_key = self::PROFILE_TRANSIENT_PREFIX . sanitize_key( (string) $status['did'] );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached_int = (int) $cached;

			return $cached_int < 0 ? null : $cached_int;
		}

		if ( ! class_exists( '\Atmosphere\API' ) || ! method_exists( '\Atmosphere\API', 'request' ) ) {
			return null;
		}

		$endpoint = '/xrpc/app.bsky.actor.getProfile?' . http_build_query( array( 'actor' => (string) $status['did'] ) );
		$result   = \Atmosphere\API::request( 'GET', $endpoint, array( 'timeout' => self::PROFILE_REQUEST_TIMEOUT ) );
		if ( is_wp_error( $result ) || ! is_array( $result ) || ! array_key_exists( 'followersCount', $result ) || ! is_numeric( $result['followersCount'] ) ) {
			set_transient( $cache_key, -1, self::PROFILE_FAILURE_TTL );
			return null;
		}

		$count = max( 0, (int) $result['followersCount'] );
		set_transient( $cache_key, $count, self::PROFILE_SUCCESS_TTL );

		return $count;
	}

	/**
	 * Render Bluesky-specific settings inside the unified Settings form.
	 *
	 * Currently a no-op. The auto-publish toggle that used to render here
	 * was removed because Atmosphere has no per-post manual publish
	 * surface — disabling auto-publish today leaves the connection
	 * functionally inert (only inbound reaction sync keeps working).
	 * The `atmosphere_auto_publish` option is preserved in the database
	 * with default `'1'` (on) so behavior is unchanged. When the
	 * pre-publish federation panel for Bluesky lands (DOTCOM-17007 /
	 * upstream wordpress-atmosphere#50), this method can re-introduce
	 * a meaningful Bluesky-side fieldset.
	 *
	 * @return void
	 */
	public function render_setup_section(): void {
	}

	/**
	 * Render the Bluesky connection panel outside the unified Settings form.
	 *
	 * The connect and disconnect actions post to their own admin-post
	 * endpoints with their own nonces, so they cannot share the unified
	 * Settings form. This is also where Atmosphere's settings errors
	 * surface (OAuth failures, sync warnings).
	 *
	 * @return void
	 */
	public function render_connection_actions(): void {
		$status          = $this->get_status();
		$followers_count = self::get_followers_count( $status );
		?>
		<div class="fosse-connection-section fosse-admin-card" id="fosse-provider-bluesky">
			<div class="fosse-card-header">
				<h3><?php esc_html_e( 'Bluesky', 'fosse' ); ?></h3>
				<span class="fosse-status-badge is-<?php echo esc_attr( $status['connected'] ? 'connected' : 'disconnected' ); ?>">
					<?php echo esc_html( $status['connected'] ? __( 'Connected', 'fosse' ) : __( 'Disconnected', 'fosse' ) ); ?>
				</span>
			</div>

			<?php settings_errors( 'atmosphere' ); ?>

			<?php if ( ! $status['connected'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fosse_connect_bluesky" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_connect_bluesky' ) ); ?>" />

					<div class="fosse-card-body">
						<p><?php esc_html_e( 'Connect a Bluesky account to share eligible WordPress posts there too. You can disconnect it here later.', 'fosse' ); ?></p>

						<div class="fosse-field">
							<label class="fosse-field__label" for="fosse_bluesky_handle"><?php esc_html_e( 'Bluesky handle', 'fosse' ); ?></label>
							<div class="fosse-field__control">
								<input
									type="text"
									class="regular-text"
									name="bluesky_handle"
									id="fosse_bluesky_handle"
									placeholder="alice.bsky.social"
								/>
								<p class="description"><?php esc_html_e( 'Enter your Bluesky handle, such as alice.bsky.social. If you use your own domain as your handle, enter that.', 'fosse' ); ?></p>
								<p class="description fosse-bluesky-signup">
									<?php
									echo wp_kses_post(
										sprintf(
											/* translators: 1: opening anchor tag, 2: closing anchor tag */
											__( 'Need a Bluesky account? %1$sCreate one at bsky.app%2$s, then come back here to connect.', 'fosse' ),
											'<a href="' . esc_url( 'https://bsky.app/' ) . '" target="_blank" rel="noopener noreferrer" class="fosse-bluesky-signup__link">',
											'</a>'
										)
									);
									?>
								</p>
							</div>
						</div>
					</div>

					<div class="fosse-card-footer fosse-action-bar">
						<?php submit_button( __( 'Connect Bluesky', 'fosse' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
				<?php $this->render_identity_recovery_panel(); ?>
				<?php $this->render_identity_forget_panel(); ?>
			<?php else : ?>
				<div class="fosse-card-body">
					<p>
						<strong><?php esc_html_e( 'Connected account', 'fosse' ); ?></strong>
					</p>
					<p class="description">
						<?php esc_html_e( 'FOSSE can share eligible WordPress posts with this Bluesky account.', 'fosse' ); ?>
					</p>
					<dl class="fosse-detail-list">
						<?php if ( ! empty( $status['handle'] ) ) : ?>
							<dt class="fosse-detail-list__term"><?php esc_html_e( 'Bluesky handle', 'fosse' ); ?></dt>
							<dd class="fosse-detail-list__description">
								<?php
								echo self::format_handle_link( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_handle_link() escapes input and returns safe token/link markup.
									$status,
									array( 'fosse-token', 'fosse-admin-token', 'fosse-token--handle', 'fosse-admin-token--handle' )
								);
								?>
							</dd>
						<?php endif; ?>
						<?php if ( null !== $followers_count ) : ?>
							<dt class="fosse-detail-list__term"><?php esc_html_e( 'Followers', 'fosse' ); ?></dt>
							<dd class="fosse-detail-list__description"><?php echo esc_html( number_format_i18n( $followers_count ) ); ?></dd>
						<?php endif; ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'Account ID', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description">
							<code class="fosse-token fosse-admin-token fosse-token--did fosse-admin-token--did">
								<?php
								echo Status_Formatter::did( $status['did'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status_Formatter::did() escapes input and returns safe HTML with <wbr>.
								?>
							</code>
						</dd>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'PDS endpoint', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description">
							<code class="fosse-token fosse-admin-token fosse-token--url fosse-admin-token--url">
								<?php
								echo Status_Formatter::url( $status['pds_endpoint'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status_Formatter::url() escapes input and returns safe HTML with <wbr>.
								?>
							</code>
						</dd>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'Token health', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description"><?php echo esc_html( $status['token_error'] ? $status['token_error'] : __( 'OK', 'fosse' ) ); ?></dd>
					</dl>

						<?php $this->render_domain_handle_panel( $status ); ?>
						<?php
						// Surface the planned handle-revert so users understand
						// disconnect isn't just "log me out" when FOSSE previously
						// changed their Bluesky handle. The disconnect handler
						// runs the revert before dropping the OAuth token; if the
						// snapshot belongs to a different DID (reconnect to a
						// different account) the getter returns '' and the note
						// is suppressed.
						$pending_revert = Bluesky_Domain_Handle::get_pending_revert_handle();
						if ( '' !== $pending_revert ) :
							?>
							<div class="notice notice-warning inline fosse-domain-handle-revert-note">
								<p>
									<strong><?php esc_html_e( 'Disconnect note:', 'fosse' ); ?></strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: previous Bluesky handle that disconnect will restore (e.g. alice.bsky.social). */
											__( 'Disconnecting will also restore %s as this account\'s Bluesky handle.', 'fosse' ),
											$pending_revert
										)
									);
									?>
								</p>
							</div>
							<?php
						endif;
						?>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="fosse_disconnect_bluesky" />
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_disconnect_bluesky' ) ); ?>" />
						<div class="fosse-card-footer fosse-action-bar">
							<?php submit_button( __( 'Disconnect Bluesky', 'fosse' ), 'secondary', 'submit', false ); ?>
						</div>
					</form>
				<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "use my domain as my Bluesky handle" panel.
	 *
	 * Surfaces the explicit confirm button on the Bluesky Settings panel.
	 * The wizard's connected-state Bluesky step renders an equivalent panel
	 * with wizard-shaped copy inline; both surfaces share the same
	 * eligibility gate via {@see Bluesky_Domain_Handle::should_offer()},
	 * but the wizard does not call this method directly. If the panel
	 * markup ever drifts between the two, the gate stays the source of
	 * truth for "should this offer surface at all".
	 *
	 * Always shows the current handle alongside the target so the user
	 * understands the trade — clicking the button replaces their handle
	 * and the old one stops resolving.
	 *
	 * @param array<string, mixed> $status Bluesky provider status snapshot.
	 * @return void
	 */
	private function render_domain_handle_panel( array $status ): void {
		if ( ! Bluesky_Domain_Handle::should_offer( $status ) ) {
			return;
		}

		$current  = isset( $status['handle'] ) ? (string) $status['handle'] : '';
		$target   = Bluesky_Domain_Handle::get_target_handle();
		$is_drift = Bluesky_Domain_Handle::is_drift( $status );
		?>
		<div class="fosse-domain-handle-panel fosse-callout">
			<h4>
				<?php
				if ( $is_drift ) {
					esc_html_e( 'Realign your Bluesky handle with this site', 'fosse' );
				} else {
					esc_html_e( 'Use your domain as your Bluesky handle', 'fosse' );
				}
				?>
			</h4>
			<p>
				<?php
				if ( $is_drift ) {
					// Either the site domain changed since FOSSE set the
					// handle, or the user changed their handle on bsky.app
					// directly. Server-side we can't tell which, so the copy
					// stays neutral.
					echo esc_html(
						sprintf(
							/* translators: 1: current Bluesky handle (e.g. example.com); 2: target handle = site host (e.g. newdomain.com). */
							__( 'FOSSE previously set your Bluesky handle, but it no longer matches this site. Your handle on Bluesky is %1$s; this site is %2$s. Set it again to align them.', 'fosse' ),
							$current,
							$target
						)
					);
				} elseif ( '' !== $current ) {
					echo esc_html(
						sprintf(
							/* translators: 1: current Bluesky handle (e.g. alice.bsky.social); 2: target handle = site host (e.g. example.com). */
							__( 'Your current Bluesky handle is %1$s. You can replace it with %2$s.', 'fosse' ),
							$current,
							$target
						)
					);
				} else {
					echo esc_html(
						sprintf(
							/* translators: %s: target handle = site host (e.g. example.com). */
							__( 'You can set your Bluesky handle to %s.', 'fosse' ),
							$target
						)
					);
				}
				?>
			</p>
			<?php Bluesky_Domain_Handle::render_destructive_warning_notice(); ?>
			<form class="fosse-action-bar" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fosse_set_bluesky_domain_handle" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_set_bluesky_domain_handle' ) ); ?>" />
				<?php
				submit_button(
					sprintf(
						/* translators: %s: target handle = site host (e.g. example.com). */
						__( 'Use %s as my Bluesky handle', 'fosse' ),
						$target
					),
					'secondary',
					'submit',
					false
				);
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the collapsed "Restore from DID" recovery panel.
	 *
	 * Surfaces below the primary Connect form on the disconnected state.
	 * Collapsed by default — most users never need it. The escape hatch
	 * is for sites that adopted the site domain as their Bluesky handle
	 * and lost `atmosphere_identity` (older Atmosphere disconnects wiped
	 * it; a backup restore that excluded the option does the same). With
	 * identity empty, `/.well-known/atproto-did` 404s, the handle
	 * resolver finds nothing, and reconnect with the domain handle is
	 * impossible — `handle_restore_identity()` rebuilds the verification
	 * anchor from a DID the user can read off bsky.app.
	 *
	 * Only renders when there's no persisted identity at all. A site
	 * with a previously-persisted identity that just needs reauth has
	 * the normal Connect path; this panel would only be a confusing
	 * second option for them.
	 *
	 * @return void
	 */
	private function render_identity_recovery_panel(): void {
		// Mirror the handler's resolver-API check so the panel doesn't
		// invite a submission the handler will immediately reject. The
		// missing-Atmosphere case is rare in shipped FOSSE but reachable
		// in custom builds and CI; either way, a hidden panel is a better
		// UX than a paste-then-fail loop.
		if ( ! function_exists( '\Atmosphere\has_identity' )
			|| ! class_exists( '\Atmosphere\OAuth\Resolver' )
			|| ! method_exists( '\Atmosphere\OAuth\Resolver', 'resolve_did' )
			|| ! method_exists( '\Atmosphere\OAuth\Resolver', 'pds_from_did_doc' )
		) {
			return;
		}

		if ( \Atmosphere\has_identity() ) {
			return;
		}

		$site_host = $this->get_eligible_canonical_site_host();
		if ( '' === $site_host ) {
			return;
		}
		?>
		<details class="fosse-identity-recovery">
			<summary class="fosse-identity-recovery__summary">
				<?php esc_html_e( 'Trouble reconnecting a domain handle?', 'fosse' ); ?>
			</summary>
			<div class="fosse-identity-recovery__body">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: WordPress site host (e.g. example.com). */
							__( 'Use this if you previously used %s as your Bluesky handle and reconnecting no longer works. Paste your Bluesky DID below. FOSSE will check that the DID still points back to this site before restoring the identity needed to reconnect.', 'fosse' ),
							$site_host
						)
					);
					?>
				</p>
				<form class="fosse-identity-recovery__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fosse_restore_bluesky_identity" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_restore_bluesky_identity' ) ); ?>" />

					<div class="fosse-field">
						<label class="fosse-field__label" for="fosse_bluesky_did"><?php esc_html_e( 'Bluesky DID', 'fosse' ); ?></label>
						<div class="fosse-field__control">
							<input
								type="text"
								class="regular-text"
								name="bluesky_did"
								id="fosse_bluesky_did"
								aria-describedby="fosse_bluesky_did_description"
								placeholder="did:plc:..."
								autocomplete="off"
								autocapitalize="none"
								spellcheck="false"
							/>
							<p class="description" id="fosse_bluesky_did_description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: 1: opening anchor tag to Bluesky account settings, 2: closing anchor tag. */
										__( 'Starts with did:plc: or did:web:. You can find it in %1$sBluesky account settings%2$s or your profile URL.', 'fosse' ),
										'<a href="' . esc_url( 'https://bsky.app/settings/account' ) . '" target="_blank" rel="noopener noreferrer">',
										'</a>'
									)
								);
								?>
							</p>
						</div>
					</div>

					<div class="fosse-identity-recovery__actions fosse-action-bar">
						<?php submit_button( __( 'Restore identity', 'fosse' ), 'secondary', 'submit', false ); ?>
					</div>
				</form>
			</div>
		</details>
		<?php
	}

	/**
	 * Render the "Forget Bluesky identity" disclosure panel.
	 *
	 * Counterpart to {@see self::render_identity_recovery_panel()}. Renders
	 * only on the disconnected state when an identity is still on file —
	 * the case where the admin disconnected but Disconnect deliberately
	 * preserved the verification anchor, and they now want to fully sever
	 * the link (selling the site, switching accounts entirely, undoing a
	 * wrong restore). Connected sites don't see this — the natural flow is
	 * Disconnect, then Forget if they want a clean slate.
	 *
	 * Shows the persisted DID + handle + PDS as plain text inside the
	 * disclosure body so the admin can verify what they're about to delete
	 * before clicking through. Collapsed by default because the typical
	 * disconnect → reconnect cycle shouldn't surface this as the primary
	 * action.
	 *
	 * @return void
	 */
	private function render_identity_forget_panel(): void {
		if ( ! function_exists( '\Atmosphere\get_identity' ) || ! function_exists( '\Atmosphere\has_identity' ) ) {
			return;
		}

		if ( ! \Atmosphere\has_identity() ) {
			return;
		}

		$identity = \Atmosphere\get_identity();
		$did      = isset( $identity['did'] ) ? (string) $identity['did'] : '';
		$handle   = isset( $identity['handle'] ) ? (string) $identity['handle'] : '';
		$pds      = isset( $identity['pds_endpoint'] ) ? (string) $identity['pds_endpoint'] : '';
		?>
		<details class="fosse-identity-forget">
			<summary>
				<?php esc_html_e( 'Forget this site\'s Bluesky identity entirely.', 'fosse' ); ?>
			</summary>
			<div class="fosse-card-body">
				<p>
					<?php
					esc_html_e(
						'Disconnect keeps the persisted DID so a domain-handle site can reconnect cleanly. Use this if you instead want to fully sever the link — selling the site, switching to a different Bluesky account, or undoing a wrong restore. Once cleared, the .well-known/atproto-did route on this domain stops serving the DID, and external resolvers stop trusting any cached binding.',
						'fosse'
					);
					?>
				</p>
				<dl class="fosse-detail-list">
					<?php if ( '' !== $did ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'DID', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description"><code><?php echo esc_html( $did ); ?></code></dd>
					<?php endif; ?>
					<?php if ( '' !== $handle ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'Handle', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description"><code><?php echo esc_html( $handle ); ?></code></dd>
					<?php endif; ?>
					<?php if ( '' !== $pds ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'PDS endpoint', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description"><code><?php echo esc_html( $pds ); ?></code></dd>
					<?php endif; ?>
				</dl>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fosse_forget_bluesky_identity" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_forget_bluesky_identity' ) ); ?>" />
					<div class="fosse-card-footer fosse-action-bar">
						<?php submit_button( __( 'Forget Bluesky identity', 'fosse' ), 'secondary', 'submit', false ); ?>
					</div>
				</form>
			</div>
		</details>
		<?php
	}

	/**
	 * Render the Bluesky status card on the FOSSE Status page.
	 *
	 * @return void
	 */
	public function render_status_card(): void {
		$status          = $this->get_status();
		$status_class    = $status['connected'] ? 'connected' : 'disconnected';
		$status_label    = $status['connected'] ? __( 'Connected', 'fosse' ) : __( 'Disconnected', 'fosse' );
		$followers_count = self::get_followers_count( $status );
		?>
		<div class="fosse-status-card fosse-admin-card">
			<div class="fosse-status-card__header fosse-card-header">
				<h2 class="fosse-status-card__title">
					<span
						class="fosse-status-indicator <?php echo esc_attr( $status_class ); ?>"
						aria-hidden="true"
					></span>
					<?php esc_html_e( 'Bluesky', 'fosse' ); ?>
				</h2>
				<span class="fosse-status-badge is-<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
			</div>

			<div class="fosse-card-body">
				<dl class="fosse-detail-list">
					<dt class="fosse-detail-list__term"><?php esc_html_e( 'Connection', 'fosse' ); ?></dt>
					<dd class="fosse-detail-list__description"><?php echo esc_html( $status_label ); ?></dd>
					<?php if ( $status['handle'] ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'Handle', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description">
								<?php
								echo self::format_handle_link( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_handle_link() escapes input and returns safe token/link markup.
									$status,
									array( 'fosse-token', 'fosse-status-card__token', 'fosse-token--handle', 'fosse-status-card__token--handle' )
								);
								?>
						</dd>
					<?php endif; ?>
					<?php if ( null !== $followers_count ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'Followers', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description"><?php echo esc_html( number_format_i18n( $followers_count ) ); ?></dd>
					<?php endif; ?>
					<?php if ( $status['did'] ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'DID', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description">
								<code class="fosse-token fosse-status-card__token fosse-token--did fosse-status-card__token--did">
									<?php
									echo Status_Formatter::did( $status['did'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status_Formatter::did() escapes input and returns safe HTML with <wbr>.
									?>
								</code>
						</dd>
					<?php endif; ?>
					<?php if ( $status['pds_endpoint'] ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'PDS endpoint', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description">
								<code class="fosse-token fosse-status-card__token fosse-token--url fosse-status-card__token--url">
									<?php
									echo Status_Formatter::url( $status['pds_endpoint'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status_Formatter::url() escapes input and returns safe HTML with <wbr>.
									?>
								</code>
						</dd>
					<?php endif; ?>
					<dt class="fosse-detail-list__term"><?php esc_html_e( 'Token Health', 'fosse' ); ?></dt>
					<dd class="fosse-detail-list__description">
							<?php if ( $status['token_error'] ) : ?>
								<strong><?php esc_html_e( 'Reconnect required.', 'fosse' ); ?></strong>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse#fosse-provider-bluesky' ) ); ?>">
									<?php esc_html_e( 'Open Bluesky settings', 'fosse' ); ?>
								</a>
								<details class="fosse-status-card__error">
									<summary><?php esc_html_e( 'Error details', 'fosse' ); ?></summary>
									<code><?php echo esc_html( $status['token_error'] ); ?></code>
								</details>
							<?php else : ?>
								<?php esc_html_e( 'OK', 'fosse' ); ?>
							<?php endif; ?>
					</dd>
				</dl>
			</div>

			<?php if ( ! $status['connected'] && ! $status['token_error'] ) : ?>
				<p class="fosse-status-card__actions fosse-card-footer fosse-action-bar">
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=fosse#fosse-provider-bluesky' ) ); ?>">
						<?php esc_html_e( 'Open Bluesky settings', 'fosse' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Register hooks for this provider.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_fosse_connect_bluesky', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_fosse_disconnect_bluesky', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_fosse_set_bluesky_domain_handle', array( $this, 'handle_set_domain_handle' ) );
		add_action( 'admin_post_fosse_restore_bluesky_identity', array( $this, 'handle_restore_identity' ) );
		add_action( 'admin_post_fosse_forget_bluesky_identity', array( $this, 'handle_forget_identity' ) );
		add_action( 'admin_post_fosse_enable_bluesky_auto_publish', array( $this, 'handle_enable_auto_publish' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_auto_publish_disabled_notice' ) );
		add_action( 'template_redirect', array( $this, 'maybe_suppress_atmosphere_well_known' ), 1 );
		add_action( 'template_redirect', array( $this, 'send_atproto_did_nocache_headers' ), 2 );

		// Override Atmosphere's OAuth redirect URI so the auth server callback
		// and the client-metadata REST endpoint both advertise FOSSE's page.
		// Registered unconditionally (not just during handle_connect) because
		// the auth server fetches client metadata in a separate public request
		// and validates redirect_uri against it.
		add_filter( 'atmosphere_oauth_redirect_uri', array( $this, 'filter_oauth_redirect_uri' ) );
	}

	/**
	 * Persist Bluesky-side settings from the unified save submission.
	 *
	 * Currently a no-op. The auto-publish toggle was the only Bluesky-side
	 * setting that surfaced on the FOSSE Settings page, and it was removed
	 * because Atmosphere has no per-post manual publish surface to back it
	 * up — see {@see self::render_setup_section()} for the full rationale.
	 * Connection state is managed via the separate connect/disconnect
	 * flows. Returning true keeps the unified save's all-or-nothing
	 * semantics: this provider never rejects a submission.
	 *
	 * @param array<string, mixed> $post_data POST payload to read (unused).
	 * @return bool Always true.
	 */
	public function save_settings( array $post_data ): bool { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Connection_Provider interface contract; no Bluesky-side fields to persist after the auto-publish toggle removal.
		return true;
	}

	/**
	 * Apply `nocache_headers()` to `/.well-known/atproto-did` responses before
	 * Atmosphere's handler sends the body.
	 *
	 * The deleted FOSSE handler sent `nocache_headers()` on both 200 and 404
	 * responses; Atmosphere's `serve_wellknown_atproto_did()` doesn't. Without
	 * the headers, fronting page/CDN caches can keep a pre-connect 404 after
	 * OAuth completes, or keep a stale 200 DID after disconnect — either
	 * defeats Bluesky's bidirectional handle resolution. This shim runs at
	 * `template_redirect` priority 2 (after the opt-out suppression at
	 * priority 1, before Atmosphere's serve at priority 10) so the headers
	 * are queued before any body is sent. The opt-out path already calls
	 * `nocache_headers()` directly, so this hook short-circuits when the
	 * filter is false.
	 *
	 * Track wordpress-atmosphere#83 — once upstream sends `nocache_headers()`
	 * itself, this shim can be deleted.
	 *
	 * @return void
	 */
	public function send_atproto_did_nocache_headers(): void {
		if ( 'atproto-did' !== get_query_var( 'atmosphere_wellknown' ) ) {
			return;
		}

		if ( ! apply_filters( 'fosse_serve_atproto_did_well_known', true ) ) {
			return;
		}

		nocache_headers();
	}

	/**
	 * Suppress bundled Atmosphere's /.well-known/atproto-did handler when FOSSE opts out.
	 *
	 * Atmosphere owns the route end-to-end now: its `serve_wellknown_atproto_did()`
	 * runs on `template_redirect` priority 10 and gates the response on
	 * `\Atmosphere\has_identity()`, which is the contract FOSSE was previously
	 * mirroring. The `fosse_serve_atproto_did_well_known` filter remains as a
	 * site-level opt-out: when it returns false, this hook (priority 1) clears
	 * Atmosphere's query var so its handler returns early and marks the request
	 * 404 so WordPress doesn't render the front page for the well-known URL.
	 *
	 * Third-party handlers attached at `template_redirect` priority > 1 can still
	 * take over by calling `status_header( 200 )`, `$wp_query->set_404( false )`,
	 * and `exit()`.
	 *
	 * @return void
	 */
	public function maybe_suppress_atmosphere_well_known(): void {
		if ( 'atproto-did' !== get_query_var( 'atmosphere_wellknown' ) ) {
			return;
		}

		/**
		 * Filter whether the bundled Atmosphere handler serves /.well-known/atproto-did.
		 *
		 * Return false to let another component (CDN, custom rewrite, etc.) own the path.
		 * When false, FOSSE clears Atmosphere's query var and forces a 404 so neither
		 * plugin responds to the request.
		 *
		 * @param bool $serve Default true.
		 */
		if ( apply_filters( 'fosse_serve_atproto_did_well_known', true ) ) {
			return;
		}

		// Clear Atmosphere's query var so its handler at priority 10 returns,
		// then mark the request 404 so the rewrite rule doesn't render the
		// front page for the well-known URL. Third-party handlers attached at
		// template_redirect priority > 1 can still take over by calling
		// status_header( 200 ), $wp_query->set_404( false ), and exit().
		set_query_var( 'atmosphere_wellknown', '' );

		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Handle the FOSSE Bluesky connect action.
	 *
	 * @return void
	 */
	public function handle_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_connect_bluesky' );

		$return_context = $this->get_connect_return_context();
		if ( self::RETURN_CONTEXT_WIZARD !== $return_context ) {
			$this->forget_oauth_return_context();
		}

		$source = self::context_to_source( $return_context );

		self::record_metric(
			'fosse_connection_attempt',
			array(
				'network' => 'bluesky',
				'source'  => $source,
			)
		);

		$handle = sanitize_text_field( wp_unslash( $_POST['bluesky_handle'] ?? '' ) );
		$handle = self::normalize_submitted_handle( $handle );

		if ( empty( $handle ) ) {
			self::record_connection_failed( 'bluesky', $source, 'invalid_handle' );
			$this->redirect_with_notice( __( 'Enter a Bluesky handle to continue.', 'fosse' ), 'error', $return_context );
			return;
		}

		// AT Protocol handles are domain names: at least one dot, only ASCII
		// alphanumerics and hyphens per label, no leading/trailing hyphens.
		// Pre-validate so users get an actionable hint instead of a raw
		// upstream error like "PDS lookup failed: dns_get_record returned false".
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $handle ) ) {
			self::record_connection_failed( 'bluesky', $source, 'invalid_handle' );
			$this->redirect_with_notice(
				__( 'That doesn\'t look like a valid handle. Try something like alice.bsky.social or example.com.', 'fosse' ),
				'error',
				$return_context
			);
			return;
		}

		$auth_url = $this->request_authorize_url( $handle );

		if ( is_wp_error( $auth_url ) ) {
			self::record_connection_failed( 'bluesky', $source, self::categorize_wp_error( $auth_url ) );
			$this->redirect_with_notice( $auth_url->get_error_message(), 'error', $return_context );
			return;
		}

		// Defense-in-depth: the authorize URL is built from the remote auth
		// server's advertised `authorization_endpoint`. Bundled Atmosphere
		// validates the scheme during resolution, but a standalone or forked
		// Atmosphere may not — never hand the browser a non-https
		// authorization redirect.
		if ( 'https' !== strtolower( (string) wp_parse_url( $auth_url, PHP_URL_SCHEME ) ) ) {
			self::record_connection_failed( 'bluesky', $source, 'auth_failed' );
			$this->redirect_with_notice(
				__( 'Bluesky connection failed: the account\'s server returned an insecure sign-in address. Please try again or contact your Bluesky host.', 'fosse' ),
				'error',
				$return_context
			);
			return;
		}

		$this->remember_oauth_return_context( $return_context );

		wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Fetch the authorization URL for a validated handle from the OAuth client.
	 *
	 * Seam for tests: `Client::authorize()` is a static call whose return
	 * value is remote-controlled (the auth server's advertised
	 * `authorization_endpoint`), so subclasses override this to exercise
	 * the redirect guard in `handle_connect()` without a live OAuth flow.
	 *
	 * @param string $handle Validated AT Protocol handle.
	 * @return string|\WP_Error Authorization URL or error.
	 */
	protected function request_authorize_url( string $handle ): string|\WP_Error {
		return \Atmosphere\OAuth\Client::authorize( $handle );
	}

	/**
	 * Handle the FOSSE Bluesky disconnect action.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_disconnect_bluesky' );

		// Best-effort handle revert BEFORE the disconnect drops the OAuth
		// token: if FOSSE previously set the handle to the site domain,
		// restore the snapshotted previous handle while we still have a
		// valid access token. The result is composed into the final
		// disconnect notice below — `maybe_revert_on_disconnect()`
		// deliberately does not post its own notice on failure so we
		// don't end up with a yellow warning the cheerful "Disconnected"
		// success can drown out.
		$revert_result = Bluesky_Domain_Handle::maybe_revert_on_disconnect();

		\Atmosphere\OAuth\Client::disconnect();

		// Disconnect orphans any in-flight wizard return marker; clear it so
		// a subsequent connect attempt starts from a clean slate.
		$this->forget_oauth_return_context();

		// Atmosphere's `disconnect()` returns void and just deletes the
		// connection option. Verify the option actually went away so a DB
		// or filter failure surfaces instead of falsely showing "Disconnected".
		if ( \Atmosphere\is_connected() ) {
			// Re-persist the snapshot when the revert succeeded but the
			// disconnect did not. The successful revert already cleared
			// OPTION_PREVIOUS_HANDLE, so a future disconnect retry would
			// otherwise find nothing to revert to and the now-domain handle
			// on the PDS would be stranded with no FOSSE-assisted recovery.
			if ( true === $revert_result ) {
				$snapshot = Bluesky_Domain_Handle::get_last_reverted_snapshot();
				if ( null !== $snapshot ) {
					$restored = Bluesky_Domain_Handle::restore_snapshot(
						$snapshot['did'],
						$snapshot['handle']
					);
					if ( ! $restored && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'FOSSE: handle_disconnect: failed to restore handle snapshot after disconnect failure.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
			}

			// Drop any pending revert-success notice that
			// maybe_revert_on_disconnect() queued before the disconnect
			// failed. Surfacing both "Restored handle X" and "Could not
			// disconnect" on the next page render confuses the user about
			// the actual end state — disconnect didn't happen, so the
			// revert-success message is misleading on its own.
			User_Notices::forget();
			$this->redirect_with_notice(
				__( 'Could not disconnect from Bluesky. Please try again.', 'fosse' ),
				'error'
			);
			return;
		}

		if ( is_wp_error( $revert_result ) ) {
			$this->redirect_with_notice(
				sprintf(
					/* translators: %s: error message from the failed handle-revert PDS call. */
					__( 'Disconnected from Bluesky, but could not restore your previous handle: %s. You may need to set it manually from the Bluesky app.', 'fosse' ),
					$revert_result->get_error_message()
				),
				'warning'
			);
			return;
		}

		$this->redirect_with_notice( __( 'Disconnected from Bluesky.', 'fosse' ), 'info' );
	}

	/**
	 * Normalize a submitted Bluesky handle before validation.
	 *
	 * Peels ASCII whitespace and invisible Unicode formatting bytes
	 * (`\p{Cf}`: BOM, ZWSP, bidi marks, etc.) from both edges of the
	 * handle, then strips a leading `@`. Formatting bytes in the
	 * interior of the handle are intentionally left in place — they
	 * change the semantic shape of what the user typed and the
	 * downstream ASCII validation should surface that as an
	 * `invalid_handle` error rather than silently coercing the input
	 * into a different valid handle.
	 *
	 * @param string $handle Raw sanitized handle from the form submission.
	 * @return string Normalized handle.
	 */
	private static function normalize_submitted_handle( string $handle ): string {
		// ASCII whitespace is listed explicitly rather than via `\s` so the
		// pattern doesn't quietly grow if PCRE2 is ever compiled with UCP
		// (PHP's bundled build isn't, but the explicit class removes the
		// dependency on that). Non-ASCII whitespace stays intact and falls
		// through to the AT Protocol ASCII validator downstream.
		$edge_pattern = '/^[ \t\n\r\f\v\p{Cf}]+|[ \t\n\r\f\v\p{Cf}]+$/u';

		$handle = (string) preg_replace( $edge_pattern, '', $handle );
		$handle = ltrim( $handle, '@' );
		$handle = (string) preg_replace( $edge_pattern, '', $handle );

		return strtolower( $handle );
	}

	/**
	 * Handle the explicit "use my domain as my Bluesky handle" submission.
	 *
	 * Posted from the wizard's connected-state confirm button or the FOSSE
	 * Settings page. Verifies capability + nonce, defers to
	 * {@see Bluesky_Domain_Handle::set_handle()} for the actual call, then
	 * redirects back to the originating screen with a settings notice
	 * already populated by Bluesky_Domain_Handle.
	 *
	 * Always requires explicit user confirmation; there is no auto-set
	 * path. Changing a Bluesky handle is destructive (the old handle stops
	 * resolving), so this remains a deliberate user action regardless of
	 * how the host environment is configured.
	 *
	 * @return void
	 */
	public function handle_set_domain_handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_set_bluesky_domain_handle' );

		$return_context = $this->get_connect_return_context();

		Bluesky_Domain_Handle::set_handle();

		wp_safe_redirect( $this->get_redirect_url( $return_context ) );
		exit;
	}

	/**
	 * Restore `atmosphere_identity` from a DID submitted by the admin.
	 *
	 * Recovery escape hatch for sites that previously adopted the site
	 * domain as their Bluesky handle and lost the persisted identity (e.g.
	 * disconnected on a pre-fix Atmosphere release that wiped
	 * `atmosphere_identity`, or restored from a backup that excluded the
	 * option). With identity gone, `/.well-known/atproto-did` 404s and the
	 * AT Protocol handle resolver has nothing to find — `handle_to_did()`
	 * fails on both DNS TXT and HTTPS well-known, so reconnect with the
	 * domain handle is impossible without first restoring the option.
	 *
	 * Flow:
	 *  1. Capability + nonce gate.
	 *  2. Validate DID syntax (`did:plc:<24 lowercase alnum>` or `did:web:<host>`).
	 *  3. Fetch the DID document via {@see \Atmosphere\OAuth\Resolver::resolve_did()}.
	 *  4. Bind to the site: the document's `alsoKnownAs` MUST include
	 *     `at://<site-host>`. This is the integrity gate that blocks both
	 *     accidental wrong-DID entry and deliberate impersonation — only a
	 *     DID that already lists this WordPress site as one of its handles
	 *     can attach itself here.
	 *  5. Extract the PDS endpoint via {@see \Atmosphere\OAuth\Resolver::pds_from_did_doc()}.
	 *  6. Write `atmosphere_identity` with the trio FOSSE/Atmosphere
	 *     expect (`did`, `handle`, `pds_endpoint`), `autoload=true` to
	 *     match {@see \Atmosphere\get_identity()}.
	 *
	 * The user still has to complete a fresh OAuth flow afterwards — this
	 * only restores the bidirectional verification anchor so the resolver
	 * chain works.
	 *
	 * @return void
	 */
	public function handle_restore_identity(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_restore_bluesky_identity' );

		// Mirror the renderer's gate at the handler level. The recovery panel
		// only renders when has_identity() is false, but the admin-post hook
		// is still wired up regardless — a stale form tab from a prior
		// disconnected state, or a direct POST with a valid nonce, could
		// otherwise overwrite identity on a site that has since reconnected.
		// That would produce split-brain state: get_status() reports the
		// connected DID from atmosphere_connection while the well-known
		// route serves the overwritten DID from atmosphere_identity.
		if ( function_exists( '\Atmosphere\has_identity' ) && \Atmosphere\has_identity() ) {
			$this->redirect_with_notice(
				__( 'A Bluesky identity is already on file for this site. Use Disconnect or "Forget Bluesky identity" first if you want to replace it.', 'fosse' ),
				'error'
			);
			return;
		}

		$did = trim( sanitize_text_field( wp_unslash( $_POST['bluesky_did'] ?? '' ) ) );

		if ( '' === $did ) {
			$this->redirect_with_notice(
				__( 'Enter your Bluesky DID to restore the connection.', 'fosse' ),
				'error'
			);
			return;
		}

		// Strict DID syntax: did:plc with a 24-char base32-ish suffix (the
		// canonical PLC identifier shape) or did:web with a DNS-style host.
		// Anything outside these two methods isn't supported by the resolver
		// downstream, so rejecting here gives a clearer error than letting
		// resolve_did() fall through to "Unsupported DID method".
		if ( ! preg_match( '/^did:plc:[a-z0-9]{24}$/', $did )
			&& ! preg_match( '/^did:web:[a-z0-9.-]+$/', $did )
		) {
			$this->redirect_with_notice(
				__( 'That doesn\'t look like a valid AT Protocol DID. It should start with did:plc: or did:web:.', 'fosse' ),
				'error'
			);
			return;
		}

		if ( ! class_exists( '\Atmosphere\OAuth\Resolver' )
			|| ! method_exists( '\Atmosphere\OAuth\Resolver', 'resolve_did' )
			|| ! method_exists( '\Atmosphere\OAuth\Resolver', 'pds_from_did_doc' )
		) {
			$this->redirect_with_notice(
				__( 'Identity recovery is unavailable: the bundled Atmosphere is missing the resolver API.', 'fosse' ),
				'error'
			);
			return;
		}

		// Use the same eligibility + canonical-form helper that the
		// domain-handle SET path uses. Without this, the recovery flow can
		// claim a domain handle on a site that's not actually eligible
		// (subdirectory install, non-routable host, etc.), or reject a
		// legitimate IDN site by comparing the raw UTF-8 form against the
		// punycoded `at://xn--…` entry the DID document actually stores.
		$site_host = $this->get_eligible_canonical_site_host();
		if ( '' === $site_host ) {
			$this->redirect_with_notice(
				__( 'This site\'s WordPress Address (URL) is not eligible to be a Bluesky handle (subdirectory install, non-routable host, or an internationalized name this server can\'t encode). Fix the Site URL in Settings → General, or set up the handle on a different installation first.', 'fosse' ),
				'error'
			);
			return;
		}

		$did_doc = \Atmosphere\OAuth\Resolver::resolve_did( $did );
		if ( is_wp_error( $did_doc ) ) {
			$this->redirect_with_notice(
				sprintf(
					/* translators: %s: upstream error message from DID document lookup. */
					__( 'Could not fetch the DID document: %s', 'fosse' ),
					$did_doc->get_error_message()
				),
				'error'
			);
			return;
		}

		$expected_aka = 'at://' . $site_host;
		$also_known   = array();
		if ( isset( $did_doc['alsoKnownAs'] ) && is_array( $did_doc['alsoKnownAs'] ) ) {
			foreach ( $did_doc['alsoKnownAs'] as $aka ) {
				if ( is_string( $aka ) ) {
					$also_known[] = strtolower( $aka );
				}
			}
		}

		if ( ! in_array( $expected_aka, $also_known, true ) ) {
			$listed = empty( $also_known )
				? __( '(none)', 'fosse' )
				: implode( ', ', array_slice( $also_known, 0, 3 ) );
			$this->redirect_with_notice(
				sprintf(
					/* translators: 1: WordPress site host (e.g. example.com); 2: comma-separated list of at:// handles the DID document actually lists. */
					__( 'This DID does not list %1$s as one of its handles (its document claims: %2$s). FOSSE only restores identity for a DID whose document lists this site.', 'fosse' ),
					$site_host,
					$listed
				),
				'error'
			);
			return;
		}

		$pds = \Atmosphere\OAuth\Resolver::pds_from_did_doc( $did_doc );
		if ( is_wp_error( $pds ) ) {
			$this->redirect_with_notice(
				sprintf(
					/* translators: %s: upstream error message from PDS extraction. */
					__( 'Could not find a PDS endpoint in the DID document: %s', 'fosse' ),
					$pds->get_error_message()
				),
				'error'
			);
			return;
		}

		$identity = array(
			'did'          => $did,
			'handle'       => $site_host,
			'pds_endpoint' => $pds,
		);

		// autoload=true matches Atmosphere\get_identity()'s lazy-migration write
		// so subsequent get_option() calls hit the autoloaded cache rather than
		// re-fetching from the options table.
		update_option(
			'atmosphere_identity',
			$identity,
			true
		);

		// `update_option()` returns false both on DB write failure AND on
		// "value didn't change" (e.g. a hostile filter pinning the option,
		// or a stale autoloaded row that matches), so the return value
		// alone isn't a reliable success signal. Re-read the option and
		// verify the full identity trio landed — not just the DID. A
		// hostile `pre_option_atmosphere_identity` filter could pin the
		// option with the same DID but a different handle or PDS endpoint,
		// which would silently route the well-known route's handle field
		// and Atmosphere\get_pds_endpoint() against attacker-controlled
		// values while the handler reports success.
		$persisted = get_option( 'atmosphere_identity', array() );
		if ( ! is_array( $persisted )
			|| ( $persisted['did'] ?? '' ) !== $identity['did']
			|| ( $persisted['handle'] ?? '' ) !== $identity['handle']
			|| ( $persisted['pds_endpoint'] ?? '' ) !== $identity['pds_endpoint']
		) {
			$this->redirect_with_notice(
				__( 'Could not persist the restored Bluesky identity. The option write failed or was overridden by a filter. Check database write access and try again.', 'fosse' ),
				'error'
			);
			return;
		}

		// Echo the persisted values into the success notice. The admin pasted
		// the DID by hand and is still on the page that triggered the action;
		// surfacing the resolved DID + PDS at the moment of commit gives them
		// the last opportunity to spot a wrong paste (e.g. from a phishing
		// support thread) before they continue on to the OAuth handoff. The
		// adjacent "Forget Bluesky identity" panel is the undo if it does
		// look wrong.
		$this->redirect_with_notice(
			sprintf(
				/* translators: 1: site host the user can now reconnect with (e.g. example.com); 2: persisted DID (did:plc:…); 3: persisted PDS endpoint URL. */
				__( 'Restored Bluesky identity for %1$s. DID: %2$s. PDS: %3$s. Click Connect Bluesky to reauthorize. If anything looks wrong, scroll down to "Forget Bluesky identity" to clear it.', 'fosse' ),
				$site_host,
				$identity['did'],
				$identity['pds_endpoint']
			),
			'success'
		);
	}

	/**
	 * Eligible, canonical Bluesky-handle form of the current site's host.
	 *
	 * Returns `''` when the site is not in a shape that can legally host a
	 * Bluesky domain handle — subdirectory install, non-routable host, or
	 * an internationalized name that this server can't encode to punycode
	 * (`intl` extension missing). Returns the lowercased ASCII handle
	 * otherwise — punycoded for IDN sites, exactly as the AT Protocol
	 * handle lexicon expects and as Bluesky_Domain_Handle::set_handle()
	 * would store it.
	 *
	 * Shared by the recovery handler and renderer so the gate, the
	 * alsoKnownAs comparison, and the persisted handle string all agree
	 * about what "this site's handle" is. Diverging here is how the panel
	 * ends up either offered on an ineligible install or comparing a
	 * raw IDN host against the DID's punycoded entry.
	 *
	 * @return string
	 */
	private function get_eligible_canonical_site_host(): string {
		if ( ! class_exists( Bluesky_Domain_Handle::class ) ) {
			return '';
		}
		if ( ! Bluesky_Domain_Handle::is_root_install() ) {
			return '';
		}
		if ( ! Bluesky_Domain_Handle::is_resolvable_host() ) {
			return '';
		}
		return Bluesky_Domain_Handle::get_target_handle();
	}

	/**
	 * Clear the persisted Bluesky identity from this site.
	 *
	 * Counterpart to {@see self::handle_restore_identity()}. Disconnect now
	 * preserves `atmosphere_identity` so a domain-handle site can reconnect
	 * without losing the bidirectional verification anchor. That contract
	 * leaves no in-product path to fully sever the DID link in two real
	 * cases:
	 *
	 *  1. Site transfer / ownership change. The previous owner's DID stays
	 *     advertised by `/.well-known/atproto-did` and the publication
	 *     link tag until somebody clears it.
	 *  2. Recovery undo. The admin pastes the wrong DID into the recovery
	 *     panel (phish, support thread mix-up). The success notice echoes
	 *     the persisted DID/PDS as a sanity check, but the admin still
	 *     needs a button to revert if it looks wrong.
	 *
	 * The action is deliberately destructive — once cleared, the
	 * well-known route 404s and external resolvers stop trusting any
	 * cached binding. Capability + nonce gated like every other admin-post
	 * handler in this class.
	 *
	 * @return void
	 */
	public function handle_forget_identity(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_forget_bluesky_identity' );

		if ( function_exists( '\Atmosphere\is_connected' ) && \Atmosphere\is_connected() ) {
			$this->redirect_with_notice(
				__( 'Disconnect Bluesky before forgetting this site\'s identity. You can forget the saved identity after the Bluesky account is disconnected.', 'fosse' ),
				'error'
			);
			return;
		}

		// Capture the DID before deletion so the notice can confirm what
		// just went away (and so a "did nothing" double-click on a fresh
		// install doesn't read as success).
		$cleared_did = '';
		if ( function_exists( '\Atmosphere\get_did' ) ) {
			$cleared_did = (string) \Atmosphere\get_did();
		}

		if ( '' === $cleared_did ) {
			$this->redirect_with_notice(
				__( 'There is no Bluesky identity on file to forget.', 'fosse' ),
				'info'
			);
			return;
		}

		delete_option( 'atmosphere_identity' );

		// Re-read to verify the delete landed. A hostile
		// `pre_option_atmosphere_identity` filter or a sticky autoloaded
		// row could otherwise leave the option in place while the handler
		// reports success.
		if ( function_exists( '\Atmosphere\has_identity' ) && \Atmosphere\has_identity() ) {
			$this->redirect_with_notice(
				__( 'Could not clear the Bluesky identity. The option may be pinned by a filter or stuck in autoload. Check database write access and try again.', 'fosse' ),
				'error'
			);
			return;
		}

		$this->redirect_with_notice(
			sprintf(
				/* translators: %s: DID that was cleared (e.g. did:plc:abcdef…). */
				__( 'Forgot Bluesky identity %s. This site no longer claims that DID; the .well-known/atproto-did route now returns 404.', 'fosse' ),
				$cleared_did
			),
			'success'
		);
	}

	/**
	 * Re-enable Bluesky auto-publishing for sites whose option is stuck at `'0'`.
	 *
	 * Backstop for the only state where the now-removed auto-publish toggle
	 * left a user without a way to recover: a site that explicitly set
	 * `atmosphere_auto_publish` to `'0'` before the toggle was removed has
	 * no UI to flip it back on, and the connection silently drops new
	 * posts. The notice rendered by
	 * {@see self::maybe_render_auto_publish_disabled_notice()} surfaces a
	 * one-click re-enable form that posts here.
	 *
	 * @return void
	 */
	public function handle_enable_auto_publish(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_enable_bluesky_auto_publish' );

		update_option( 'atmosphere_auto_publish', '1' );

		$this->redirect_with_notice(
			__( 'Bluesky auto-publishing is back on. New posts will be sent to Bluesky.', 'fosse' ),
			'success'
		);
	}

	/**
	 * Render an admin notice on FOSSE pages when Bluesky auto-publishing
	 * is explicitly disabled in the database.
	 *
	 * The auto-publish toggle was removed from the FOSSE Settings UI
	 * (Atmosphere has no per-post manual publish surface to back it up;
	 * see {@see self::render_setup_section()} for the full rationale).
	 * For every site at the default (`'1'` on, or option absent) this
	 * removal is invisible. For the narrow population that explicitly
	 * set the option to `'0'` BEFORE the toggle went away, the connection
	 * silently drops every new post — and they have no UI to recover.
	 * This notice is the recovery path: one-click re-enable form rendered
	 * only when the option is explicitly `'0'`.
	 *
	 * Scoped to FOSSE admin pages so it doesn't leak across wp-admin.
	 *
	 * @return void
	 */
	public function maybe_render_auto_publish_disabled_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || ! Menu::is_fosse_admin_screen( $screen ) ) {
			return;
		}

		// Only fire when the option is EXPLICITLY '0' — distinguish from
		// "absent" (default-on) by reading without a default and checking
		// the raw value. `get_option(..., '1')` would mask the explicit-off
		// state behind the default.
		$stored = get_option( 'atmosphere_auto_publish', null );
		if ( '0' !== $stored ) {
			return;
		}

		// Only show for connected sites — a disconnected site has nothing
		// to publish to anyway, so the notice would be noise.
		if ( ! $this->is_connected() ) {
			return;
		}

		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Bluesky auto-publishing is off.', 'fosse' ); ?></strong>
				<?php
				esc_html_e(
					'New posts aren\'t being sent to Bluesky. If this was intentional, no action is needed; otherwise, turn auto-publishing back on below.',
					'fosse'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="fosse-auto-publish-recover__form">
				<input type="hidden" name="action" value="fosse_enable_bluesky_auto_publish" />
				<?php wp_nonce_field( 'fosse_enable_bluesky_auto_publish' ); ?>
				<?php submit_button( __( 'Turn auto-publishing back on', 'fosse' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the OAuth callback when Atmosphere returns to the FOSSE page.
	 *
	 * @return void
	 */
	public function handle_oauth_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback query args are validated via state in Atmosphere\OAuth\Client::handle_callback().
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
		if ( 'fosse' !== $page ) {
			return;
		}

		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// No code and no state → ordinary page hit, not an OAuth callback.
		if ( '' === $code && '' === $state ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to complete the Bluesky connection.', 'fosse' ) );
		}

		// Exactly one of code/state present means the auth server redirected
		// back but something stripped the other parameter mid-flight. Treat
		// it as a real error instead of silently rendering an empty page.
		if ( '' === $code || '' === $state ) {
			self::record_connection_failed( 'bluesky', '', 'auth_failed' );
			$this->redirect_with_notice(
				__( 'Bluesky returned an incomplete response. Please try connecting again.', 'fosse' ),
				'error'
			);
			return;
		}

		// Resolve the return context against the inbound state before we hand
		// off to Atmosphere so a stale or replayed callback can't strip the
		// wizard marker away from a legitimate callback that arrives later.
		$return_context = $this->consume_oauth_return_context( $state );
		$source         = self::context_to_source( $return_context );

		$result = \Atmosphere\OAuth\Client::handle_callback( $code, $state );

		if ( is_wp_error( $result ) ) {
			self::record_connection_failed( 'bluesky', $source, self::categorize_wp_error( $result ) );
			$this->redirect_with_notice( $result->get_error_message(), 'error', $return_context );
			return;
		}

		// Atmosphere reports success on token exchange but writes the
		// connection option separately. If that write was lost (DB error,
		// hostile filter), `is_connected()` flips back to false — surface
		// that instead of falsely telling the user they're connected.
		if ( ! \Atmosphere\is_connected() ) {
			self::record_connection_failed( 'bluesky', $source, 'other' );
			$this->redirect_with_notice(
				__( 'Bluesky responded successfully, but the connection was not saved. Please try connecting again.', 'fosse' ),
				'error',
				$return_context
			);
			return;
		}

		// Connection succeeded. Subsequent publication-setup failures are
		// surfaced as warnings (the Bluesky link itself is good), so they
		// emit `_completed`, not `_failed`.
		self::record_metric(
			'fosse_connection_completed',
			array(
				'network' => 'bluesky',
				'source'  => $source,
			)
		);

		if ( ! method_exists( '\Atmosphere\Publisher', 'sync_publication' ) ) {
			$this->redirect_with_notice(
				__( 'Connected to Bluesky, but publication setup is unavailable in the current Atmosphere version. Posts will not sync until this is resolved.', 'fosse' ),
				'warning',
				$return_context
			);
			return;
		}

		$sync_result = \Atmosphere\Publisher::sync_publication();

		if ( is_wp_error( $sync_result ) ) {
			$this->redirect_with_notice(
				sprintf(
					/* translators: %s: error message from Atmosphere publication setup. */
					__( 'Connected to Bluesky, but publication setup failed: %s', 'fosse' ),
					$sync_result->get_error_message()
				),
				'warning',
				$return_context
			);
			return;
		}

		$this->redirect_with_notice( __( 'Successfully connected to Bluesky.', 'fosse' ), 'success', $return_context );
	}

	/**
	 * Map an OAuth return context to the canonical `source` property.
	 *
	 * @param string $return_context Context string (typically `'wizard'` or `''`).
	 * @return string `'wizard'` when initiated from the wizard, `'settings'` otherwise.
	 */
	private static function context_to_source( string $return_context ): string {
		return self::RETURN_CONTEXT_WIZARD === $return_context ? 'wizard' : 'settings';
	}

	/**
	 * Map a `WP_Error` to the funnel's bounded `error_category` enum.
	 *
	 * Pre-classified categories are part of the privacy contract: raw
	 * upstream messages never leave the recorder. Codes recognized
	 * here come from Atmosphere's OAuth client and the AT Protocol
	 * handle-resolution path; anything else maps to `'other'`.
	 *
	 * @param \WP_Error $error WP_Error from Atmosphere\OAuth\Client.
	 * @return string `'auth_failed'|'rate_limited'|'network_timeout'|'invalid_handle'|'other'`.
	 */
	private static function categorize_wp_error( \WP_Error $error ): string {
		$code = (string) $error->get_error_code();

		if ( false !== \stripos( $code, 'rate' ) || '429' === $code ) {
			return 'rate_limited';
		}
		if ( false !== \stripos( $code, 'timeout' ) || false !== \stripos( $code, 'http_request_failed' ) ) {
			return 'network_timeout';
		}
		if ( false !== \stripos( $code, 'handle' ) || false !== \stripos( $code, 'pds' ) || false !== \stripos( $code, 'did' ) ) {
			return 'invalid_handle';
		}
		if (
			false !== \stripos( $code, 'auth' ) ||
			false !== \stripos( $code, 'oauth' ) ||
			false !== \stripos( $code, 'token' ) ||
			false !== \stripos( $code, 'state' ) ||
			false !== \stripos( $code, 'expired' ) ||
			false !== \stripos( $code, 'dpop' ) ||
			false !== \stripos( $code, 'decrypt' ) ||
			false !== \stripos( $code, 'refresh' )
		) {
			return 'auth_failed';
		}

		// Any remaining Atmosphere-prefixed code is most plausibly
		// an OAuth-layer failure (PAR, connection, not_connected, etc.)
		// rather than an unclassifiable error. Catching them here
		// keeps the `error_category` enum representative on dashboards.
		if ( 0 === \stripos( $code, 'atmosphere_' ) ) {
			return 'auth_failed';
		}

		return 'other';
	}

	/**
	 * Emit a `fosse_connection_failed` event for the given network/source/category.
	 *
	 * @param string $network        `'bluesky'|'mastodon'`.
	 * @param string $source         `'wizard'|'settings'` (or `''` when unknown — recorder allowlist drops empty).
	 * @param string $error_category Pre-classified error category.
	 * @return void
	 */
	private static function record_connection_failed( string $network, string $source, string $error_category ): void {
		$properties = array(
			'network'        => $network,
			'error_category' => $error_category,
		);
		if ( '' !== $source ) {
			$properties['source'] = $source;
		}

		self::record_metric( 'fosse_connection_failed', $properties );
	}

	/**
	 * Forward to `Recorder::record()` if the metrics module is loaded.
	 *
	 * The class_exists guard keeps this provider safe to load on
	 * checkouts that haven't shipped the metrics spine yet.
	 *
	 * @param string $event      Event name.
	 * @param array  $properties Property bag.
	 * @return void
	 */
	private static function record_metric( string $event, array $properties ): void {
		if ( ! \class_exists( \Automattic\Fosse\Metrics\Recorder::class ) ) {
			return;
		}
		\Automattic\Fosse\Metrics\Recorder::record( $event, $properties );
	}

	/**
	 * Override Atmosphere's OAuth callback URI for FOSSE-initiated auth.
	 *
	 * @param string $uri Default Atmosphere URI.
	 * @return string
	 */
	public function filter_oauth_redirect_uri( string $uri ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter callback signature.
		return admin_url( 'admin.php?page=fosse' );
	}

	/**
	 * Persist an admin notice and redirect back to the originating FOSSE screen.
	 *
	 * Messages are escaped with `esc_html()` before storage. Every consumer of
	 * the `'atmosphere'` settings-error group renders the stored `message`
	 * field as HTML: the explicit `settings_errors( 'atmosphere' )` call in
	 * {@see self::render_connection_actions()}, the `get_settings_errors(...)`
	 * loop in {@see Onboarding_Wizard::render_step_bluesky()}, and any
	 * `options-*.php` screen where WordPress core's automatic
	 * `settings_errors()` invocation fires for queued settings errors.
	 * Several callers here pass untrusted text (`WP_Error` messages from
	 * Atmosphere's OAuth client, the PDS, or `Publisher::sync_publication()`)
	 * which could otherwise inject markup into wp-admin. Escaping at this
	 * single chokepoint frees every caller from remembering the constraint.
	 * Notice messages that need rich HTML must be added through a different
	 * code path; nothing in FOSSE needs that today.
	 *
	 * @param string $message        Notice message (treated as plain text).
	 * @param string $type           Notice type.
	 * @param string $return_context Optional return context.
	 * @return void
	 */
	private function redirect_with_notice( string $message, string $type, string $return_context = '' ): void {
		add_settings_error( 'atmosphere', 'fosse_bluesky_notice', esc_html( $message ), $type );
		User_Notices::persist();

		wp_safe_redirect( $this->get_redirect_url( $return_context ) );
		exit;
	}

	/**
	 * Read the requested return context from a connect form submission.
	 *
	 * Caller MUST verify the form nonce before invoking — the PHPCS nonce
	 * check is suppressed on that assumption.
	 *
	 * @return string
	 */
	private function get_connect_return_context(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller (handle_connect) verifies the nonce before this helper runs.
		$raw = isset( $_POST[ self::RETURN_CONTEXT_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::RETURN_CONTEXT_FIELD ] ) ) : '';

		return self::normalize_return_context( $raw );
	}

	/**
	 * Coerce an arbitrary value to a known return-context slug.
	 *
	 * Single chokepoint for return-context normalization: every read,
	 * write, and comparison routes through here so adding a new context
	 * value is a one-line change.
	 *
	 * @param mixed $raw Raw value to normalize.
	 * @return string Normalized context slug, or `''` for unknown input.
	 */
	private static function normalize_return_context( $raw ): string {
		return is_string( $raw ) && self::RETURN_CONTEXT_WIZARD === $raw ? self::RETURN_CONTEXT_WIZARD : '';
	}

	/**
	 * Remember where a successful OAuth start should return after callback.
	 *
	 * Binds the return context to Atmosphere's freshly-minted OAuth state so a
	 * stale or replayed callback can't consume it before the legitimate one
	 * arrives. `Client::authorize()` always writes `atmosphere_oauth_state`
	 * before returning the auth URL, so reading it here is safe.
	 *
	 * @param string $return_context Return context.
	 * @return void
	 */
	private function remember_oauth_return_context( string $return_context ): void {
		$key = $this->get_oauth_return_transient_key();
		if ( '' === $key ) {
			return;
		}

		delete_transient( $key );

		$context = self::normalize_return_context( $return_context );
		if ( '' === $context ) {
			return;
		}

		$oauth_state = get_transient( 'atmosphere_oauth_state' );
		if ( ! is_string( $oauth_state ) || '' === $oauth_state ) {
			return;
		}

		set_transient(
			$key,
			array(
				'context' => $context,
				'state'   => $oauth_state,
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Clear any remembered OAuth return context for the current user.
	 *
	 * @return void
	 */
	private function forget_oauth_return_context(): void {
		$key = $this->get_oauth_return_transient_key();
		if ( '' === $key ) {
			return;
		}

		delete_transient( $key );
	}

	/**
	 * Consume any remembered OAuth return context for the current user.
	 *
	 * Only deletes and returns the context when the inbound callback state
	 * matches the OAuth state the marker was bound to. A non-matching state
	 * leaves the marker intact so the legitimate callback can still find it.
	 *
	 * @param string $callback_state Inbound OAuth `state` query arg.
	 * @return string
	 */
	private function consume_oauth_return_context( string $callback_state ): string {
		$key = $this->get_oauth_return_transient_key();
		if ( '' === $key ) {
			return '';
		}

		$stored = get_transient( $key );

		if ( ! is_array( $stored ) ) {
			return '';
		}

		$stored_state   = isset( $stored['state'] ) && is_string( $stored['state'] ) ? $stored['state'] : '';
		$stored_context = isset( $stored['context'] ) && is_string( $stored['context'] ) ? $stored['context'] : '';

		if ( '' === $stored_state || '' === $callback_state || ! hash_equals( $stored_state, $callback_state ) ) {
			return '';
		}

		delete_transient( $key );

		return self::normalize_return_context( $stored_context );
	}

	/**
	 * Build the per-user OAuth return transient key.
	 *
	 * Returns `''` for an unauthenticated context (`get_current_user_id() === 0`)
	 * so the per-user namespace can't collapse into a shared key for all
	 * anonymous requests. Callers MUST treat an empty key as "skip".
	 *
	 * @return string
	 */
	private function get_oauth_return_transient_key(): string {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return '';
		}

		return self::OAUTH_RETURN_TRANSIENT_PREFIX . $user_id;
	}

	/**
	 * Build the admin URL for a return context.
	 *
	 * @param string $return_context Return context.
	 * @return string
	 */
	private function get_redirect_url( string $return_context ): string {
		if ( self::RETURN_CONTEXT_WIZARD === self::normalize_return_context( $return_context ) ) {
			return add_query_arg(
				array(
					'page'             => 'fosse-wizard',
					'step'             => 'bluesky',
					'settings-updated' => 'true',
				),
				admin_url( 'admin.php' )
			);
		}

		return admin_url( 'admin.php?page=fosse&settings-updated=true' );
	}
}
