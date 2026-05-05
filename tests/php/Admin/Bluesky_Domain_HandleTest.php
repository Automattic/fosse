<?php
/**
 * Tests for Bluesky_Domain_Handle.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Atmosphere\OAuth\Encryption;
use Automattic\Fosse\Admin\Bluesky_Domain_Handle;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use WorDBless\BaseTestCase;

/**
 * Verifies the FOSSE Bluesky domain-handle service.
 */
class Bluesky_Domain_HandleTest extends BaseTestCase {

	/**
	 * Reset state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'fosse-test-auth-key' );
		}

		if ( ! defined( 'AUTH_SALT' ) ) {
			define( 'AUTH_SALT', 'fosse-test-auth-salt' );
		}

		$this->reset_filters();
		delete_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE );
		delete_option( 'atmosphere_connection' );
		delete_option( 'home' );
		delete_option( 'siteurl' );

		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core global reset for test isolation.
		delete_transient( 'settings_errors' );
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_state(): void {
		$this->reset_filters();
	}

	/**
	 * Drop every filter the suite touches.
	 *
	 * @return void
	 */
	private function reset_filters(): void {
		remove_all_filters( Bluesky_Domain_Handle::FILTER_ENABLED );
		remove_all_filters( Bluesky_Domain_Handle::FILTER_PRE_UPDATE );
		remove_all_filters( 'home_url' );
		remove_all_filters( 'option_home' );
		remove_all_filters( 'pre_option_home' );
	}

	/**
	 * Force `home_url()` to return a fixed value across tests.
	 *
	 * @param string $url URL to return.
	 * @return void
	 */
	private function force_home_url( string $url ): void {
		add_filter(
			'home_url',
			static function ( $existing, $path ) use ( $url ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
				return rtrim( $url, '/' ) . ( '' === $path ? '' : $path );
			},
			10,
			2
		);
	}

