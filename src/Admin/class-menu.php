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
		add_action( 'admin_enqueue_scripts', array( static::class, 'enqueue_assets' ) );
		add_action( 'admin_init', array( static::class, 'maybe_redirect_to_wizard' ) );

		Setup_Page::register_hooks();
		// Suppress at two stages so plugins can't bypass us by registering
		// notices in hooks that fire between current_screen and the notice
		// hooks themselves. current_screen strips the typical case where
		// notices are registered at admin_init or plugin load. in_admin_header
		// fires immediately before the four notice hooks, catching anything
		// re-added by admin_head, admin_enqueue_scripts, admin_print_scripts,
		// or admin_print_styles handlers. PHP_INT_MAX on both so we run after
		// every same-hook callback a plugin could register at normal priority.
		add_action( 'current_screen', array( static::class, 'maybe_suppress_admin_notices' ), PHP_INT_MAX );
		add_action( 'in_admin_header', array( static::class, 'maybe_suppress_admin_notices_late' ), PHP_INT_MAX );

		Onboarding_Wizard::register();
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
			__( 'Settings', 'fosse' ),
			__( 'Settings', 'fosse' ),
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

		// Wizard page: empty parent slug keeps it out of the sidebar while
		// preserving a real admin URL. (PHP 8.2 deprecates passing null
		// through plugin_basename() inside add_submenu_page(), so we keep
		// the empty-string form rather than the documented null idiom.)
		$wizard_hook = add_submenu_page(
			'',
			__( 'Setup Wizard', 'fosse' ),
			__( 'Setup Wizard', 'fosse' ),
			'manage_options',
			'fosse-wizard',
			array( Onboarding_Wizard::class, 'render' )
		);

		if ( false !== $wizard_hook ) {
			add_action( "load-{$wizard_hook}", array( static::class, 'set_wizard_admin_title' ) );
		}
	}

	/**
	 * Provide a title for the hidden wizard screen before admin-header.php runs.
	 *
	 * WordPress core's admin header calls `strip_tags( $title )`. Hidden
	 * submenu pages registered under an empty parent can leave `$title` null
	 * on newer Playground builds, which emits a PHP 8.3 deprecation before
	 * headers are sent. Setting the core global on the screen's `load-*` hook
	 * keeps the page title intact and prevents the follow-on header warnings.
	 *
	 * @return void
	 */
	public static function set_wizard_admin_title(): void {
		global $title;

		if ( ! is_string( $title ) || '' === $title ) {
			$title = __( 'Setup Wizard', 'fosse' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core expects this global before admin-header.php renders.
		}
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
	 * Redirect to the onboarding wizard on first activation.
	 *
	 * Fires once for the first qualifying admin request after activation.
	 * On capability/context guard returns (non-admin user, AJAX/cron/CLI)
	 * the option is preserved so a later real admin request can still
	 * consume it. On positive "do not redirect" branches (already
	 * complete, bulk activation), the option is deleted to prevent a
	 * stale redirect.
	 *
	 * @return void
	 */
	public static function maybe_redirect_to_wizard(): void {
		// Migrate any leftover legacy transient from a pre-option install
		// onto the new option-backed signal, then drop the transient so
		// the rest of the function only consults the option.
		if ( get_transient( Onboarding_Wizard::REDIRECT_TRANSIENT ) ) {
			update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
			// Return value unchecked: we only care that it's gone after
			// this call, and we already have the option-backed signal.
			delete_transient( Onboarding_Wizard::REDIRECT_TRANSIENT );
		}

		if ( ! get_option( Onboarding_Wizard::REDIRECT_OPTION ) ) {
			return;
		}

		// Gate on capability and request context before consuming the
		// option. The signal is global, so a lower-privileged user or
		// non-admin request could otherwise consume it and prevent the
		// intended redirect for the actual site administrator.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Don't redirect during bulk activation, AJAX, or CLI.
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Don't consume a form POST. admin-post.php fires admin_init before
		// dispatching its admin_post_* actions, so a POST that lands here
		// (e.g. if the option survived to a later request) would be swallowed
		// by the redirect+exit below, silently dropping the submitted data.
		// Preserve the option so a later GET admin request can still redirect.
		if ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			return;
		}

		// Don't redirect if the wizard was already completed.
		if ( Onboarding_Wizard::is_complete() ) {
			// Return value unchecked: any of "deleted", "wasn't there",
			// or "stored value was identical" leaves the option absent,
			// which is exactly what we want.
			delete_option( Onboarding_Wizard::REDIRECT_OPTION );
			return;
		}

		// Don't redirect if activating multiple plugins at once. Consume
		// the option anyway so a follow-up admin request can't redirect
		// unexpectedly.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check.
		if ( isset( $_GET['activate-multi'] ) ) {
			// Return value unchecked: same reasoning as above.
			delete_option( Onboarding_Wizard::REDIRECT_OPTION );
			return;
		}

		// Don't redirect when ActivityPub isn't available — the wizard
		// would just render its degraded notice. Preserve the option so
		// a later admin request (after the user installs/activates AP)
		// still triggers the redirect.
		if ( ! Onboarding_Wizard::is_activitypub_available() ) {
			return;
		}

		// All guards passed — consume the option and redirect.
		// Return value unchecked: same reasoning as above.
		delete_option( Onboarding_Wizard::REDIRECT_OPTION );

		wp_safe_redirect( admin_url( 'admin.php?page=fosse-wizard' ) );
		exit;
	}

	/**
	 * Enqueue admin styles and scripts on FOSSE pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		// Strict whitelist of FOSSE's own admin hook suffixes. A prefix
		// match (e.g. str_starts_with( $hook_suffix, 'toplevel_page_fosse' ))
		// would also match a third-party plugin whose top-level slug starts
		// with "fosse" (e.g. 'toplevel_page_fosse-companion'), loading
		// FOSSE's admin CSS onto a foreign screen. These hook suffixes mirror
		// the screen IDs whitelisted in {@see self::is_fosse_admin_screen()}.
		$fosse_hook_suffixes = array(
			'toplevel_page_fosse',
			'fosse_page_fosse-status',
			'admin_page_fosse-wizard',
		);

		if ( ! in_array( $hook_suffix, $fosse_hook_suffixes, true ) ) {
			return;
		}

		wp_enqueue_style(
			'fosse-admin',
			plugins_url( 'src/Admin/assets/css/admin.css', dirname( __DIR__, 2 ) . '/fosse.php' ),
			array(),
			filemtime( __DIR__ . '/assets/css/admin.css' )
		);

		// Wizard-only scripts: the Appearance step live preview and the
		// hidden style toggle. Loaded only on the wizard
		// hook to avoid shipping scripts on Setup/Status pages where their
		// target markup is not rendered.
		if ( 'admin_page_fosse-wizard' === $hook_suffix ) {
			wp_enqueue_script(
				'fosse-wizard-appearance',
				plugins_url( 'src/Admin/assets/js/wizard-appearance.js', dirname( __DIR__, 2 ) . '/fosse.php' ),
				array(),
				filemtime( __DIR__ . '/assets/js/wizard-appearance.js' ),
				array( 'in_footer' => true )
			);

			// Runs synchronously in the head so a stored lizard theme can mark
			// the root element before the wizard body paints. Do not add a
			// delayed strategy here; defer/async would reintroduce the flash.
			wp_enqueue_script(
				'fosse-wizard-lizard',
				plugins_url( 'src/Admin/assets/js/wizard-lizard.js', dirname( __DIR__, 2 ) . '/fosse.php' ),
				array(),
				filemtime( __DIR__ . '/assets/js/wizard-lizard.js' ),
				array( 'in_footer' => false )
			);
		}
	}

	/**
	 * Suppress foreign admin notices on the FOSSE Setup Wizard screen.
	 *
	 * The wizard is a focused first-run flow; third-party notices (host
	 * banners, plugin upsells, "rate this plugin" prompts) inject
	 * themselves into the wizard surface and break the orientation.
	 * Strip the four core notice hooks on that screen only.
	 *
	 * The Settings page is NOT suppressed: long-lived admin pages benefit
	 * from FOSSE's own `admin_notices`-hooked banners (e.g. the recovery
	 * notice rendered by
	 * {@see Bluesky_Provider::maybe_render_auto_publish_disabled_notice()})
	 * and from legitimate cross-plugin notices that the user expects to
	 * see in their normal admin flow. The Status page was already exempt
	 * for the same reason.
	 *
	 * FOSSE's own template-driven messaging is unaffected either way:
	 * `settings_errors()` is rendered by direct calls in our templates,
	 * not via the `admin_notices` hook.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 * @return void
	 */
	public static function maybe_suppress_admin_notices( \WP_Screen $screen ): void {
		if ( ! self::is_fosse_wizard_screen( $screen ) ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Late-stage notice suppression bound to `in_admin_header`.
	 *
	 * Catches notice callbacks that other plugins added between
	 * `current_screen` and the notice hooks themselves — typically via
	 * `admin_head`, `admin_print_scripts`, or `admin_enqueue_scripts`
	 * handlers. Defers to {@see self::maybe_suppress_admin_notices()} once
	 * the current screen is resolved via `get_current_screen()`, since
	 * `in_admin_header` doesn't pass the screen object as an argument.
	 *
	 * No-op when no screen is set (defensive — `in_admin_header` only
	 * fires inside the admin header where the screen should be initialized).
	 *
	 * @return void
	 */
	public static function maybe_suppress_admin_notices_late(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}

		self::maybe_suppress_admin_notices( $screen );
	}

	/**
	 * Whether the given screen is the FOSSE Setup Wizard.
	 *
	 * Centralized so the suppression list and any future wizard-only
	 * callers (e.g. conditional asset enqueues) stay in sync. Settings
	 * and Status pages are excluded by design — see
	 * {@see self::maybe_suppress_admin_notices()}.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 * @return bool
	 */
	private static function is_fosse_wizard_screen( \WP_Screen $screen ): bool {
		return 'admin_page_fosse-wizard' === $screen->id;
	}

	/**
	 * Whether the given screen is any FOSSE-owned admin screen.
	 *
	 * Public so providers and other admin-side code can scope notices,
	 * banners, or asset enqueues to FOSSE pages without each caller
	 * re-implementing screen-id matching. Strict whitelist by design — a
	 * substring check (e.g. `strpos( $id, 'fosse' )`) would accidentally
	 * match third-party plugins whose admin pages happen to contain
	 * "fosse" in their slug.
	 *
	 * Covers Settings (`toplevel_page_fosse`), Status
	 * (`fosse_page_fosse-status`), and the hidden Setup Wizard
	 * (`admin_page_fosse-wizard`). Add new FOSSE screen IDs here when
	 * registering new admin pages in {@see self::add_menu()}.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 * @return bool
	 */
	public static function is_fosse_admin_screen( \WP_Screen $screen ): bool {
		return in_array(
			$screen->id,
			array(
				'toplevel_page_fosse',
				'fosse_page_fosse-status',
				'admin_page_fosse-wizard',
			),
			true
		);
	}
}
