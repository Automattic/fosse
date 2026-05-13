<?php
/**
 * Tests for Provider_Loader.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Admin\AP_Provider;
use Automattic\Fosse\Admin\Connection_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use Automattic\Fosse\Provider_Loader;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies that Provider_Loader boots providers and attaches hooks
 * unconditionally — not gated behind is_admin().
 */
class Provider_LoaderTest extends BaseTestCase {

	/**
	 * Clean state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		Connection_Provider_Registry::reset();
		Provider_Loader::reset();
		delete_option( 'activitypub_actor_mode' );
	}

	/**
	 * Clean up after each test.
	 *
	 * @after
	 */
	#[After]
	public function clean_up(): void {
		remove_all_filters( 'fosse_register_providers' );
		Connection_Provider_Registry::reset();
		Provider_Loader::reset();
	}

	/**
	 * Providers are registered in the registry after boot().
	 */
	public function test_providers_registered_after_boot() {
		AP_Provider::init();
		Provider_Loader::boot();

		$this->assertNotNull( Connection_Provider_Registry::get_provider( 'activitypub' ) );
	}

	/**
	 * A provider registered from a third-party callback on
	 * `fosse_register_providers` is available after `boot()` — the
	 * documented extension path for standalone provider plugins.
	 */
	public function test_external_provider_registered_via_hook_is_available_after_boot() {
		$external = $this->make_counting_provider( 'external' );

		add_action(
			'fosse_register_providers',
			static function () use ( $external ) {
				Connection_Provider_Registry::register( $external );
			}
		);

		Provider_Loader::boot();

		$this->assertSame( $external, Connection_Provider_Registry::get_provider( 'external' ) );
	}

	/**
	 * The production bootstrap is wired to `plugins_loaded` priority 20
	 * via the global `fosse_boot_providers()` callback. Asserts the
	 * binding itself so a future refactor that drops the wrapper —
	 * leaving the registry never booted in production — fails a test.
	 */
	public function test_boot_is_wired_to_plugins_loaded_priority_20() {
		$this->assertSame( 20, has_action( 'plugins_loaded', 'fosse_boot_providers' ) );
	}

	/**
	 * Calling `fosse_boot_providers()` directly drives the same code path
	 * the `plugins_loaded` callback runs in production: AP and Bluesky
	 * providers self-register, then `Provider_Loader::boot()` fires the
	 * action and attaches their hooks. Asserts the bundled providers land
	 * in the registry through that path — not by manually invoking each
	 * provider's `init()`.
	 */
	public function test_production_callback_registers_bundled_providers() {
		fosse_boot_providers();

		$this->assertNotNull( Connection_Provider_Registry::get_provider( 'activitypub' ) );
		$this->assertNotNull( Connection_Provider_Registry::get_provider( 'bluesky' ) );
	}

	/**
	 * A provider whose `is_available()` returns false stays out of the
	 * `register_hooks()` loop, matching the documented contract that
	 * standalone provider plugins should return false when their SDK
	 * isn't loaded so FOSSE skips hook registration cleanly.
	 */
	public function test_unavailable_provider_does_not_register_hooks() {
		$unavailable                       = $this->make_counting_provider( 'unavailable' );
		$unavailable->is_available_returns = false;

		add_action(
			'fosse_register_providers',
			static function () use ( $unavailable ) {
				Connection_Provider_Registry::register( $unavailable );
			}
		);

		Provider_Loader::boot();

		$this->assertSame( 0, $unavailable->register_hooks_calls, 'register_hooks() should not run when is_available() returns false.' );
		$this->assertSame( $unavailable, Connection_Provider_Registry::get_provider( 'unavailable' ), 'Provider should still appear in the registry — `is_available()` only gates hook registration.' );
	}

	/**
	 * Calling `boot()` twice in the same request fires the registration
	 * action and `register_hooks()` exactly once, so defensive callers
	 * (e.g. an add-on that boots its own copy on `plugins_loaded`) cannot
	 * cause double-registered hooks.
	 */
	public function test_boot_is_idempotent() {
		$external          = $this->make_counting_provider( 'idempotent' );
		$registration_runs = 0;

		add_action(
			'fosse_register_providers',
			static function () use ( $external, &$registration_runs ) {
				++$registration_runs;
				Connection_Provider_Registry::register( $external );
			}
		);

		Provider_Loader::boot();
		Provider_Loader::boot();

		$this->assertSame( 1, $external->register_hooks_calls, 'register_hooks() should run exactly once across two boot() calls.' );
		$this->assertSame( 1, $registration_runs, '`fosse_register_providers` callbacks should run exactly once across two boot() calls.' );
	}

	/**
	 * Build an anonymous Connection_Provider that counts how many times
	 * `register_hooks()` has been called. Lets idempotency assertions
	 * read off `->register_hooks_calls` without poking at WP hook state.
	 *
	 * @param string $slug Provider slug.
	 * @return Connection_Provider
	 */
	private function make_counting_provider( string $slug ): Connection_Provider {
		// phpcs:disable Squiz.Commenting, Generic.Commenting.DocComment.MissingShort
		return new class( $slug ) implements Connection_Provider {
			/**
			 * Provider slug.
			 *
			 * @var string
			 */
			private string $slug;

			/**
			 * Number of times register_hooks() has been called.
			 *
			 * @var int
			 */
			public int $register_hooks_calls = 0;

			/**
			 * Value returned by is_available(); set to false to exercise
			 * the loader's "skip register_hooks" branch.
			 *
			 * @var bool
			 */
			public bool $is_available_returns = true;

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
				return $this->is_available_returns;
			}
			public function get_status(): array {
				return array( 'connected' => true );
			}
			public function render_setup_section(): void {}
			public function render_connection_actions(): void {}
			public function render_status_card(): void {}
			public function register_hooks(): void {
				++$this->register_hooks_calls;
			}
			public function save_settings( array $post_data ): bool {
				unset( $post_data );
				return true;
			}
		};
		// phpcs:enable Squiz.Commenting
	}
}
