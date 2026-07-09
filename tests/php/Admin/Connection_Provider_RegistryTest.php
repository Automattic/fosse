<?php
/**
 * Tests for Connection_Provider_Registry.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Connection_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the static provider registry: register, retrieve, duplicate handling, reset.
 */
class Connection_Provider_RegistryTest extends BaseTestCase {

	/**
	 * Clear the registry before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_registry(): void {
		Connection_Provider_Registry::reset();
	}

	/**
	 * A registered provider can be retrieved by slug.
	 */
	public function test_register_and_retrieve() {
		$provider = $this->make_provider( 'test' );

		Connection_Provider_Registry::register( $provider );

		$this->assertSame( $provider, Connection_Provider_Registry::get_provider( 'test' ) );
	}

	/**
	 * Get_providers returns all registered providers keyed by slug.
	 */
	public function test_get_providers_returns_all() {
		$a = $this->make_provider( 'alpha' );
		$b = $this->make_provider( 'beta' );

		Connection_Provider_Registry::register( $a );
		Connection_Provider_Registry::register( $b );

		$providers = Connection_Provider_Registry::get_providers();

		$this->assertCount( 2, $providers );
		$this->assertSame( $a, $providers['alpha'] );
		$this->assertSame( $b, $providers['beta'] );
	}

	/**
	 * Registering a duplicate slug is rejected; first registration wins.
	 *
	 * Suppresses the `_doing_it_wrong()` warning via the
	 * `doing_it_wrong_trigger_error` filter so PHPUnit's `failOnWarning`
	 * doesn't trip — the firing of the warning itself is asserted
	 * separately in {@see self::test_duplicate_slug_triggers_doing_it_wrong()}.
	 */
	public function test_duplicate_slug_is_ignored() {
		add_filter( 'doing_it_wrong_trigger_error', '__return_false' );

		try {
			$first  = $this->make_provider( 'dupe' );
			$second = $this->make_provider( 'dupe' );

			Connection_Provider_Registry::register( $first );
			Connection_Provider_Registry::register( $second );

			$this->assertSame( $first, Connection_Provider_Registry::get_provider( 'dupe' ) );
			$this->assertCount( 1, Connection_Provider_Registry::get_providers() );
		} finally {
			remove_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		}
	}

	/**
	 * Registering a duplicate slug fires `_doing_it_wrong` so callers can
	 * see the conflict instead of debugging a silent no-op.
	 *
	 * Hooks `doing_it_wrong_run` to capture the call and filters
	 * `doing_it_wrong_trigger_error` to false so PHPUnit's failOnWarning
	 * doesn't trip on the E_USER_NOTICE — WorDBless's BaseTestCase
	 * extends Yoast's polyfill TestCase, neither of which exposes
	 * `setExpectedIncorrectUsage()`.
	 */
	public function test_duplicate_slug_triggers_doing_it_wrong() {
		$captured = array();
		$listener = static function ( $function_name, $message ) use ( &$captured ) {
			$captured[] = array(
				'function' => $function_name,
				'message'  => $message,
			);
		};

		add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		add_action( 'doing_it_wrong_run', $listener, 10, 2 );

		try {
			Connection_Provider_Registry::register( $this->make_provider( 'dupe' ) );
			Connection_Provider_Registry::register( $this->make_provider( 'dupe' ) );
		} finally {
			remove_action( 'doing_it_wrong_run', $listener, 10 );
			remove_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		}

		$this->assertCount( 1, $captured );
		$this->assertStringContainsString( 'Connection_Provider_Registry::register', $captured[0]['function'] );
		$this->assertStringContainsString( 'dupe', $captured[0]['message'] );
	}

	/**
	 * Getting an unregistered slug returns null.
	 */
	public function test_get_unknown_returns_null() {
		$this->assertNull( Connection_Provider_Registry::get_provider( 'nonexistent' ) );
	}

	/**
	 * Reset clears all registered providers.
	 */
	public function test_reset_clears() {
		Connection_Provider_Registry::register( $this->make_provider( 'temp' ) );

		$this->assertCount( 1, Connection_Provider_Registry::get_providers() );

		Connection_Provider_Registry::reset();

		$this->assertEmpty( Connection_Provider_Registry::get_providers() );
	}

	/**
	 * Create a stub provider with the given slug.
	 *
	 * @param string $slug Provider slug.
	 * @return Connection_Provider
	 */
	private function make_provider( string $slug ): Connection_Provider {
		// phpcs:disable Squiz.Commenting, Generic.Commenting.DocComment.MissingShort
		return new class( $slug ) implements Connection_Provider {
			/**
			 * Provider slug.
			 *
			 * @var string
			 */
			private string $slug;
			public function __construct( string $slug ) {
				$this->slug = $slug;
			}
			public function get_slug(): string {
				return $this->slug;
			}
			public function get_name(): string {
				return ucfirst( $this->slug );
			}
			public function is_available(): bool {
				return true;
			}
			public function get_status(): array {
				return array( 'connected' => true );
			}
			public function is_connected(): bool {
				return true;
			}
			public function render_setup_section(): void {}
			public function render_connection_actions(): void {}
			public function render_status_card(): void {}
			public function register_hooks(): void {}
			public function save_settings( array $post_data ): bool {
				unset( $post_data );
				return true;
			}
		};
		// phpcs:enable Squiz.Commenting
	}
}
