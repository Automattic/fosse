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
	 * Register admin menu pages, bundled-menu suppression, and CSS.
	 *
	 * Provider discovery and hook registration happen in Provider_Loader::boot(),
	 * which runs unconditionally. This method handles admin-only concerns.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( static::class, 'add_menu' ), 9 );
		add_action( 'admin_menu', array( static::class, 'hide_bundled_menus' ), 99 );
		add_action( 'admin_bar_menu', array( static::class, 'hide_bundled_admin_bar' ), 101 );
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

		// AP Dashboard submenu (gated by activitypub_reader_ui). AP registers
		// it via add_dashboard_page(), which is add_submenu_page( 'index.php', ... ),
		// so remove_menu_page() — which only scans the top-level $menu global —
		// does not remove it.
		remove_submenu_page( 'index.php', 'activitypub-social-web' );

		// AP Users submenus.
		remove_submenu_page( 'users.php', 'activitypub-followers-list' );
		remove_submenu_page( 'users.php', 'activitypub-following-list' );
		remove_submenu_page( 'users.php', 'activitypub-blocked-actors-list' );
		// Mirrors bundled AP registration at includes/wp-admin/class-menu.php:94-98.
		// Fragile: matches the rendered URL string AP uses as the submenu slug.
		remove_submenu_page( 'users.php', esc_url( admin_url( '/edit.php?post_type=ap_extrafield' ) ) );
	}

	/**
	 * Hide bundled-plugin admin-bar nodes.
	 *
	 * AP registers its 'activitypub-social-web' admin-bar node at priority
	 * 100 (bundled/activitypub/includes/wp-admin/class-menu.php:22), so the
	 * remove_node call must run after — priority 101.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 * @return void
	 */
	public static function hide_bundled_admin_bar( \WP_Admin_Bar $wp_admin_bar ): void {
		$wp_admin_bar->remove_node( 'activitypub-social-web' );
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
