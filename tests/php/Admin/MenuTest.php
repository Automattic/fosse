<?php
/**
 * Tests for the activation-redirect logic in Menu.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Menu;
use Automattic\Fosse\Admin\Onboarding_Wizard;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies Menu::maybe_redirect_to_wizard() across its eight branches.
 */
class MenuTest extends BaseTestCase {

	/**
	 * Reset state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		delete_option( Onboarding_Wizard::COMPLETED_OPTION );
		delete_transient( Onboarding_Wizard::REDIRECT_TRANSIENT );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test cleanup.
		$_GET = array();
		wp_set_current_user( 0 );
	}

	/**
	 * Restore globals after each test.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_state(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test cleanup.
		$_GET = array();
		remove_all_filters( 'wp_redirect' );
	}

	/**
	 * Returns false when the activation transient is absent.
	 */
	public function test_no_op_when_transient_absent(): void {
		$this->become_admin();
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
	}

	/**
	 * Successful redirect consumes the transient and lands on the wizard URL.
	 */
	public function test_redirects_admin_when_transient_present(): void {
		$this->become_admin();
		set_transient( Onboarding_Wizard::REDIRECT_TRANSIENT, 1, 30 );
		$this->arm_redirect_trap();

		try {
			Menu::maybe_redirect_to_wizard();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( $this->redirect_fired() );
		$this->assertStringContainsString( 'page=fosse-wizard', $this->captured_redirect() );
		$this->assertFalse( get_transient( Onboarding_Wizard::REDIRECT_TRANSIENT ) );
	}

	/**
	 * Non-admin user does not consume the transient.
	 */
	public function test_does_not_consume_transient_for_non_admin(): void {
		set_transient( Onboarding_Wizard::REDIRECT_TRANSIENT, 1, 30 );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_subscriber_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertNotFalse( get_transient( Onboarding_Wizard::REDIRECT_TRANSIENT ) );
	}

	/**
	 * AJAX requests skip and preserve the transient for a later admin load.
	 */
	public function test_skips_during_ajax_and_preserves_transient(): void {
		$this->become_admin();
		set_transient( Onboarding_Wizard::REDIRECT_TRANSIENT, 1, 30 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertNotFalse( get_transient( Onboarding_Wizard::REDIRECT_TRANSIENT ) );

		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	/**
	 * Cron runs skip and preserve the transient.
	 */
	public function test_skips_during_cron_and_preserves_transient(): void {
		$this->become_admin();
		set_transient( Onboarding_Wizard::REDIRECT_TRANSIENT, 1, 30 );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertNotFalse( get_transient( Onboarding_Wizard::REDIRECT_TRANSIENT ) );

		remove_filter( 'wp_doing_cron', '__return_true' );
	}

	/**
	 * Already-complete wizard clears the transient and does not redirect.
	 */
	public function test_clears_transient_when_already_complete(): void {
		$this->become_admin();
		set_transient( Onboarding_Wizard::REDIRECT_TRANSIENT, 1, 30 );
		Onboarding_Wizard::mark_complete();
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertFalse( get_transient( Onboarding_Wizard::REDIRECT_TRANSIENT ) );
	}

	/**
	 * Bulk activation clears the transient and does not redirect.
	 *
	 * Without this guard, a follow-up admin request inside the 30-second
	 * TTL could redirect away from the bulk-activation result page.
	 */
	public function test_activate_multi_clears_transient(): void {
		$this->become_admin();
		set_transient( Onboarding_Wizard::REDIRECT_TRANSIENT, 1, 30 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET['activate-multi'] = '1';
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertFalse( get_transient( Onboarding_Wizard::REDIRECT_TRANSIENT ) );
	}

	// --- helpers ---

	/**
	 * Captured target of the most recent intercepted redirect, if any.
	 *
	 * @var string|null
	 */
	private ?string $captured_redirect = null;

	/**
	 * Hook a wp_redirect filter that records the target and throws so we
	 * can detect redirects without leaving the test.
	 */
	private function arm_redirect_trap(): void {
		$this->captured_redirect = null;
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->captured_redirect = (string) $location;
				throw new \Exception( 'redirect' );
			}
		);
	}

	/**
	 * Whether the most recent maybe_redirect_to_wizard call redirected.
	 *
	 * @return bool
	 */
	private function redirect_fired(): bool {
		return null !== $this->captured_redirect;
	}

	/**
	 * Most recent captured redirect target.
	 *
	 * @return string
	 */
	private function captured_redirect(): string {
		return (string) $this->captured_redirect;
	}

	/**
	 * Create and authenticate an administrator user.
	 */
	private function become_admin(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_admin_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );
	}
}
