<?php
/**
 * Tests for User_Notices.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\User_Notices;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the per-user settings-notice transient.
 *
 * The class exists to replace WordPress's site-global `settings_errors`
 * transient, which would otherwise let one admin's notices leak to a
 * different admin loading any admin page within the 30-second TTL.
 */
class User_NoticesTest extends BaseTestCase {

	/**
	 * Reset state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core global reset for test isolation.

		wp_set_current_user( 0 );
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_state(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			delete_transient( 'fosse_settings_errors_' . $user_id );
		}
		wp_set_current_user( 0 );
	}

	/**
	 * Authenticate a user and return the ID.
	 *
	 * @return int
	 */
	private function as_user(): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_un_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $user_id );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Persist + consume round-trips notices through the per-user transient.
	 *
	 * Persist snapshots the current request's settings errors into the
	 * per-user transient; consume reads them back into the request-global
	 * on the next request, then clears the transient.
	 */
	public function test_persist_then_consume_round_trips_notices(): void {
		$user_id = $this->as_user();

		add_settings_error( 'atmosphere', 'fosse_test', 'Hello world.', 'info' );
		User_Notices::persist();

		$this->assertNotFalse( get_transient( 'fosse_settings_errors_' . $user_id ) );

		// Simulate the redirect: clear the request-global, then consume.
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- simulating fresh request.

		User_Notices::consume();

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertContains( 'Hello world.', $messages );

		// Transient must be cleared after consume — otherwise the next
		// admin page load would render the same notice again.
		$this->assertFalse( get_transient( 'fosse_settings_errors_' . $user_id ) );
	}

	/**
	 * Notices posted by Admin A must NOT be visible to Admin B.
	 *
	 * This is the entire reason this helper exists: the WP-default
	 * site-global `settings_errors` transient leaks across users.
	 */
	public function test_notices_do_not_leak_across_users(): void {
		$admin_a = $this->as_user();
		add_settings_error( 'atmosphere', 'fosse_test', 'Secret message for A.', 'info' );
		User_Notices::persist();

		// Switch to a different admin and clear any inherited request-global.
		$admin_b = wp_insert_user(
			array(
				'user_login' => 'fosse_un_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $admin_b );
		wp_set_current_user( $admin_b );

		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- simulating B's fresh admin request.

		User_Notices::consume();

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotContains( 'Secret message for A.', $messages );
		$this->assertEmpty( $messages );

		// A's transient is still intact — they haven't loaded an admin
		// page yet, and consuming under B's identity must not have eaten it.
		$this->assertNotFalse( get_transient( 'fosse_settings_errors_' . $admin_a ) );

		// Cleanup: drain A's transient too.
		wp_set_current_user( $admin_a );
		delete_transient( 'fosse_settings_errors_' . $admin_a );
	}

	/**
	 * Anonymous requests skip the transient entirely — no per-user key
	 * exists, and falling back to the global one would re-introduce the
	 * cross-user leak this helper is designed to prevent.
	 */
	public function test_persist_is_noop_for_anonymous_user(): void {
		add_settings_error( 'atmosphere', 'fosse_test', 'Anon message.', 'info' );

		User_Notices::persist();

		// No deterministic key to read; just assert the per-user shape
		// did not get written for any plausible user id.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( get_transient( 'fosse_settings_errors_' . $i ) );
		}
	}

	/**
	 * An empty error list does NOT write a transient.
	 *
	 * Otherwise a routine `wp_safe_redirect()` from a quiet handler would
	 * create a stale empty key that consume() would still process.
	 */
	public function test_persist_skips_when_no_errors_pending(): void {
		$user_id = $this->as_user();

		User_Notices::persist();

		$this->assertFalse( get_transient( 'fosse_settings_errors_' . $user_id ) );
	}

	/**
	 * Two persist() calls in the same request must not clobber each other.
	 *
	 * Pins the contract that persist() merges into the existing transient
	 * (instead of overwriting) so a handler that emits two notices and
	 * persists between them sees both messages survive into the next
	 * request's consume().
	 */
	public function test_persist_twice_then_consume_preserves_both_notices(): void {
		$user_id = $this->as_user();

		add_settings_error( 'atmosphere', 'fosse_test_first', 'First message.', 'info' );
		User_Notices::persist();

		add_settings_error( 'atmosphere', 'fosse_test_second', 'Second message.', 'warning' );
		User_Notices::persist();

		// Simulate the redirect: clear the request-global, then consume.
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- simulating fresh request.

		User_Notices::consume();

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertContains( 'First message.', $messages );
		$this->assertContains( 'Second message.', $messages );

		// Transient cleared after consume.
		$this->assertFalse( get_transient( 'fosse_settings_errors_' . $user_id ) );
	}

	/**
	 * The forget() helper drops a pending transient.
	 *
	 * Used when a handler decides mid-flight that it doesn't want to
	 * surface what it queued.
	 */
	public function test_forget_clears_pending_transient(): void {
		$user_id = $this->as_user();

		add_settings_error( 'atmosphere', 'fosse_test', 'Will be forgotten.', 'info' );
		User_Notices::persist();
		$this->assertNotFalse( get_transient( 'fosse_settings_errors_' . $user_id ) );

		User_Notices::forget();
		$this->assertFalse( get_transient( 'fosse_settings_errors_' . $user_id ) );
	}

	/**
	 * The consume() helper must NOT drain the transient during an admin-ajax request.
	 *
	 * `admin_init` fires on admin-ajax.php (Heartbeat ticks, other
	 * plugins' polls). A background ajax request from another open
	 * wp-admin tab must not eat the pending notices before the user's
	 * redirect target renders them — otherwise the "Settings saved" /
	 * error banner is silently lost.
	 */
	public function test_consume_is_noop_during_ajax_request(): void {
		$user_id = $this->as_user();

		add_settings_error( 'atmosphere', 'fosse_test', 'Survives the ajax tick.', 'info' );
		User_Notices::persist();
		$this->assertNotFalse( get_transient( 'fosse_settings_errors_' . $user_id ) );

		// Simulate the background ajax request: fresh request-global, and
		// wp_doing_ajax() returns true via the core filter.
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- simulating a fresh ajax request.

		$doing_ajax = '__return_true';
		add_filter( 'wp_doing_ajax', $doing_ajax );

		User_Notices::consume();

		remove_filter( 'wp_doing_ajax', $doing_ajax );

		// The transient must still be intact — the ajax tick must not have
		// consumed it.
		$this->assertNotFalse( get_transient( 'fosse_settings_errors_' . $user_id ) );

		// And nothing was merged into the request-global during the tick.
		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotContains( 'Survives the ajax tick.', $messages );

		// A subsequent real page view (no ajax) consumes it as normal.
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- simulating the real page load that follows.
		User_Notices::consume();

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertContains( 'Survives the ajax tick.', $messages );
		$this->assertFalse( get_transient( 'fosse_settings_errors_' . $user_id ) );
	}

	/**
	 * The register() helper wires consume() onto admin_init priority 1.
	 *
	 * Priority 1 ensures the merge runs before any settings_errors()
	 * render attempt later in the request.
	 */
	public function test_register_hooks_consume_on_admin_init(): void {
		User_Notices::register();

		$priority = has_action( 'admin_init', array( User_Notices::class, 'consume' ) );

		$this->assertSame( 1, $priority );

		remove_action( 'admin_init', array( User_Notices::class, 'consume' ), 1 );
	}
}
