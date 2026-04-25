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
 */
class Bluesky_Provider implements Connection_Provider {

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
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( '\Atmosphere\Atmosphere' );
	}

	/**
	 * Get current Bluesky connection status from Atmosphere.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$connection   = function_exists( '\Atmosphere\get_connection' ) ? \Atmosphere\get_connection() : array();
		$connected    = function_exists( '\Atmosphere\is_connected' ) ? \Atmosphere\is_connected() : false;
		$auto_publish = '1' === get_option( 'atmosphere_auto_publish', '1' );
		$token_error  = null;

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
			'auto_publish' => $auto_publish,
			'token_error'  => $token_error,
		);
	}

	/**
	 * Render the Bluesky setup section on the FOSSE Setup page.
	 *
	 * @return void
	 */
	public function render_setup_section(): void {
		$status = $this->get_status();

		?>
		<div class="fosse-provider-section">
			<h2><?php esc_html_e( 'Bluesky', 'fosse' ); ?></h2>

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
						<th scope="row"><?php esc_html_e( 'Auto Publish', 'fosse' ); ?></th>
						<td><?php echo esc_html( $status['auto_publish'] ? __( 'Enabled', 'fosse' ) : __( 'Disabled', 'fosse' ) ); ?></td>
					</tr>
					<?php if ( $status['token_error'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Token Health', 'fosse' ); ?></th>
							<td><?php echo esc_html( $status['token_error'] ); ?></td>
						</tr>
					<?php endif; ?>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fosse_disconnect_bluesky" />
					<input type="hidden" name="atmosphere_nonce" value="<?php echo esc_attr( wp_create_nonce( 'atmosphere_disconnect' ) ); ?>" />
					<?php submit_button( __( 'Disconnect Bluesky', 'fosse' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>

			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=atmosphere' ) ); ?>">
					<?php esc_html_e( 'Show advanced Atmosphere settings', 'fosse' ); ?>
				</a>
			</p>
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
			<h3>
				<span class="fosse-status-indicator <?php echo $status['connected'] ? 'connected' : 'disconnected'; ?>"></span>
				<?php esc_html_e( 'Bluesky', 'fosse' ); ?>
			</h3>

			<table class="widefat striped">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Connection', 'fosse' ); ?></td>
						<td><?php echo esc_html( $status['connected'] ? __( 'Connected', 'fosse' ) : __( 'Disconnected', 'fosse' ) ); ?></td>
					</tr>
					<?php if ( $status['handle'] ) : ?>
						<tr>
							<td><?php esc_html_e( 'Handle', 'fosse' ); ?></td>
							<td><strong><?php echo esc_html( $status['handle'] ); ?></strong></td>
						</tr>
					<?php endif; ?>
					<?php if ( $status['did'] ) : ?>
						<tr>
							<td><?php esc_html_e( 'DID', 'fosse' ); ?></td>
							<td><code><?php echo esc_html( $status['did'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( $status['pds_endpoint'] ) : ?>
						<tr>
							<td><?php esc_html_e( 'PDS', 'fosse' ); ?></td>
							<td><code><?php echo esc_html( $status['pds_endpoint'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<td><?php esc_html_e( 'Auto Publish', 'fosse' ); ?></td>
						<td><?php echo esc_html( $status['auto_publish'] ? __( 'Enabled', 'fosse' ) : __( 'Disabled', 'fosse' ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Token Health', 'fosse' ); ?></td>
						<td><?php echo esc_html( $status['token_error'] ? $status['token_error'] : __( 'OK', 'fosse' ) ); ?></td>
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
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
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

		$handle = sanitize_text_field( wp_unslash( $_POST['bluesky_handle'] ?? '' ) );

		if ( empty( $handle ) ) {
			$this->redirect_with_notice( __( 'Enter a Bluesky handle to continue.', 'fosse' ), 'error' );
		}

		add_filter( 'atmosphere_oauth_redirect_uri', array( $this, 'filter_oauth_redirect_uri' ) );
		$auth_url = \Atmosphere\OAuth\Client::authorize( $handle );
		remove_filter( 'atmosphere_oauth_redirect_uri', array( $this, 'filter_oauth_redirect_uri' ) );

		if ( is_wp_error( $auth_url ) ) {
			$this->redirect_with_notice( $auth_url->get_error_message(), 'error' );
		}

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

		check_admin_referer( 'atmosphere_disconnect', 'atmosphere_nonce' );

		\Atmosphere\OAuth\Client::disconnect();

		$this->redirect_with_notice( __( 'Disconnected from Bluesky.', 'fosse' ), 'info' );
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

		if ( empty( $code ) || empty( $state ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = \Atmosphere\OAuth\Client::handle_callback( $code, $state );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $result->get_error_message(), 'error' );
		}

		if ( method_exists( '\Atmosphere\Publisher', 'sync_publication' ) ) {
			\Atmosphere\Publisher::sync_publication();
		}

		$this->redirect_with_notice( __( 'Successfully connected to Bluesky.', 'fosse' ), 'success' );
	}

	/**
	 * Override Atmosphere's OAuth callback URI for FOSSE-initiated auth.
	 *
	 * @param string $uri Default Atmosphere URI.
	 * @return string
	 */
	public function filter_oauth_redirect_uri( string $uri ): string {
		unset( $uri );

		return admin_url( 'admin.php?page=fosse' );
	}

	/**
	 * Persist an admin notice and redirect back to the FOSSE setup page.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type.
	 * @return void
	 */
	private function redirect_with_notice( string $message, string $type ): void {
		add_settings_error( 'atmosphere', 'fosse_bluesky_notice', $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=fosse' ) );
		exit;
	}
}
