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
	 * Get current Bluesky connection status from Atmosphere.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$connection  = \Atmosphere\get_connection();
		$connected   = \Atmosphere\is_connected();
		$token_error = null;

		if ( $connected && method_exists( '\Atmosphere\OAuth\Client', 'access_token' ) ) {
			$token = \Atmosphere\OAuth\Client::access_token();
			if ( is_wp_error( $token ) ) {
				$token_error = $token->get_error_message();
			}
		}

		return array(
			'connected'    => $connected,
			'handle'       => $connection['handle'] ?? '',
			'did'          => $connection['did'] ?? '',
			'pds_endpoint' => $connection['pds_endpoint'] ?? '',
			'token_error'  => $token_error,
		);
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
		$status = $this->get_status();
		?>
		<div class="fosse-connection-section" id="fosse-provider-bluesky">
			<h3><?php esc_html_e( 'Bluesky', 'fosse' ); ?></h3>

			<?php settings_errors( 'atmosphere' ); ?>

			<?php if ( ! $status['connected'] ) : ?>
				<p><?php esc_html_e( 'Connect your Bluesky account to let FOSSE publish through Atmosphere.', 'fosse' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fosse_connect_bluesky" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_connect_bluesky' ) ); ?>" />

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="fosse_bluesky_handle"><?php esc_html_e( 'Handle', 'fosse' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="regular-text"
									name="bluesky_handle"
									id="fosse_bluesky_handle"
									placeholder="alice.bsky.social"
								/>
								<p class="description"><?php esc_html_e( 'Your AT Protocol handle, e.g. alice.bsky.social or your own domain.', 'fosse' ); ?></p>
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
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Connect Bluesky', 'fosse' ) ); ?>
				</form>
			<?php else : ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Handle', 'fosse' ); ?></th>
						<td><strong><?php echo esc_html( $status['handle'] ); ?></strong></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'DID', 'fosse' ); ?></th>
						<td><code><?php echo esc_html( $status['did'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'PDS', 'fosse' ); ?></th>
						<td><code><?php echo esc_html( $status['pds_endpoint'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Token Health', 'fosse' ); ?></th>
						<td><?php echo esc_html( $status['token_error'] ? $status['token_error'] : __( 'OK', 'fosse' ) ); ?></td>
					</tr>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fosse_disconnect_bluesky" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_disconnect_bluesky' ) ); ?>" />
					<?php submit_button( __( 'Disconnect Bluesky', 'fosse' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Bluesky status card on the FOSSE Status page.
	 *
	 * @return void
	 */
	public function render_status_card(): void {
		$status = $this->get_status();
		?>
		<div class="fosse-status-card">
			<h2>
				<span
					class="fosse-status-indicator <?php echo $status['connected'] ? 'connected' : 'disconnected'; ?>"
					role="img"
					aria-label="<?php echo esc_attr( $status['connected'] ? __( 'Connected', 'fosse' ) : __( 'Disconnected', 'fosse' ) ); ?>"
				></span>
				<?php esc_html_e( 'Bluesky', 'fosse' ); ?>
			</h2>

			<table class="widefat striped fosse-status-card__table">
				<tbody>
					<tr>
						<td class="fosse-status-card__label"><?php esc_html_e( 'Connection', 'fosse' ); ?></td>
						<td class="fosse-status-card__value"><?php echo esc_html( $status['connected'] ? __( 'Connected', 'fosse' ) : __( 'Disconnected', 'fosse' ) ); ?></td>
					</tr>
					<?php if ( $status['handle'] ) : ?>
						<tr>
							<td class="fosse-status-card__label"><?php esc_html_e( 'Handle', 'fosse' ); ?></td>
							<td class="fosse-status-card__value">
								<strong class="fosse-status-card__token fosse-status-card__token--handle">
									<?php
									echo Status_Formatter::handle( $status['handle'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status_Formatter::handle() escapes input and returns safe HTML with <wbr>.
									?>
								</strong>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( $status['did'] ) : ?>
						<tr>
							<td class="fosse-status-card__label"><?php esc_html_e( 'DID', 'fosse' ); ?></td>
							<td class="fosse-status-card__value">
								<code class="fosse-status-card__token fosse-status-card__token--did">
									<?php
									echo Status_Formatter::did( $status['did'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status_Formatter::did() escapes input and returns safe HTML with <wbr>.
									?>
								</code>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( $status['pds_endpoint'] ) : ?>
						<tr>
							<td class="fosse-status-card__label"><?php esc_html_e( 'PDS', 'fosse' ); ?></td>
							<td class="fosse-status-card__value">
								<code class="fosse-status-card__token fosse-status-card__token--url">
									<?php
									echo Status_Formatter::url( $status['pds_endpoint'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status_Formatter::url() escapes input and returns safe HTML with <wbr>.
									?>
								</code>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<td class="fosse-status-card__label"><?php esc_html_e( 'Token Health', 'fosse' ); ?></td>
						<td class="fosse-status-card__value">
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
						</td>
					</tr>
				</tbody>
			</table>
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
		add_action( 'admin_post_fosse_enable_bluesky_auto_publish', array( $this, 'handle_enable_auto_publish' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_auto_publish_disabled_notice' ) );
		add_action( 'init', array( $this, 'serve_atproto_did_well_known' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_suppress_atmosphere_well_known' ), 1 );

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
	public function save_settings( array $post_data ): bool {
		unset( $post_data );

		return true;
	}

	/**
	 * Serve /.well-known/atproto-did when FOSSE owns the route.
	 *
	 * Returns silently for unrelated paths, when the
	 * `fosse_serve_atproto_did_well_known` filter opts out, and when
	 * Atmosphere isn't loaded. Sends a 404 and exits when Atmosphere is
	 * loaded but no DID is available; otherwise sends a `text/plain` body
	 * containing the connected DID and exits.
	 *
	 * @return void
	 */
	public function serve_atproto_did_well_known(): void {
		// Path-match below uses strict equality; sanitize_text_field can normalize
		// encoded characters in surprising ways, so read raw.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$response    = $this->get_atproto_did_well_known_response( $request_uri );

		if ( null === $response ) {
			return;
		}

		if ( 404 === $response['status'] ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		nocache_headers();
		echo $response['did']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain response; DID syntax validated in get_atproto_did_well_known_response().
		exit;
	}

	/**
	 * Resolve the response data for FOSSE's /.well-known/atproto-did handler.
	 *
	 * @param string $request_uri Request URI.
	 * @return array{status:200|404,did:string}|null Null when FOSSE should not handle the request; otherwise a status code and the DID (empty for 404).
	 */
	private function get_atproto_did_well_known_response( string $request_uri ): ?array {
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( '/.well-known/atproto-did' !== $path ) {
			return null;
		}

		/**
		 * Filter whether FOSSE serves the /.well-known/atproto-did route.
		 *
		 * Disable to let another component (CDN, custom rewrite, etc.) own the path.
		 *
		 * @param bool $serve Default true.
		 */
		if ( ! apply_filters( 'fosse_serve_atproto_did_well_known', true ) ) {
			return null;
		}

		if ( ! function_exists( '\Atmosphere\is_connected' ) ) {
			// Atmosphere isn't loaded. That's a structural error, not a
			// user-facing "no connection" state. Decline to handle so a
			// normal 404 happens via WordPress's main request flow.
			return null;
		}

		if ( ! \Atmosphere\is_connected() ) {
			return array(
				'status' => 404,
				'did'    => '',
			);
		}

		$connection = \Atmosphere\get_connection();
		$did        = isset( $connection['did'] ) ? (string) $connection['did'] : '';

		// Validate the DID against AT Proto syntax before promising to serve it.
		// The response is plain text and a malformed value (newlines, control chars,
		// HTML bytes) would corrupt the body or worse. Valid AT Proto DIDs are
		// "did:" + method + ":" + ASCII alphanumerics with a small punctuation set.
		// \A and \z anchor strictly so a stored DID with a trailing newline (which
		// PHP's $ anchor permits) doesn't slip a stray byte into the response.
		if ( ! preg_match( '/\Adid:[a-z]+:[A-Za-z0-9._:%\-]*[A-Za-z0-9._\-]\z/', $did ) ) {
			return array(
				'status' => 404,
				'did'    => '',
			);
		}

		return array(
			'status' => 200,
			'did'    => $did,
		);
	}

	/**
	 * Suppress bundled Atmosphere's /.well-known/atproto-did handler when FOSSE opts out.
	 *
	 * The fosse_serve_atproto_did_well_known filter only controls FOSSE's own handler.
	 * Atmosphere registers an independent template_redirect handler that would otherwise
	 * still serve the route, defeating the opt-out. Clearing Atmosphere's query var
	 * makes its handler return early so neither plugin serves the route. Also flags the
	 * request 404 so WordPress doesn't render the front page for the well-known URL.
	 *
	 * @return void
	 */
	public function maybe_suppress_atmosphere_well_known(): void {
		if ( 'atproto-did' !== get_query_var( 'atmosphere_wellknown' ) ) {
			return;
		}

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

		$handle = sanitize_text_field( wp_unslash( $_POST['bluesky_handle'] ?? '' ) );
		$handle = strtolower( trim( ltrim( trim( $handle ), '@' ) ) );

		if ( empty( $handle ) ) {
			$this->redirect_with_notice( __( 'Enter a Bluesky handle to continue.', 'fosse' ), 'error', $return_context );
			return;
		}

		// AT Protocol handles are domain names: at least one dot, only ASCII
		// alphanumerics and hyphens per label, no leading/trailing hyphens.
		// Pre-validate so users get an actionable hint instead of a raw
		// upstream error like "PDS lookup failed: dns_get_record returned false".
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $handle ) ) {
			$this->redirect_with_notice(
				__( 'That doesn\'t look like a valid handle. Try something like alice.bsky.social or example.com.', 'fosse' ),
				'error',
				$return_context
			);
			return;
		}

		$auth_url = \Atmosphere\OAuth\Client::authorize( $handle );

		if ( is_wp_error( $auth_url ) ) {
			$this->redirect_with_notice( $auth_url->get_error_message(), 'error', $return_context );
			return;
		}

		$this->remember_oauth_return_context( $return_context );

		wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
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

		\Atmosphere\OAuth\Client::disconnect();

		// Disconnect orphans any in-flight wizard return marker; clear it so
		// a subsequent connect attempt starts from a clean slate.
		$this->forget_oauth_return_context();

		// Atmosphere's `disconnect()` returns void and just deletes the
		// connection option. Verify the option actually went away so a DB
		// or filter failure surfaces instead of falsely showing "Disconnected".
		if ( \Atmosphere\is_connected() ) {
			$this->redirect_with_notice(
				__( 'Could not disconnect from Bluesky. Please try again.', 'fosse' ),
				'error'
			);
			return;
		}

		$this->redirect_with_notice( __( 'Disconnected from Bluesky.', 'fosse' ), 'info' );
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
		if ( null === $screen || ! is_string( $screen->id ) || false === strpos( $screen->id, 'fosse' ) ) {
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
		if ( ! $this->get_status()['connected'] ) {
			return;
		}

		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Bluesky auto-publishing is off.', 'fosse' ); ?></strong>
				<?php
				esc_html_e(
					'New posts are not being sent to Bluesky. The toggle that controlled this was removed because FOSSE doesn\'t yet offer per-post manual publishing — until it does, leaving auto-publish off means your Bluesky connection is effectively idle.',
					'fosse'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-bottom: 6px;">
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

		$result = \Atmosphere\OAuth\Client::handle_callback( $code, $state );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $result->get_error_message(), 'error', $return_context );
			return;
		}

		// Atmosphere reports success on token exchange but writes the
		// connection option separately. If that write was lost (DB error,
		// hostile filter), `is_connected()` flips back to false — surface
		// that instead of falsely telling the user they're connected.
		if ( ! \Atmosphere\is_connected() ) {
			$this->redirect_with_notice(
				__( 'Bluesky responded successfully, but the connection was not saved. Please try connecting again.', 'fosse' ),
				'error',
				$return_context
			);
			return;
		}

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
	 * @param string $message        Notice message.
	 * @param string $type           Notice type.
	 * @param string $return_context Optional return context.
	 * @return void
	 */
	private function redirect_with_notice( string $message, string $type, string $return_context = '' ): void {
		add_settings_error( 'atmosphere', 'fosse_bluesky_notice', $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

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
