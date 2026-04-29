<?php
/**
 * Tests for Onboarding_Wizard.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Onboarding_Wizard;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies wizard completion tracking, form handling, and skip/reset flows.
 */
class Onboarding_WizardTest extends BaseTestCase {

	/**
	 * Clean option state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		delete_option( Onboarding_Wizard::COMPLETED_OPTION );
		delete_option( 'activitypub_actor_mode' );
		delete_option( 'activitypub_support_post_types' );
		delete_transient( Onboarding_Wizard::REDIRECT_TRANSIENT );
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_state(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test cleanup.
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();

		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
	}

	// --- is_complete / mark_complete ---

	/**
	 * Wizard is not complete by default.
	 */
	public function test_is_complete_false_by_default() {
		$this->assertFalse( Onboarding_Wizard::is_complete() );
	}

	/**
	 * Mark_complete sets the option and is_complete returns true.
	 */
	public function test_mark_complete_sets_option() {
		Onboarding_Wizard::mark_complete();

		$this->assertTrue( Onboarding_Wizard::is_complete() );
	}

	/**
	 * Deleting the option makes is_complete return false again.
	 */
	public function test_delete_option_resets_completion() {
		Onboarding_Wizard::mark_complete();
		delete_option( Onboarding_Wizard::COMPLETED_OPTION );

		$this->assertFalse( Onboarding_Wizard::is_complete() );
	}

	// --- handle_save: appearance step ---

