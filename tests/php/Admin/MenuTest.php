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
	 * Notice-canary callbacks registered by the current test.
	 *
	 * Tracked by reference so `tear_down_state()` can remove only the
	 * callbacks this test added, instead of `remove_all_actions()` on
	 * the four core notice hooks (which would also drop legitimate
	 * WordPress core callbacks for the rest of the PHPUnit process).
	 *
	 * @var array<string, callable>
	 */
	private array $canary_callbacks = array();

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

		// Drop only the canary callbacks this test registered, leaving any
		// WordPress core hooks intact so later tests aren't order-dependent.
		foreach ( $this->canary_callbacks as $hook => $callback ) {
			remove_action( $hook, $callback );
		}
		$this->canary_callbacks = array();

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

	// --- hidden wizard title ---

	/**
	 * Hidden wizard pages still need a core admin title before admin-header.php.
	 */
	public function test_sets_wizard_admin_title_when_missing(): void {
		$old_title = $GLOBALS['title'] ?? null;

		try {
			$GLOBALS['title'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup for core title global.

			Menu::set_wizard_admin_title();

			$this->assertSame( 'Setup Wizard', $GLOBALS['title'] );
		} finally {
			$GLOBALS['title'] = $old_title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore core title global after test.
		}
	}

	/**
	 * Existing non-empty admin titles are preserved.
	 */
	public function test_preserves_existing_wizard_admin_title(): void {
		$old_title = $GLOBALS['title'] ?? null;

		try {
			$GLOBALS['title'] = 'Existing Title'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup for core title global.

			Menu::set_wizard_admin_title();

			$this->assertSame( 'Existing Title', $GLOBALS['title'] );
		} finally {
			$GLOBALS['title'] = $old_title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore core title global after test.
		}
	}

	// --- notice suppression (#56) ---

	/**
	 * Foreign admin notice callbacks are stripped on the FOSSE Setup screen.
	 *
	 * Covers the four core notice hooks WordPress fires during admin header
	 * rendering. The wizard / Setup page are focused flows; foreign notices
	 * (host banners, plugin upsells) break the orientation.
	 */
	public function test_suppresses_admin_notices_on_setup_screen(): void {
		$fired = array();
		$this->register_notice_canaries( $fired );

		Menu::maybe_suppress_admin_notices( $this->fake_screen( 'toplevel_page_fosse' ) );

		do_action( 'admin_notices' );
		do_action( 'all_admin_notices' );
		do_action( 'network_admin_notices' );
		do_action( 'user_admin_notices' );

		$this->assertSame( array(), $fired, 'Foreign notices should be suppressed on the Setup screen.' );
	}

	/**
	 * Same suppression on the Setup Wizard screen — the original incident
	 * (Jurassic Ninja credentials banner) was on the wizard, not Setup.
	 */
	public function test_suppresses_admin_notices_on_wizard_screen(): void {
		$fired = array();
		$this->register_notice_canaries( $fired );

		Menu::maybe_suppress_admin_notices( $this->fake_screen( 'admin_page_fosse-wizard' ) );

		do_action( 'admin_notices' );
		do_action( 'all_admin_notices' );
		do_action( 'network_admin_notices' );
		do_action( 'user_admin_notices' );

		$this->assertSame( array(), $fired, 'Foreign notices should be suppressed on the Wizard screen.' );
	}

	/**
	 * Suppression is scoped to Setup + Wizard. The Status page is a
	 * long-lived dashboard; foreign notices are more legitimate there
	 * and stripping them would risk masking real cross-plugin signal.
	 */
	public function test_does_not_suppress_admin_notices_on_status_screen(): void {
		$fired = array();
		$this->register_notice_canaries( $fired );

		Menu::maybe_suppress_admin_notices( $this->fake_screen( 'fosse_page_fosse-status' ) );

		do_action( 'admin_notices' );
		do_action( 'all_admin_notices' );
		do_action( 'network_admin_notices' );
		do_action( 'user_admin_notices' );

		$this->assertSame(
			array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ),
			$fired,
			'Status screen must preserve foreign notices.'
		);
	}

	/**
	 * Non-FOSSE screens (Dashboard, Plugins, etc.) are untouched. The
	 * suppression is opt-in: a stray Menu callback must not affect the
	 * rest of wp-admin.
	 */
	public function test_does_not_suppress_admin_notices_on_unrelated_screen(): void {
		$fired = array();
		$this->register_notice_canaries( $fired );

		Menu::maybe_suppress_admin_notices( $this->fake_screen( 'dashboard' ) );

		do_action( 'admin_notices' );

		$this->assertContains( 'admin_notices', $fired, 'Unrelated screens must keep their notices.' );
	}

	/**
	 * Late-stage suppression strips notices that other plugins added
	 * between `current_screen` and the actual notice hooks (e.g. via
	 * `admin_head` or `admin_enqueue_scripts`). Mirrors the
	 * `current_screen` test but exercises the `in_admin_header`-bound
	 * `maybe_suppress_admin_notices_late()` wrapper.
	 */
	public function test_late_suppression_strips_admin_head_added_notices(): void {
		set_current_screen( 'toplevel_page_fosse' );
		$current = get_current_screen();
		if ( $current instanceof \WP_Screen ) {
			$current->id = 'toplevel_page_fosse';
		}

		$fired = array();
		$this->register_notice_canaries( $fired );

		Menu::maybe_suppress_admin_notices_late();

		do_action( 'admin_notices' );
		do_action( 'all_admin_notices' );
		do_action( 'network_admin_notices' );
		do_action( 'user_admin_notices' );

		$this->assertSame( array(), $fired, 'Late-stage suppression must strip notices added after current_screen.' );
	}

	/**
	 * Late-stage suppression no-ops on unrelated screens — same scoping
	 * guarantees as the `current_screen`-bound stage.
	 */
	public function test_late_suppression_skips_unrelated_screen(): void {
		set_current_screen( 'dashboard' );
		$current = get_current_screen();
		if ( $current instanceof \WP_Screen ) {
			$current->id = 'dashboard';
		}

		$fired = array();
		$this->register_notice_canaries( $fired );

		Menu::maybe_suppress_admin_notices_late();

		do_action( 'admin_notices' );

		$this->assertContains( 'admin_notices', $fired, 'Late-stage suppression must not affect unrelated screens.' );
	}

	/**
	 * Register one canary callback per notice hook so the suppression
	 * tests can observe which hooks still fire.
	 *
	 * Each closure is captured on the test instance via
	 * {@see self::$canary_callbacks} so `tear_down_state()` can remove
	 * exactly these callbacks (and only these) — instead of nuking every
	 * registered handler on the four notice hooks, which would also drop
	 * legitimate core callbacks.
	 *
	 * @param array<int, string> $fired Bucket to populate, indexed by hook name.
	 * @return void
	 */
	private function register_notice_canaries( array &$fired ): void {
		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
			$callback                        = static function () use ( $hook, &$fired ): void {
				$fired[] = $hook;
			};
			$this->canary_callbacks[ $hook ] = $callback;
			add_action( $hook, $callback );
		}
	}

	/**
	 * Build a fresh WP_Screen object with the given id.
	 *
	 * `WP_Screen::get()` caches by source hook name, so a unique seed
	 * yields a brand-new object per call — preventing one test's id
	 * mutation from leaking into another. The suppression method only
	 * reads `$screen->id`, so the rest of the fields can stay as the
	 * factory default.
	 *
	 * @param string $id Screen id to assign.
	 * @return \WP_Screen
	 */
	private function fake_screen( string $id ): \WP_Screen {
		$screen     = \WP_Screen::get( 'fosse-test-' . uniqid( '', true ) );
		$screen->id = $id;
		return $screen;
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