	/**
	 * Seed an Atmosphere connection so `is_connected()` and `get_connection()`
	 * resolve to a usable handle without going through real OAuth.
	 *
	 * @param string $handle Handle to seed.
	 * @param string $did    DID to seed.
	 * @return void
	 */
	private function seed_connection( string $handle = 'alice.bsky.social', string $did = 'did:plc:test123' ): void {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => $did,
				'handle'       => $handle,
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);
	}

	/**
	 * Seed a previous-handle snapshot in the new `{did, handle}` format.
	 *
	 * @param string $did    DID the snapshot is bound to.
	 * @param string $handle Handle to revert to.
	 * @return void
	 */
	private function seed_snapshot( string $did, string $handle ): void {
		update_option(
			Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => $did,
				'handle' => $handle,
			),
			false
		);
	}

	// ---- is_enabled ----

	/**
	 * Feature defaults to enabled.
	 */
	public function test_is_enabled_defaults_true(): void {
		$this->assertTrue( Bluesky_Domain_Handle::is_enabled() );
	}

	/**
	 * Filtering the kill-switch off disables the feature.
	 */
	public function test_is_enabled_respects_filter(): void {
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );
		$this->assertFalse( Bluesky_Domain_Handle::is_enabled() );
	}

	// ---- is_root_install / get_target_handle ----

	/**
	 * Root-install detection accepts every reasonable shape of
	 * `home_url()` for a domain-rooted site.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	public static function root_install_provider(): array {
		return array(
			'no path'             => array( 'https://example.com', true ),
			'trailing slash only' => array( 'https://example.com/', true ),
			'subdomain root'      => array( 'https://blog.example.com', true ),
			'subdirectory'        => array( 'https://example.com/blog', false ),
			'deep subdirectory'   => array( 'https://example.com/site/wp', false ),
			'trailing subdir'     => array( 'https://example.com/blog/', false ),
		);
	}

	/**
	 * Subdirectory installs are excluded from the feature.
	 *
	 * @param string $url      home_url() value.
	 * @param bool   $expected Expected is_root_install() result.
	 * @dataProvider root_install_provider
	 */
	#[DataProvider( 'root_install_provider' )]
	public function test_is_root_install_for_home_url_shape( string $url, bool $expected ): void {
		$this->force_home_url( $url );

		$this->assertSame( $expected, Bluesky_Domain_Handle::is_root_install() );
	}

	/**
	 * Target handle reads the host portion of `home_url()`, lowercased.
	 */
	public function test_get_target_handle_returns_lowercased_host(): void {
		$this->force_home_url( 'https://Example.COM' );
		$this->assertSame( 'example.com', Bluesky_Domain_Handle::get_target_handle() );
	}

	/**
	 * Target handle preserves the subdomain.
	 */
	public function test_get_target_handle_preserves_subdomain(): void {
		$this->force_home_url( 'https://blog.example.com' );
		$this->assertSame( 'blog.example.com', Bluesky_Domain_Handle::get_target_handle() );
	}

	// ---- should_offer ----

	/**
	 * A connected user on a root-installed site sees the offer.
	 */
	public function test_should_offer_default_for_connected_root_install(): void {
		$this->force_home_url( 'https://example.com' );

		$this->assertTrue(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Disabled feature suppresses the offer entirely.
	 */
	public function test_should_offer_respects_disabled_filter(): void {
		$this->force_home_url( 'https://example.com' );
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );

		$this->assertFalse(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Subdirectory installs cannot offer the confirm UI.
	 */
	public function test_should_offer_subdirectory_blocked(): void {
		$this->force_home_url( 'https://example.com/blog' );

		$this->assertFalse(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Disconnected users have no handle to update.
	 */
	public function test_should_offer_disconnected_blocked(): void {
		$this->force_home_url( 'https://example.com' );

		$this->assertFalse(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => false,
					'handle'    => '',
				)
			)
		);
	}

	/**
	 * Connections already on the site host are a no-op offer.
	 */
	public function test_should_offer_skipped_when_handle_matches_host(): void {
		$this->force_home_url( 'https://Example.com' );

		$this->assertFalse(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'example.com',
				)
			)
		);
	}

	// ---- set_handle ----

	/**
	 * Disabled feature short-circuits the call AND posts an info notice
	 * so the caller doesn't have to invent a "nothing happened" message.
	 */
	public function test_set_handle_disabled_returns_null_with_info_notice(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection();
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$this->assertNull( Bluesky_Domain_Handle::set_handle() );
		$this->assertFalse( $captured, 'Disabled feature must not invoke the underlying call.' );
		$this->assertContains( 'info', wp_list_pluck( get_settings_errors( 'atmosphere' ), 'type' ) );
	}

	/**
	 * Subdirectory installs short-circuit the call AND post an error notice
	 * explaining why — otherwise the user clicks confirm and sees a silent
	 * reload with no feedback.
	 */
	public function test_set_handle_subdirectory_returns_null_with_error_notice(): void {
		$this->force_home_url( 'https://example.com/blog' );
		$this->seed_connection();

		$this->assertNull( Bluesky_Domain_Handle::set_handle() );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== stripos( $m, 'subdirectory' )
			)
		);
	}

	/**
	 * Empty hostname (degraded `home` option) refuses the call AND posts
	 * an error notice — otherwise we'd send `handle: ''` to the PDS.
	 */
	public function test_set_handle_empty_host_returns_null_with_error_notice(): void {
		$this->force_home_url( 'https:///just-a-path' );
		$this->seed_connection();

		$this->assertNull( Bluesky_Domain_Handle::set_handle() );

		$types = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'type' );
		$this->assertContains( 'error', $types );
	}

	/**
	 * No connection = no call. Surfaces an error notice for the user.
	 */
	public function test_set_handle_unconnected_returns_error_with_notice(): void {
		$this->force_home_url( 'https://example.com' );
		// No seeded connection.

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'fosse_not_connected', $result->get_error_code() );
		$this->assertContains( 'error', wp_list_pluck( get_settings_errors( 'atmosphere' ), 'type' ) );
	}

	/**
	 * Successful call snapshots the previous handle (DID-bound), syncs the
	 * locally-cached connection handle, and posts a success notice.
	 *
	 * The DID binding prevents reconnect-to-different-account from later
	 * pushing the wrong handle to the new account on disconnect.
	 */
	public function test_set_handle_success_snapshots_previous_handle(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'alice.bsky.social' );

		$received = null;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function ( $pre, $handle ) use ( &$received ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
				$received = $handle;
				return true;
			},
			10,
			2
		);

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertTrue( $result );
		$this->assertSame( 'example.com', $received );
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE )
		);

		// The locally-cached connection handle must reflect the new value
		// immediately — otherwise the UI keeps offering a no-op confirm
		// and Atmosphere's mention-facet builder keeps emitting the old
		// handle.
		$connection = get_option( 'atmosphere_connection' );
		$this->assertSame( 'example.com', $connection['handle'] );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== strpos( $m, 'Your Bluesky handle is now example.com' )
			)
		);
	}

	/**
	 * No-op when the connection handle already matches the site host —
	 * nothing to call, nothing to snapshot. Posts an info notice so the
	 * user understands "you're already set" instead of seeing a silent
	 * page reload with no feedback.
	 */
	public function test_set_handle_already_matches_skips_call_and_snapshot(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'example.com' );

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertNull( $result );
		$this->assertFalse( $captured );
		$this->assertFalse( get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ) );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== strpos( $m, 'already' )
			)
		);
	}

	/**
	 * A failed call surfaces the WP_Error and posts an error notice — and
	 * deliberately does NOT leave a stale snapshot behind. The earlier
	 * design wrote the snapshot before the call, which let a failed
	 * `set_handle()` followed by an external handle change get
	 * silently overwritten on disconnect; storing only after confirmed
	 * success closes that gap.
	 */
	public function test_set_handle_failure_surfaces_error_without_writing_snapshot(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'alice.bsky.social' );

		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static fn() => new \WP_Error( 'fake_pds', 'rate limited' )
		);

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate limited', $result->get_error_message() );
		$this->assertFalse( get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ) );

		// The locally-cached connection handle must NOT change on failure —
		// the remote handle is still the old value.
		$connection = get_option( 'atmosphere_connection' );
		$this->assertSame( 'alice.bsky.social', $connection['handle'] );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== strpos( $m, 'Could not set example.com' ) && false !== strpos( $m, 'rate limited' )
			)
		);
	}

	// ---- maybe_revert_on_disconnect ----

	/**
	 * Revert is a no-op without a snapshotted previous handle.
	 */
	public function test_maybe_revert_on_disconnect_without_snapshot_returns_null(): void {
		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$this->assertNull( Bluesky_Domain_Handle::maybe_revert_on_disconnect() );
		$this->assertFalse( $captured );
	}

	/**
	 * Successful revert clears the snapshot, syncs the locally-cached
	 * connection handle, and posts an info notice.
	 */
	public function test_maybe_revert_on_disconnect_success_clears_option(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		$received = null;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function ( $pre, $handle ) use ( &$received ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
				$received = $handle;
				return true;
			},
			10,
			2
		);

		$result = Bluesky_Domain_Handle::maybe_revert_on_disconnect();

		$this->assertTrue( $result );
		$this->assertSame( 'alice.bsky.social', $received );
		$this->assertFalse( get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ) );

		// Local connection handle must roll back to match the remote.
		$connection = get_option( 'atmosphere_connection' );
		$this->assertSame( 'alice.bsky.social', $connection['handle'] );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== strpos( $m, 'Restored your previous Bluesky handle' )
			)
		);
	}

	/**
	 * Failed revert preserves the snapshot so a future retry can still revert,
	 * and deliberately does NOT post its own notice — the caller composes a
	 * combined "disconnected, but couldn't revert" message instead, so the
	 * user doesn't see a green "Disconnected" success appended after a
	 * yellow warning that's easy to read past.
	 */
	public function test_maybe_revert_on_disconnect_failure_preserves_option_without_notice(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static fn() => new \WP_Error( 'fake_pds', 'token revoked' )
		);

		$result = Bluesky_Domain_Handle::maybe_revert_on_disconnect();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE )
		);

		$this->assertEmpty(
			get_settings_errors( 'atmosphere' ),
			'maybe_revert_on_disconnect() must not post its own notice on failure — the caller composes a combined message.'
		);
	}

	/**
	 * Snapshot is bound to the DID it was taken under. A subsequent
	 * connect-to-different-account followed by disconnect must NOT push
	 * the prior account's handle onto the new account.
	 */
	public function test_maybe_revert_on_disconnect_refuses_when_did_does_not_match(): void {
		$this->seed_connection( 'bob.bsky.social', 'did:plc:bob456' );
		$this->seed_snapshot( 'did:plc:alice123', 'alice.bsky.social' );

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$result = Bluesky_Domain_Handle::maybe_revert_on_disconnect();

		$this->assertNull( $result );
		$this->assertFalse( $captured, 'Revert must not run when the snapshot belongs to a different DID.' );
		$this->assertNotFalse(
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ),
			'Mismatched-DID snapshots are kept — the legitimate account may reconnect later.'
		);
	}

	/**
	 * Disabled feature skips the revert path entirely.
	 */
	public function test_maybe_revert_on_disconnect_disabled_returns_null(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$this->assertNull( Bluesky_Domain_Handle::maybe_revert_on_disconnect() );
		$this->assertFalse( $captured );
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE )
		);
	}

	// ---- pre-update filter contract ----

	/**
	 * A non-null, non-bool-true, non-WP_Error short-circuit return is rejected.
	 */
	public function test_invalid_pre_update_filter_return_surfaces_error(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'alice.bsky.social' );
		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, static fn() => 'unexpected string' );

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'fosse_invalid_pre_update_handle_return', $result->get_error_code() );
	}

	/**
	 * Class metadata is stable: filter names, settings group, option key,
	 * and the notice code the wizard's notice-suppression filter pivots on.
	 *
	 * Catches accidental constant renames that would silently break
	 * external integrations filtering on the documented filter names or
	 * reading the previous-handle option directly.
	 */
	public function test_public_constants_are_stable(): void {
		$this->assertSame( 'fosse_domain_handle_enabled', Bluesky_Domain_Handle::FILTER_ENABLED );
		$this->assertSame( 'fosse_pre_bluesky_update_handle', Bluesky_Domain_Handle::FILTER_PRE_UPDATE );
		$this->assertSame( 'atmosphere', Bluesky_Domain_Handle::NOTICE_SETTING );
		$this->assertSame( 'fosse_domain_handle', Bluesky_Domain_Handle::NOTICE_CODE );
		$this->assertSame( 'fosse_bluesky_previous_handle', Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE );

		$reflection = new ReflectionClass( Bluesky_Domain_Handle::class );
		$this->assertFalse(
			$reflection->hasConstant( 'FILTER_AUTOMATIC' ),
			'Automatic mode was deliberately removed; replacing a Bluesky handle must always be an explicit user action.'
		);
		$this->assertFalse(
			$reflection->hasConstant( 'FORM_FIELD' ),
			'The pre-OAuth checkbox was deliberately removed; the confirm flow runs after connect.'
		);
	}

	/**
	 * Every notice this service posts is tagged with NOTICE_CODE so the
	 * wizard's top-of-step notice-suppression filter can distinguish our
	 * messages from Atmosphere's own connect-success echo.
	 *
	 * Without the code tag, our success message would be silently
	 * suppressed by the wizard's `success`/`info` type filter, leaving the
	 * user with no confirmation that their handle change went through.
	 */
	public function test_all_notices_carry_the_domain_handle_code(): void {
		$this->force_home_url( 'https://example.com' );

		// Cover one early-return path (disabled) plus one successful call —
		// proves the code tag is consistent across info/success/error types.
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );
		Bluesky_Domain_Handle::set_handle();

		remove_all_filters( Bluesky_Domain_Handle::FILTER_ENABLED );
		$this->seed_connection( 'alice.bsky.social' );
		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );
		Bluesky_Domain_Handle::set_handle();

		$codes = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'code' );
		$this->assertNotEmpty( $codes );
		foreach ( $codes as $code ) {
			$this->assertSame( Bluesky_Domain_Handle::NOTICE_CODE, $code );
		}
	}
}
