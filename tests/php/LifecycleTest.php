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
 * Mirrors the Data Ownership table in `sdd/deactivation-lifecycle/spec.md`.
 * If that table changes, this test must change in lockstep.
 */
class LifecycleTest extends BaseTestCase {

	/**
	 * Exact FOSSE-owned options the spec says uninstall must delete.
	 *
	 * @var string[]
	 */
	private const FOSSE_OWNED_OPTIONS = array(
		'fosse_object_type',
		'fosse_long_form_strategy',
		'fosse_onboarding_completed',
		'fosse_onboarding_destination',
		'fosse_activation_redirect',
		'fosse_bundled_ap_bootstrapped',
		'fosse_bundled_atmosphere_bootstrapped',
		'fosse_canonical_options_migrated',
		'fosse_metrics_consent',
		'fosse_metrics_last_observed_at',
		'fosse_metrics_first_observed_at',
		'fosse_metrics_funnel',
	);

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
		foreach ( self::FOSSE_OWNED_OPTIONS as $key ) {
			delete_option( $key );
		}
		foreach ( array_keys( self::UPSTREAM_OWNED_OPTIONS ) as $key ) {
			delete_option( $key );
		}
		delete_transient( 'fosse_activation_redirect' );
		delete_transient( 'fosse_bluesky_oauth_return_123' );
		delete_transient( 'fosse_deactivation_handoff_pending' );
	}

	/**
	 * Seeded FOSSE state is removed; seeded upstream state is preserved
	 * exactly as written.
	 */
	public function test_uninstall_removes_fosse_state_and_preserves_upstream_state(): void {
		foreach ( self::FOSSE_OWNED_OPTIONS as $key ) {
			update_option( $key, 'seeded-' . $key );
		}
		foreach ( self::UPSTREAM_OWNED_OPTIONS as $key => $value ) {
			update_option( $key, $value );
		}
		set_transient( 'fosse_activation_redirect', '1', HOUR_IN_SECONDS );
		set_transient( 'fosse_bluesky_oauth_return_123', 'return-context', HOUR_IN_SECONDS );

		$user_id = wp_insert_user(
			array(
				'user_login' => 'lifecycle-test-user',
				'user_email' => 'lifecycle-test@example.org',
				'user_pass'  => 'lifecycle-test-pass',
			)
		);
		$this->assertIsInt( $user_id, 'Test fixture user insert failed.' );
		update_user_meta( $user_id, '_fosse_wizard_started_emitted', '1' );

		Lifecycle::uninstall();

		foreach ( self::FOSSE_OWNED_OPTIONS as $key ) {
			$this->assertFalse( get_option( $key ), "FOSSE option {$key} should be deleted on uninstall." );
		}
		foreach ( self::UPSTREAM_OWNED_OPTIONS as $key => $value ) {
			$this->assertSame( $value, get_option( $key ), "Upstream option {$key} must survive uninstall unchanged." );
		}
		$this->assertFalse( get_transient( 'fosse_activation_redirect' ) );
		$this->assertFalse( get_transient( 'fosse_bluesky_oauth_return_123' ) );
		$this->assertSame( '', get_user_meta( $user_id, '_fosse_wizard_started_emitted', true ) );
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
}
