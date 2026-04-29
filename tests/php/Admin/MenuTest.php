<?php
/**
 * Tests for the activation-redirect logic in Menu.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\AP_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use Automattic\Fosse\Admin\Menu;
use Automattic\Fosse\Admin\Onboarding_Wizard;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies Menu::maybe_redirect_to_wizard() across its seven branches.
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
		delete_option( Onboarding_Wizard::REDIRECT_OPTION );
		delete_transient( Onboarding_Wizard::REDIRECT_TRANSIENT );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test cleanup.
		$_GET = array();
		wp_set_current_user( 0 );

		// Tests below assume an ActivityPub provider is registered (matching
		// the live plugin's load order). Re-register fresh so tests that
		// reset the registry mid-flow don't bleed into siblings.
		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
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

		// Restore the AP registration so a test that called
		// Connection_Provider_Registry::reset() doesn't leave the next
		// test class without a provider.
		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
	}

	/**
	 * Returns false when the redirect option is absent.
	 */
	public function test_no_op_when_option_absent(): void {
		$this->become_admin();
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
	}

	/**
	 * Successful redirect consumes the option and lands on the wizard URL.
	 */
	public function test_redirects_admin_when_option_present(): void {
		$this->become_admin();
		update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
		$this->arm_redirect_trap();

		try {
			Menu::maybe_redirect_to_wizard();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( $this->redirect_fired() );
		$this->assertStringContainsString( 'page=fosse-wizard', $this->captured_redirect() );
		$this->assertFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );
	}

	/**
	 * Non-admin user does not consume the option.
	 */
	public function test_does_not_consume_option_for_non_admin(): void {
		update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_subscriber_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'subscriber',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertNotFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );
	}

	/**
	 * AJAX requests skip and preserve the option for a later admin load.
	 */
	public function test_skips_during_ajax_and_preserves_option(): void {
		$this->become_admin();
		update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertNotFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );

		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	/**
	 * Cron runs skip and preserve the option.
	 */
	public function test_skips_during_cron_and_preserves_option(): void {
		$this->become_admin();
		update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertNotFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );

		remove_filter( 'wp_doing_cron', '__return_true' );
	}

	/**
	 * Already-complete wizard clears the option and does not redirect.
	 */
	public function test_clears_option_when_already_complete(): void {
		$this->become_admin();
		update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
		Onboarding_Wizard::mark_complete();
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );
	}

	/**
	 * Bulk activation clears the option and does not redirect.
	 *
	 * Without this guard, a follow-up admin request could redirect away
	 * from the bulk-activation result page.
	 */
	public function test_activate_multi_clears_option(): void {
		$this->become_admin();
		update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET['activate-multi'] = '1';
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );
	}

	/**
	 * No registered ActivityPub provider preserves the option and skips
	 * the redirect, so a later admin request (after AP becomes available)
	 * can still land the user on the wizard.
	 */
	public function test_no_redirect_when_activitypub_unavailable(): void {
		$this->become_admin();
		update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
		Connection_Provider_Registry::reset();
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertNotFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );
	}

	/**
	 * A leftover legacy transient migrates onto the new option-backed
	 * signal: the transient is gone afterward, the option is preserved
	 * (because AP is unavailable on this guard path), and no redirect
	 * fires.
	 */
	public function test_legacy_transient_migrates_to_option(): void {
		$this->become_admin();
		set_transient( Onboarding_Wizard::REDIRECT_TRANSIENT, 1, 30 );
		// Drop AP so we land on the no-provider guard, where the option
		// is preserved — a single explicit path keeps the migration
		// observable.
		Connection_Provider_Registry::reset();
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertFalse( get_transient( Onboarding_Wizard::REDIRECT_TRANSIENT ) );
		$this->assertNotFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );
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
				throw new RedirectFired( 'redirect' );
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
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );
	}

	/**
	 * Fail the test if the value is a WP_Error.
	 *
	 * @param mixed $value Value to check.
	 */
	private function assertNotWPError( $value ): void {
		if ( is_wp_error( $value ) ) {
			$this->fail( 'Unexpected WP_Error: ' . $value->get_error_message() );
		}
		$this->assertFalse( is_wp_error( $value ) );
	}
}
