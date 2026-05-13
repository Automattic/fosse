<?php
/**
 * Provider bootstrap.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

use Automattic\Fosse\Admin\Connection_Provider_Registry;

/**
 * Discovers and boots Connection_Provider implementations.
 *
 * Runs unconditionally (admin and front-end) so that provider hooks
 * like option-projection filters are active on every request.
 * Admin-only concerns (menus, pages, CSS) live in Admin\Menu.
 *
 * ### Registering a standalone provider
 *
 * Third-party plugins that add a new federation provider implement
 * {@see \Automattic\Fosse\Admin\Connection_Provider} and hook
 * `fosse_register_providers` to push their instance onto the registry:
 *
 * ```php
 * add_action( 'fosse_register_providers', static function () {
 *     \Automattic\Fosse\Admin\Connection_Provider_Registry::register(
 *         new My_Plugin\My_Provider()
 *     );
 * } );
 * ```
 *
 * `Provider_Loader::boot()` fires `fosse_register_providers` on
 * `plugins_loaded` priority 10 (registered in `fosse.php`). The add-on's
 * callback must be attached no later than `plugins_loaded` priority 9 —
 * registering it from the plugin's main file is the simplest path.
 *
 * Providers whose underlying SDK isn't loaded should return `false` from
 * `is_available()` so `register_hooks()` is skipped cleanly.
 */
class Provider_Loader {

	/**
	 * Whether {@see self::boot()} has already run in this request.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Fire provider registration and call register_hooks() on each.
	 *
	 * Safe to invoke more than once in a request: subsequent calls are
	 * no-ops, so a defensively-coded add-on cannot double-register hooks
	 * by booting its own copy of the loader.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		/**
		 * Fires so Connection_Provider implementations can register themselves.
		 *
		 * Providers call Connection_Provider_Registry::register( $this ) here.
		 * See this class's docblock for the standalone-provider recipe.
		 */
		do_action( 'fosse_register_providers' );

		foreach ( Connection_Provider_Registry::get_providers() as $provider ) {
			if ( $provider->is_available() ) {
				$provider->register_hooks();
			}
		}
	}

	/**
	 * Clear the per-request boot state so {@see self::boot()} can run
	 * again. Intended for test fixtures only; production callers should
	 * rely on the idempotent boot guard instead.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$booted = false;
	}
}
