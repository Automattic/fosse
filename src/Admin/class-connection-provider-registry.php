<?php
/**
 * Connection provider registry.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Static registry for Connection_Provider instances.
 *
 * Providers register themselves during the 'fosse_register_providers' action.
 * Setup_Page and Status_Page iterate this registry to render provider sections.
 */
class Connection_Provider_Registry {

	/**
	 * Registered providers keyed by slug.
	 *
	 * @var array<string, Connection_Provider>
	 */
	private static array $providers = array();

	/**
	 * Register a provider. Duplicate slugs are rejected — first registration
	 * wins — and surface a `_doing_it_wrong()` so the caller knows the
	 * duplicate didn't take effect instead of debugging a phantom no-op.
	 *
	 * @param Connection_Provider $provider Provider instance.
	 * @return void
	 */
	public static function register( Connection_Provider $provider ): void {
		$slug = $provider->get_slug();

		if ( isset( self::$providers[ $slug ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf( 'Connection provider "%s" is already registered.', esc_html( $slug ) ),
				'0.1.2'
			);
			return;
		}

		self::$providers[ $slug ] = $provider;
	}

	/**
	 * Get all registered providers.
	 *
	 * @return array<string, Connection_Provider>
	 */
	public static function get_providers(): array {
		return self::$providers;
	}

	/**
	 * Get a single provider by slug.
	 *
	 * @param string $slug Provider slug.
	 * @return Connection_Provider|null
	 */
	public static function get_provider( string $slug ): ?Connection_Provider {
		return self::$providers[ $slug ] ?? null;
	}

	/**
	 * Clear all registered providers. Primarily for testing.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$providers = array();
	}
}
