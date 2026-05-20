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
 * Standalone provider plugins register through the
 * `fosse_register_providers` action — see
 * {@see \Automattic\Fosse\Admin\Connection_Provider} for the canonical
 * recipe and timing contract.
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
	 * by booting its own copy of the loader. The `$booted` flag is only
	 * flipped on successful completion via try/finally — a Throwable in
	 * any provider's `register_hooks()` leaves the flag clear so a
	 * recovery path can retry once the cause is fixed.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		$succeeded = false;

		try {
			/**
			 * Fires so Connection_Provider implementations can register themselves.
			 *
			 * Providers call Connection_Provider_Registry::register( $this ) here.
			 * See {@see \Automattic\Fosse\Admin\Connection_Provider} for the
			 * standalone-provider recipe.
			 */
			do_action( 'fosse_register_providers' );

			foreach ( Connection_Provider_Registry::get_providers() as $provider ) {
				if ( $provider->is_available() ) {
					$provider->register_hooks();
				}
			}

			$succeeded = true;
		} finally {
			if ( $succeeded ) {
				self::$booted = true;
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
