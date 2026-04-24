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
 */
class Provider_Loader {

	/**
	 * Fire provider registration and call register_hooks() on each.
	 *
	 * @return void
	 */
	public static function boot(): void {
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
	}
}
