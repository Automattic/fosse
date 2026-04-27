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

	/**
	 * Saving content with no post types selected stores empty array.
	 */
	public function test_handle_save_content_empty_selection() {
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array() ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( array(), get_option( 'activitypub_support_post_types' ) );
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