	/**
	 * Saving the appearance step stores the actor mode option.
	 */
	public function test_handle_save_appearance_stores_actor_mode() {
		$this->simulate_save_request( 'appearance', array( 'activitypub_actor_mode' => 'blog' ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Saving appearance with actor_blog mode works.
	 */
	public function test_handle_save_appearance_actor_blog() {
		$this->simulate_save_request( 'appearance', array( 'activitypub_actor_mode' => 'actor_blog' ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor_blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Invalid actor mode is rejected.
	 */
	public function test_handle_save_appearance_rejects_invalid_mode() {
		update_option( 'activitypub_actor_mode', 'actor' );
		$this->simulate_save_request( 'appearance', array( 'activitypub_actor_mode' => 'evil' ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor', get_option( 'activitypub_actor_mode' ) );
	}

	// --- handle_save: content step ---

	/**
	 * Saving the content step stores post types.
	 */
	public function test_handle_save_content_stores_post_types() {
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array( 'post', 'page' ) ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertContains( 'page', $saved );
	}

	/**
	 * Invalid post types are filtered out.
	 */
	public function test_handle_save_content_filters_invalid_types() {
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array( 'post', 'faketype' ) ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertNotContains( 'faketype', $saved );
	}

	// --- handle_skip ---

	/**
	 * Skip marks wizard complete.
	 */
	public function test_handle_skip_marks_complete() {
		$this->simulate_skip_request();

		try {
			Onboarding_Wizard::handle_skip();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( Onboarding_Wizard::is_complete() );
	}

	// --- handle_complete ---

	/**
	 * Complete action marks wizard complete.
	 */
	public function test_handle_complete_marks_complete() {
		$this->simulate_complete_request();

		try {
			Onboarding_Wizard::handle_complete();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( Onboarding_Wizard::is_complete() );
	}

	// --- handle_reset ---

	/**
	 * Reset clears the completed option.
	 */
	public function test_handle_reset_clears_completion() {
		Onboarding_Wizard::mark_complete();
		$this->simulate_reset_request();

		try {
			Onboarding_Wizard::handle_reset();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertFalse( Onboarding_Wizard::is_complete() );
	}

	// --- cap and nonce failures ---

	/**
	 * Non-admin users hitting the save handler trigger wp_die and the
	 * actor mode option is not written.
	 */
	public function test_handle_save_dies_for_non_admin(): void {
		$this->become_subscriber();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'                 => 'fosse_wizard_save',
			'_wpnonce'               => wp_create_nonce( 'fosse_wizard' ),
			'fosse_wizard_step'      => 'appearance',
			'activitypub_actor_mode' => 'blog',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::handle_save();
		} finally {
			$this->assertFalse( get_option( 'activitypub_actor_mode' ) );
		}
	}

	/**
	 * Save handler with a bad nonce dies before writing the option.
	 */
	public function test_handle_save_dies_on_bad_nonce(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'                 => 'fosse_wizard_save',
			'_wpnonce'               => 'invalid',
			'fosse_wizard_step'      => 'appearance',
			'activitypub_actor_mode' => 'blog',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::handle_save();
		} finally {
			$this->assertFalse( get_option( 'activitypub_actor_mode' ) );
		}
	}

	/**
	 * Skip handler dies for non-admin users and does not mark complete.
	 */
	public function test_handle_skip_dies_for_non_admin(): void {
		$this->become_subscriber();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_skip',
			'_wpnonce' => wp_create_nonce( 'fosse_wizard_skip' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::handle_skip();
		} finally {
			$this->assertFalse( Onboarding_Wizard::is_complete() );
		}
	}

	/**
	 * Skip handler dies on bad nonce and does not mark complete.
	 */
	public function test_handle_skip_dies_on_bad_nonce(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_skip',
			'_wpnonce' => 'invalid',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::handle_skip();
		} finally {
			$this->assertFalse( Onboarding_Wizard::is_complete() );
		}
	}

	/**
	 * Complete handler dies for non-admin users and does not mark complete.
	 */
	public function test_handle_complete_dies_for_non_admin(): void {
		$this->become_subscriber();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_complete',
			'_wpnonce' => wp_create_nonce( 'fosse_wizard_complete' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::handle_complete();
		} finally {
			$this->assertFalse( Onboarding_Wizard::is_complete() );
		}
	}

	/**
	 * Complete handler dies on bad nonce and does not mark complete.
	 */
	public function test_handle_complete_dies_on_bad_nonce(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_complete',
			'_wpnonce' => 'invalid',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::handle_complete();
		} finally {
			$this->assertFalse( Onboarding_Wizard::is_complete() );
		}
	}

	/**
	 * Reset handler dies for non-admin users and the completed flag is preserved.
	 */
	public function test_handle_reset_dies_for_non_admin(): void {
		Onboarding_Wizard::mark_complete();
		$this->become_subscriber();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_reset',
			'_wpnonce' => wp_create_nonce( 'fosse_wizard_reset' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::handle_reset();
		} finally {
			$this->assertTrue( Onboarding_Wizard::is_complete() );
		}
	}

	/**
	 * Reset handler dies on bad nonce and the completed flag is preserved.
	 */
	public function test_handle_reset_dies_on_bad_nonce(): void {
		Onboarding_Wizard::mark_complete();
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_reset',
			'_wpnonce' => 'invalid',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::handle_reset();
		} finally {
			$this->assertTrue( Onboarding_Wizard::is_complete() );
		}
	}

	// --- empty post types redirect ---

	/**
	 * Empty post-types submission bounces back to the content step with
	 * an error code instead of overwriting the option with [].
	 */
	public function test_handle_save_content_empty_redirects_with_error(): void {
		update_option( 'activitypub_support_post_types', array( 'post' ) );

		$captured = null;
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array() ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new \Exception( 'redirect' );
			},
			9
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'step=content', $captured );
		$this->assertStringContainsString( 'error=empty_post_types', $captured );
		$this->assertSame( array( 'post' ), get_option( 'activitypub_support_post_types' ) );
	}

	// --- redirect transient ---

	/**
	 * Setting the redirect transient allows retrieval.
	 */
	public function test_redirect_transient_round_trip() {
		set_transient( Onboarding_Wizard::REDIRECT_TRANSIENT, 1, 30 );

		$this->assertNotFalse( get_transient( Onboarding_Wizard::REDIRECT_TRANSIENT ) );
	}

	// --- helpers ---

	/**
	 * Authenticate as a non-administrator (subscriber).
	 */
	private function become_subscriber(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_sub_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );
	}

	/**
	 * Replace the wp_die handler with one that throws so cap/nonce
	 * failures can be observed without exiting the test process.
	 */
	private function arm_die_trap(): void {
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new \Exception( 'wp_die: ' . wp_strip_all_tags( (string) $message ) );
				};
			}
		);
	}

	/**
	 * Simulate a wizard save POST request.
	 *
	 * @param string               $step      Step slug.
	 * @param array<string, mixed> $post_data Additional POST data.
	 * @return void
	 */
	private function simulate_save_request( string $step, array $post_data = array() ): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$defaults = array(
			'action'            => 'fosse_wizard_save',
			'_wpnonce'          => wp_create_nonce( 'fosse_wizard' ),
			'fosse_wizard_step' => $step,
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array_merge( $defaults, $post_data );
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new \Exception( 'redirect' );
			}
		);
	}

	/**
	 * Simulate a wizard skip request.
	 *
	 * @return void
	 */
	private function simulate_skip_request(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_skip',
			'_wpnonce' => wp_create_nonce( 'fosse_wizard_skip' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new \Exception( 'redirect' );
			}
		);
	}

	/**
	 * Simulate a wizard complete request.
	 *
	 * @return void
	 */
	private function simulate_complete_request(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_complete',
			'_wpnonce' => wp_create_nonce( 'fosse_wizard_complete' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new \Exception( 'redirect' );
			}
		);
	}

	/**
	 * Simulate a wizard reset request.
	 *
	 * @return void
	 */
	private function simulate_reset_request(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_reset',
			'_wpnonce' => wp_create_nonce( 'fosse_wizard_reset' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new \Exception( 'redirect' );
			}
		);
	}
}
