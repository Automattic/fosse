<?php
/**
 * Tests for FOSSE uninstall cleanup.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Lifecycle;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies `Lifecycle::uninstall()` deletes only FOSSE-owned state and leaves
 * upstream ActivityPub / Atmosphere options untouched.
 *
 * Iterates `Lifecycle::FOSSE_OWNED_*` constants directly so a new FOSSE-owned
 * key added to the canonical list is automatically exercised by the seed /
 * preserve assertions — no test-side list to forget to update.
 */
class LifecycleTest extends BaseTestCase {

	/**
	 * Upstream-owned options the spec says uninstall must preserve. Seeded
	 * with non-empty values so a wrongly-aggressive cleanup would flip them
	 * to `false` and fail the assertion.
	 *
	 * @var array<string, mixed>
	 */
	private const UPSTREAM_OWNED_OPTIONS = array(
		'activitypub_actor_mode'         => 'blog',
		'activitypub_support_post_types' => array( 'post', 'page' ),
		'activitypub_blog_identifier'    => 'site',
		'atmosphere_connection'          => array( 'handle' => 'example.com' ),
		'atmosphere_auto_publish'        => '1',
	);

	/**
	 * Ensure each test starts with a known-empty DB for the keys we touch.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		foreach ( Lifecycle::FOSSE_OWNED_OPTIONS as $key ) {
			delete_option( $key );
		}
		foreach ( array_keys( self::UPSTREAM_OWNED_OPTIONS ) as $key ) {
			delete_option( $key );
		}
		foreach ( Lifecycle::FOSSE_OWNED_TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
		delete_transient( Lifecycle::FOSSE_TRANSIENT_PREFIX . '123' );
	}

	/**
	 * Seeded FOSSE state is removed; seeded upstream state is preserved
	 * exactly as written.
	 */
	public function test_uninstall_removes_fosse_state_and_preserves_upstream_state(): void {
		foreach ( Lifecycle::FOSSE_OWNED_OPTIONS as $key ) {
			update_option( $key, 'seeded-' . $key );
		}
		foreach ( self::UPSTREAM_OWNED_OPTIONS as $key => $value ) {
			update_option( $key, $value );
		}
		foreach ( Lifecycle::FOSSE_OWNED_TRANSIENTS as $transient ) {
			set_transient( $transient, 'seeded-' . $transient, HOUR_IN_SECONDS );
		}
		set_transient( Lifecycle::FOSSE_TRANSIENT_PREFIX . '123', 'return-context', HOUR_IN_SECONDS );

		$user_id = wp_insert_user(
			array(
				'user_login' => 'lifecycle-test-user',
				'user_email' => 'lifecycle-test@example.org',
				'user_pass'  => 'lifecycle-test-pass',
			)
		);
		$this->assertIsInt( $user_id, 'Test fixture user insert failed.' );
		foreach ( Lifecycle::FOSSE_OWNED_USER_META as $meta_key ) {
			update_user_meta( $user_id, $meta_key, '1' );
		}

		Lifecycle::uninstall();

		foreach ( Lifecycle::FOSSE_OWNED_OPTIONS as $key ) {
			$this->assertFalse( get_option( $key ), "FOSSE option {$key} should be deleted on uninstall." );
		}
		foreach ( self::UPSTREAM_OWNED_OPTIONS as $key => $value ) {
			$this->assertSame( $value, get_option( $key ), "Upstream option {$key} must survive uninstall unchanged." );
		}
		foreach ( Lifecycle::FOSSE_OWNED_TRANSIENTS as $transient ) {
			$this->assertFalse( get_transient( $transient ), "FOSSE transient {$transient} should be deleted on uninstall." );
		}
		$this->assertFalse( get_transient( Lifecycle::FOSSE_TRANSIENT_PREFIX . '123' ) );
		foreach ( Lifecycle::FOSSE_OWNED_USER_META as $meta_key ) {
			$this->assertSame( '', get_user_meta( $user_id, $meta_key, true ), "FOSSE user meta {$meta_key} should be deleted on uninstall." );
		}
	}

	/**
	 * Wildcard transient cleanup removes every key matching the FOSSE
	 * prefix, not just the canonical seed key.
	 *
	 * The raw `LIKE` delete that handles the production non-autoloaded
	 * case can't be exercised under the WorDBless dbless engine (its
	 * `Db_Less_Wpdb::query()` short-circuits SQL). The first cleanup
	 * pass — `wp_load_alloptions()` → `delete_transient()` — does run
	 * in dbless, which is enough to lock in the observable behavior.
	 */
	public function test_uninstall_clears_wildcard_oauth_return_transients(): void {
		set_transient( 'fosse_bluesky_oauth_return_alice', 'alice-context', HOUR_IN_SECONDS );
		set_transient( 'fosse_bluesky_oauth_return_bob', 'bob-context', HOUR_IN_SECONDS );

		Lifecycle::uninstall();

		$this->assertFalse( get_transient( 'fosse_bluesky_oauth_return_alice' ) );
		$this->assertFalse( get_transient( 'fosse_bluesky_oauth_return_bob' ) );
	}

	/**
	 * Calling uninstall on an unconfigured site (nothing seeded) is silent —
	 * no PHP warnings, no DB errors. PHPUnit's `failOnWarning` makes this
	 * assertion implicit; the explicit assertion below makes intent obvious.
	 */
	public function test_uninstall_is_safe_when_no_fosse_state_exists(): void {
		Lifecycle::uninstall();

		$this->assertFalse( get_option( 'fosse_onboarding_completed' ) );
	}

	/**
	 * Calling uninstall twice in the same request must not warn or fatal.
	 * The spec advertises out-of-band callability (wp.com Simple platform
	 * tooling), where the second invocation may overlap a half-finished
	 * first run if scheduling slips. `delete_option` / `delete_transient` /
	 * `delete_metadata` all tolerate already-deleted state — this test
	 * locks that property in.
	 */
	public function test_uninstall_is_idempotent_within_same_request(): void {
		set_transient( 'fosse_bluesky_oauth_return_alice', 'alice-context', HOUR_IN_SECONDS );
		update_option( 'fosse_onboarding_completed', '1' );

		Lifecycle::uninstall();
		Lifecycle::uninstall();

		$this->assertFalse( get_option( 'fosse_onboarding_completed' ) );
		$this->assertFalse( get_transient( 'fosse_bluesky_oauth_return_alice' ) );
	}

	/**
	 * A misbehaving third-party `alloptions` filter that returns a non-array
	 * must not TypeError under PHP 8 and abort uninstall mid-flight. The
	 * guard short-circuits to an empty iteration so the SQL pass still runs.
	 */
	public function test_uninstall_survives_non_array_alloptions_filter(): void {
		$poisoner = static function () {
			return null;
		};
		add_filter( 'alloptions', $poisoner, PHP_INT_MAX );

		try {
			Lifecycle::uninstall();
			$this->assertTrue( true, 'Uninstall completed without TypeError despite a non-array alloptions filter.' );
		} finally {
			remove_filter( 'alloptions', $poisoner, PHP_INT_MAX );
		}
	}
}
