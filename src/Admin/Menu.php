<?php
/**
 * FOSSE admin menu registration.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Registers the top-level FOSSE menu and hides bundled-plugin admin entries.
 */
class Menu {

	/**
	 * Boot the admin UI.
	 *
	 * Fires 'fosse_register_providers' so providers can self-register,
	 * calls register_hooks() on each available provider, then hooks the
	 * menu registration and bundled-menu suppression into admin_menu.
	 *
	 * @return void
	 */
	public static function register(): void {
		/**
		 * Fires so Connection_Provider implementations can register themselves.
		 *
		 * Providers call Connection_Provider_Registry::register( $this ) here.
		 */
		do_action( 'fosse_register_providers' );

		foreach ( Connection_Provider_Registry::get_providers() as $provider ) {
			if ( $provider->is_available() ) {
				$provider->register_hooks();
			}
		}

		add_action( 'admin_menu', array( static::class, 'add_menu' ), 9 );
		add_action( 'admin_menu', array( static::class, 'hide_bundled_menus' ), 99 );
		add_action( 'admin_enqueue_scripts', array( static::class, 'enqueue_styles' ) );
	}

	/**
	 * Register the top-level FOSSE menu and sub-pages.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( 'FOSSE', 'fosse' ),
			__( 'FOSSE', 'fosse' ),
			'manage_options',
			'fosse',
			array( Setup_Page::class, 'render' ),
			'dashicons-share',
			3
		);

		add_submenu_page(
			'fosse',
			__( 'Setup', 'fosse' ),
			__( 'Setup', 'fosse' ),
			'manage_options',
			'fosse',
			array( Setup_Page::class, 'render' )
		);

		add_submenu_page(
			'fosse',
			__( 'Status', 'fosse' ),
			__( 'Status', 'fosse' ),
			'manage_options',
			'fosse-status',
			array( Status_Page::class, 'render' )
		);
	}

	/**
	 * Hide all bundled-plugin admin entries.
	 *
	 * Pages remain registered so direct-URL access still works for power users.
	 *
	 * @return void
	 */
	public static function hide_bundled_menus(): void {
		// AP Settings submenu.
		remove_submenu_page( 'options-general.php', 'activitypub' );

		// Atmosphere Settings submenu.
		remove_submenu_page( 'options-general.php', 'atmosphere' );

		// AP top-level Dashboard page (gated by activitypub_reader_ui).
		remove_menu_page( 'activitypub-social-web' );

		// AP Users submenus.
		remove_submenu_page( 'users.php', 'activitypub-followers-list' );
		remove_submenu_page( 'users.php', 'activitypub-following-list' );
		remove_submenu_page( 'users.php', 'activitypub-blocked-actors-list' );
		remove_submenu_page( 'users.php', esc_url( admin_url( '/edit.php?post_type=ap_extrafield' ) ) );
	}

	/**
	 * Enqueue admin styles on FOSSE pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_styles( string $hook_suffix ): void {
		if ( ! str_starts_with( $hook_suffix, 'toplevel_page_fosse' ) && ! str_starts_with( $hook_suffix, 'fosse_page_' ) ) {
			return;
		}

		wp_enqueue_style(
			'fosse-admin',
			plugins_url( 'src/Admin/assets/css/admin.css', dirname( __DIR__, 2 ) . '/fosse.php' ),
			array(),
			filemtime( __DIR__ . '/assets/css/admin.css' )
		);
	}
}
