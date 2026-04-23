<?php
/**
 * ActivityPub connection provider.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Integrates the bundled ActivityPub plugin into FOSSE's admin UI.
 *
 * Self-registers on 'fosse_register_providers'. Stores FOSSE-owned options
 * (fosse_ap_actor_mode, fosse_ap_support_post_types) and projects them to
 * AP via pre_option_* filters so AP reads FOSSE's values transparently.
 */
class AP_Provider implements Connection_Provider {

	/**
	 * Allowed actor mode values.
	 *
	 * @var string[]
	 */
	private const ACTOR_MODES = array( 'actor', 'blog', 'actor_blog' );

	/**
	 * Hook into fosse_register_providers to self-register.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'fosse_register_providers', array( static::class, 'register_provider' ) );
	}

	/**
	 * Register this provider if the AP plugin is available.
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
		return 'activitypub';
	}

	/**
	 * Get the provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ActivityPub';
	}

	/**
	 * Check if the AP plugin is loaded.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( '\Activitypub\Activitypub' );
	}

	/**
	 * Get current AP connection status.
	 *
	 * AP is "connected" whenever the plugin is active — there's no external
	 * auth step. Status includes actor mode, supported post types, and the
	 * fediverse address.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$actor_mode = get_option( 'activitypub_actor_mode', 'actor' );
		$post_types = get_option( 'activitypub_support_post_types', array( 'post' ) );

		return array(
			'connected'  => true,
			'actor_mode' => $actor_mode,
			'post_types' => $post_types,
			'address'    => $this->get_fediverse_address(),
		);
	}

	/**
	 * Render the AP setup section on the FOSSE Setup page.
	 *
	 * @return void
	 */
	public function render_setup_section(): void {
		$actor_mode     = get_option( 'activitypub_actor_mode', 'actor' );
		$post_types     = get_option( 'activitypub_support_post_types', array( 'post' ) );
		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$address        = $this->get_fediverse_address();
		$nonce          = wp_create_nonce( 'fosse_save_ap_settings' );
		?>
		<div class="fosse-provider-section">
			<h2><?php esc_html_e( 'ActivityPub', 'fosse' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fosse_save_ap_settings" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Actor Mode', 'fosse' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="fosse_ap_actor_mode" value="actor" <?php checked( 'actor', $actor_mode ); ?> />
									<?php esc_html_e( 'Author profiles', 'fosse' ); ?>
								</label><br />
								<label>
									<input type="radio" name="fosse_ap_actor_mode" value="blog" <?php checked( 'blog', $actor_mode ); ?> />
									<?php esc_html_e( 'Blog profile', 'fosse' ); ?>
								</label><br />
								<label>
									<input type="radio" name="fosse_ap_actor_mode" value="actor_blog" <?php checked( 'actor_blog', $actor_mode ); ?> />
									<?php esc_html_e( 'Both', 'fosse' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Post Types', 'fosse' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $all_post_types as $pt ) : ?>
									<label>
										<input
											type="checkbox"
											name="fosse_ap_support_post_types[]"
											value="<?php echo esc_attr( $pt->name ); ?>"
											<?php checked( in_array( $pt->name, $post_types, true ) ); ?>
										/>
										<?php echo esc_html( $pt->label ); ?>
									</label><br />
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>

					<?php if ( $address ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Fediverse Address', 'fosse' ); ?></th>
							<td><code><?php echo esc_html( '@' . $address ); ?></code></td>
						</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( __( 'Save ActivityPub Settings', 'fosse' ) ); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=activitypub' ) ); ?>">
					<?php esc_html_e( 'Show advanced ActivityPub settings', 'fosse' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the AP status card on the FOSSE Status page.
	 *
	 * @return void
	 */
	public function render_status_card(): void {
		$status     = $this->get_status();
		$mode_label = $this->get_actor_mode_label( $status['actor_mode'] );
		$post_types = array_map(
			static function ( $pt_name ) {
				$pt = get_post_type_object( $pt_name );
				return $pt ? $pt->label : $pt_name;
			},
			$status['post_types']
		);
		?>
		<div class="fosse-status-card">
			<h3>
				<span class="fosse-status-indicator connected"></span>
				<?php esc_html_e( 'ActivityPub', 'fosse' ); ?>
			</h3>

			<table class="widefat striped">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Actor Mode', 'fosse' ); ?></td>
						<td><?php echo esc_html( $mode_label ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Post Types', 'fosse' ); ?></td>
						<td><?php echo esc_html( implode( ', ', $post_types ) ); ?></td>
					</tr>
					<?php if ( $status['address'] ) : ?>
						<tr>
							<td><?php esc_html_e( 'Fediverse Address', 'fosse' ); ?></td>
							<td><code><?php echo esc_html( '@' . $status['address'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php $this->render_follower_count_row(); ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Register hooks for this provider.
	 *
	 * Sets up the option projection filters and the form save handler.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Option projection: FOSSE values -> AP options at read time.
		add_filter( 'pre_option_activitypub_actor_mode', array( $this, 'project_actor_mode' ), 20 );
		add_filter( 'pre_option_activitypub_support_post_types', array( $this, 'project_post_types' ), 20 );

		// Form save handler.
		add_action( 'admin_post_fosse_save_ap_settings', array( $this, 'handle_save' ) );
	}

	/**
	 * Project fosse_ap_actor_mode to AP's option at read time.
	 *
	 * Hooked at priority 20 so AP's own constant-based overrides (priority 10)
	 * take precedence. If AP already set a value, pass it through.
	 *
	 * @param string|false $pre The pre-get option value.
	 * @return string|false
	 */
	public function project_actor_mode( $pre ) {
		if ( false !== $pre ) {
			return $pre;
		}

		$fosse_value = get_option( 'fosse_ap_actor_mode' );
		if ( false !== $fosse_value ) {
			return $fosse_value;
		}

		return $pre;
	}

	/**
	 * Project fosse_ap_support_post_types to AP's option at read time.
	 *
	 * @param array|false $pre The pre-get option value.
	 * @return array|false
	 */
	public function project_post_types( $pre ) {
		if ( false !== $pre ) {
			return $pre;
		}

		$fosse_value = get_option( 'fosse_ap_support_post_types' );
		if ( false !== $fosse_value ) {
			return $fosse_value;
		}

		return $pre;
	}

	/**
	 * Handle the AP settings form submission.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_save_ap_settings' );

		// Sanitize actor mode against allowlist.
		$mode = sanitize_text_field( wp_unslash( $_POST['fosse_ap_actor_mode'] ?? '' ) );
		if ( in_array( $mode, self::ACTOR_MODES, true ) ) {
			update_option( 'fosse_ap_actor_mode', $mode );
		}

		// Sanitize post types against registered public types.
		$submitted   = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['fosse_ap_support_post_types'] ?? array() ) ) );
		$valid_types = get_post_types( array( 'public' => true ) );
		$post_types  = array_values( array_intersect( $submitted, $valid_types ) );
		update_option( 'fosse_ap_support_post_types', $post_types );

		// Redirect back with success notice.
		add_settings_error( 'fosse', 'fosse_ap_saved', __( 'ActivityPub settings saved.', 'fosse' ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=fosse&settings-updated=true' ) );
		exit;
	}

	/**
	 * Get the fediverse address for the active actor(s).
	 *
	 * Returns the blog webfinger in blog mode, the current user's
	 * webfinger in actor mode, or the user's in actor_blog mode
	 * (falling back to blog if the user actor is unavailable).
	 *
	 * @return string Empty string if AP models are unavailable.
	 */
	private function get_fediverse_address(): string {
		$mode = get_option( 'activitypub_actor_mode', 'actor' );

		if ( 'blog' !== $mode && class_exists( '\Activitypub\Model\User' ) ) {
			$user = \Activitypub\Model\User::from_wp_user( get_current_user_id() );
			if ( $user && ! is_wp_error( $user ) ) {
				return $user->get_webfinger();
			}
		}

		if ( class_exists( '\Activitypub\Model\Blog' ) ) {
			$blog = new \Activitypub\Model\Blog();
			return $blog->get_webfinger();
		}

		return '';
	}

	/**
	 * Get a human-readable label for an actor mode value.
	 *
	 * @param string $mode The actor mode value.
	 * @return string
	 */
	private function get_actor_mode_label( string $mode ): string {
		$labels = array(
			'actor'      => __( 'Author profiles', 'fosse' ),
			'blog'       => __( 'Blog profile', 'fosse' ),
			'actor_blog' => __( 'Both', 'fosse' ),
		);

		return $labels[ $mode ] ?? $mode;
	}

	/**
	 * Render the follower count row if the AP Followers API is available.
	 *
	 * Uses the blog actor ID in blog mode, the current user in actor mode,
	 * and shows both in actor_blog mode.
	 *
	 * @return void
	 */
	private function render_follower_count_row(): void {
		if ( ! class_exists( '\Activitypub\Collection\Followers' ) ) {
			return;
		}

		$mode    = get_option( 'activitypub_actor_mode', 'actor' );
		$blog_id = defined( '\Activitypub\Collection\Actors::BLOG_USER_ID' )
			? \Activitypub\Collection\Actors::BLOG_USER_ID
			: 0;

		if ( 'blog' === $mode ) {
			$count = \Activitypub\Collection\Followers::count( $blog_id );
			?>
			<tr>
				<td><?php esc_html_e( 'Followers', 'fosse' ); ?></td>
				<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
			</tr>
			<?php
		} elseif ( 'actor_blog' === $mode ) {
			$user_count = \Activitypub\Collection\Followers::count( get_current_user_id() );
			$blog_count = \Activitypub\Collection\Followers::count( $blog_id );
			?>
			<tr>
				<td><?php esc_html_e( 'Followers', 'fosse' ); ?></td>
				<td>
					<?php
					printf(
						/* translators: 1: author follower count, 2: blog follower count */
						esc_html__( 'Author: %1$s, Blog: %2$s', 'fosse' ),
						esc_html( number_format_i18n( $user_count ) ),
						esc_html( number_format_i18n( $blog_count ) )
					);
					?>
				</td>
			</tr>
			<?php
		} else {
			$count = \Activitypub\Collection\Followers::count( get_current_user_id() );
			?>
			<tr>
				<td><?php esc_html_e( 'Followers', 'fosse' ); ?></td>
				<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
			</tr>
			<?php
		}
	}
}
