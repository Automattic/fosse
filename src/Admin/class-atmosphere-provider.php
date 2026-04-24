<?php
/**
 * Atmosphere (Bluesky) connection provider — stopgap.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Minimal Atmosphere provider that links users to the bundled settings
 * screen until the full Bluesky provider (plan.md Task 5) lands.
 *
 * Self-registers on 'fosse_register_providers'. Reports a "Not yet configured"
 * status and surfaces the Atmosphere settings page so the FOSSE menu doesn't
 * lose the Bluesky path while the bundled settings submenu is suppressed.
 */
class Atmosphere_Provider implements Connection_Provider {

	/**
	 * Hook into fosse_register_providers to self-register.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'fosse_register_providers', array( static::class, 'register_provider' ) );
	}

	/**
	 * Register this provider if the Atmosphere plugin is available.
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
		return 'atmosphere';
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
	 * Check if the Atmosphere plugin is loaded.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( '\Atmosphere\Atmosphere' );
	}

	/**
	 * Get current Atmosphere connection status.
	 *
	 * Stopgap: reports disconnected pending the full Bluesky provider.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		return array(
			'connected' => false,
			'message'   => __( 'Not yet configured', 'fosse' ),
		);
	}

	/**
	 * Render the Atmosphere setup section on the FOSSE Setup page.
	 *
	 * @return void
	 */
	public function render_setup_section(): void {
		$settings_url = admin_url( 'options-general.php?page=atmosphere' );
		?>
		<div class="fosse-provider-section">
			<h2><?php esc_html_e( 'Bluesky', 'fosse' ); ?></h2>
			<p>
				<?php esc_html_e( 'Connect FOSSE to the Bluesky network via the bundled Atmosphere integration.', 'fosse' ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Configure Bluesky settings', 'fosse' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Atmosphere status card on the FOSSE Status page.
	 *
	 * @return void
	 */
	public function render_status_card(): void {
		$status       = $this->get_status();
		$settings_url = admin_url( 'options-general.php?page=atmosphere' );
		?>
		<div class="fosse-status-card">
			<h3>
				<span class="fosse-status-indicator <?php echo $status['connected'] ? 'connected' : 'disconnected'; ?>"></span>
				<?php esc_html_e( 'Bluesky', 'fosse' ); ?>
			</h3>
			<p><?php echo esc_html( $status['message'] ); ?></p>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Configure Bluesky settings', 'fosse' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Register hooks for this provider.
	 *
	 * Stopgap has no hooks; the full Bluesky_Provider will wire up OAuth
	 * and form handlers here.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Intentionally empty — stopgap has no hooks to register.
	}
}
