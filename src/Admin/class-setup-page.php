<?php
/**
 * FOSSE Settings page.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Renders the FOSSE Settings page and handles the unified save.
 *
 * Owns the General (cross-protocol) section directly and delegates
 * protocol-specific fields to each provider's `render_setup_section()`.
 * A single submit button posts to {@see self::handle_save()}, which
 * applies the General writes and then asks each provider to persist
 * its own protocol-specific settings.
 */
class Setup_Page {

	/**
	 * Admin-post action and nonce action for the unified save.
	 *
	 * @var string
	 */
	public const SAVE_ACTION = 'fosse_save_settings';

	/**
	 * Register the unified save handler.
	 *
	 * Called by {@see Menu::register()} on every admin request so the
	 * `admin_post_*` hook is wired before the form submission lands.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( static::class, 'handle_save' ) );
	}

	/**
	 * Render the Settings page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- consumed by templates/setup-page.php.
		$providers         = Connection_Provider_Registry::get_providers();
		$wizard_incomplete = ! Onboarding_Wizard::is_complete();
		$ap_provider       = $providers['activitypub'] ?? null;
		$ap_available      = $ap_provider instanceof Connection_Provider && $ap_provider->is_available();
		$post_types        = (array) get_option( 'activitypub_support_post_types', array( 'post' ) );
		$actor_mode        = $ap_available ? (string) get_option( 'activitypub_actor_mode', 'actor' ) : 'actor';
		$all_post_types    = get_post_types( array( 'public' => true ), 'objects' );
		$save_nonce        = wp_create_nonce( self::SAVE_ACTION );
		// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		include __DIR__ . '/templates/setup-page.php';
	}

	/**
	 * Handle the unified Settings save.
	 *
	 * Verifies the nonce and capability, then delegates per-protocol
	 * persistence to each provider via {@see Connection_Provider::save_settings()}.
	 * Suppresses the blanket success notice when any provider rejected
	 * its input — providers add their own explanatory error notices in
	 * that case so the redirected page surfaces what didn't save.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer.
		$post_data = wp_unslash( $_POST );

		$ok = true;

		foreach ( Connection_Provider_Registry::get_providers() as $provider ) {
			if ( ! $provider->is_available() ) {
				continue;
			}
			if ( ! $provider->save_settings( $post_data ) ) {
				$ok = false;
			}
		}

		// Pair the success notice with a clean save: if any provider
		// rejected an input it has already added an explanatory error
		// notice, and a green "saved" banner would mislead the user about
		// what actually persisted.
		if ( $ok ) {
			add_settings_error( 'fosse', 'fosse_saved', __( 'Settings saved.', 'fosse' ), 'success' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=fosse&settings-updated=true' ) );
		exit;
	}
}
