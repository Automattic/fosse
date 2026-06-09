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
use PHPUnit\Framework\Attributes\DataProvider;
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
	 * Whether $_SERVER held a REQUEST_METHOD key before the current test.
	 *
	 * @var bool
	 */
	private bool $had_request_method = false;

	/**
	 * The pre-test value of $_SERVER['REQUEST_METHOD'], if any.
	 *
	 * @var string|null
	 */
	private ?string $old_request_method = null;

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

		// Snapshot REQUEST_METHOD so the POST-guard tests can mutate it and
		// tear_down_state() can restore the original (or drop the key).
		$this->had_request_method = array_key_exists( 'REQUEST_METHOD', $_SERVER );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Snapshotting the raw value to restore it verbatim in teardown.
		$this->old_request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : null;

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

		// Restore REQUEST_METHOD: put the original value back if it existed,
		// otherwise drop the key so a POST-guard test can't leak into siblings.
		if ( $this->had_request_method ) {
			$_SERVER['REQUEST_METHOD'] = $this->old_request_method;
		} else {
			unset( $_SERVER['REQUEST_METHOD'] );
		}

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
	 * A form POST skips the redirect and preserves the option.
	 *
	 * The admin-post.php endpoint fires admin_init before dispatching its
	 * admin_post_* actions. Without this guard, a POST that reaches
	 * admin_init while the redirect option is set would be swallowed by the
	 * redirect+exit, silently dropping the submitted data. The option is
	 * preserved so a later GET admin request can still land on the wizard.
	 */
	public function test_skips_on_post_request_and_preserves_option(): void {
		$this->become_admin();
		update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->arm_redirect_trap();

		Menu::maybe_redirect_to_wizard();

		$this->assertFalse( $this->redirect_fired() );
		$this->assertNotFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );
	}

	/**
	 * A GET request with all other guards passing still redirects — confirms
	 * the POST guard is scoped to POST and doesn't suppress the normal path.
	 */
	public function test_redirects_on_get_request(): void {
		$this->become_admin();
		update_option( Onboarding_Wizard::REDIRECT_OPTION, 1, false );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->arm_redirect_trap();

		try {
			Menu::maybe_redirect_to_wizard();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( $this->redirect_fired() );
		$this->assertFalse( get_option( Onboarding_Wizard::REDIRECT_OPTION ) );
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
	 *
	 * Hooks `gettext` to confirm the title goes through `__()` with the
	 * `fosse` text domain — asserting the rendered string alone wouldn't
	 * catch a regression that dropped the wrapper, since wrapped and
	 * unwrapped paths produce identical output in a no-translation env.
	 */
	public function test_sets_wizard_admin_title_when_missing(): void {
		$had_title = array_key_exists( 'title', $GLOBALS );
		$old_title = $GLOBALS['title'] ?? null;

		$captured = array();
		$capture  = static function ( $translation, $text, $domain ) use ( &$captured ) {
			$captured[ $text ] = $domain;
			return $translation;
		};
		add_filter( 'gettext', $capture, 10, 3 );

		try {
			$GLOBALS['title'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup for core title global.

			Menu::set_wizard_admin_title();

			$this->assertSame( __( 'Setup Wizard', 'fosse' ), $GLOBALS['title'] );
			$this->assertSame(
				'fosse',
				$captured['Setup Wizard'] ?? null,
				'Wizard title must call __() with the fosse text domain.'
			);
		} finally {
			remove_filter( 'gettext', $capture, 10 );

			// `?? null` can't distinguish an absent key from a null value, so
			// only restore the original value when the key existed; otherwise
			// drop the key we just introduced to avoid leaking state into
			// later tests.
			if ( $had_title ) {
				$GLOBALS['title'] = $old_title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore core title global after test.
			} else {
				unset( $GLOBALS['title'] );
			}
		}
	}

	/**
	 * Existing non-empty admin titles are preserved.
	 */
	public function test_preserves_existing_wizard_admin_title(): void {
		$had_title = array_key_exists( 'title', $GLOBALS );
		$old_title = $GLOBALS['title'] ?? null;

		try {
			$GLOBALS['title'] = 'Existing Title'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup for core title global.

			Menu::set_wizard_admin_title();

			$this->assertSame( 'Existing Title', $GLOBALS['title'] );
		} finally {
			if ( $had_title ) {
				$GLOBALS['title'] = $old_title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore core title global after test.
			} else {
				unset( $GLOBALS['title'] );
			}
		}
	}

	// --- notice suppression (#56) ---

	/**
	 * Foreign admin notice callbacks are stripped on the FOSSE Setup
	 * Wizard screen — the original incident (Jurassic Ninja credentials
	 * banner) was on the wizard, and the wizard is a focused first-run
	 * flow where foreign notices break the orientation.
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
	 * The Settings page (`toplevel_page_fosse`) does NOT suppress notices
	 * — long-lived admin pages benefit from FOSSE's own
	 * `admin_notices`-hooked banners (e.g. the auto-publish recovery
	 * notice in Bluesky_Provider) and from legitimate cross-plugin
	 * signal in the user's normal admin flow.
	 */
	public function test_does_not_suppress_admin_notices_on_settings_screen(): void {
		$fired = array();
		$this->register_notice_canaries( $fired );

		Menu::maybe_suppress_admin_notices( $this->fake_screen( 'toplevel_page_fosse' ) );

		do_action( 'admin_notices' );
		do_action( 'all_admin_notices' );
		do_action( 'network_admin_notices' );
		do_action( 'user_admin_notices' );

		$this->assertSame(
			array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ),
			$fired,
			'Settings screen must preserve foreign notices so FOSSE-hooked banners can render.'
		);
	}

	/**
	 * Suppression is scoped to the Wizard. The Status page is a
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
	 * The lizard theme script must run from the document head so a stored
	 * theme can mark the root element before the wizard paints.
	 */
	public function test_lizard_theme_script_is_enqueued_in_head_on_wizard_screen(): void {
		wp_dequeue_script( 'fosse-wizard-lizard' );
		wp_deregister_script( 'fosse-wizard-lizard' );

		try {
			Menu::enqueue_assets( 'admin_page_fosse-wizard' );

			$this->assertTrue( wp_script_is( 'fosse-wizard-lizard', 'enqueued' ) );
			$this->assertFalse(
				wp_scripts()->get_data( 'fosse-wizard-lizard', 'group' ),
				'Lizard theme script must stay in the head to prevent a stored-theme flash.'
			);
			$this->assertFalse(
				wp_scripts()->get_data( 'fosse-wizard-lizard', 'strategy' ),
				'Lizard theme script must execute synchronously; defer/async would reintroduce the flash.'
			);
		} finally {
			wp_dequeue_script( 'fosse-wizard-lizard' );
			wp_deregister_script( 'fosse-wizard-lizard' );
		}
	}

	/**
	 * Late-stage suppression strips notices that other plugins added
	 * between `current_screen` and the actual notice hooks (e.g. via
	 * `admin_head` or `admin_enqueue_scripts`). Mirrors the
	 * `current_screen` test but exercises the `in_admin_header`-bound
	 * `maybe_suppress_admin_notices_late()` wrapper. Targets the wizard
	 * screen since that's the only screen the early stage suppresses
	 * — the late stage must agree.
	 */
	public function test_late_suppression_strips_admin_head_added_notices(): void {
		set_current_screen( 'admin_page_fosse-wizard' );
		$current = get_current_screen();
		if ( $current instanceof \WP_Screen ) {
			$current->id = 'admin_page_fosse-wizard';
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

	// --- enqueue_assets scope ---

	/**
	 * The admin CSS is enqueued on each of FOSSE's own admin hook suffixes.
	 *
	 * @param string $hook_suffix Hook suffix under test.
	 * @dataProvider provide_fosse_hook_suffixes
	 */
	#[DataProvider( 'provide_fosse_hook_suffixes' )]
	public function test_enqueues_admin_css_on_fosse_screens( string $hook_suffix ): void {
		wp_dequeue_style( 'fosse-admin' );
		wp_deregister_style( 'fosse-admin' );

		try {
			Menu::enqueue_assets( $hook_suffix );

			$this->assertTrue(
				wp_style_is( 'fosse-admin', 'enqueued' ),
				"Admin CSS should load on the {$hook_suffix} screen."
			);
		} finally {
			wp_dequeue_style( 'fosse-admin' );
			wp_deregister_style( 'fosse-admin' );
		}
	}

	/**
	 * The admin CSS is NOT enqueued on a third-party screen whose hook
	 * suffix merely shares FOSSE's prefix. Guards against the pre-fix
	 * `str_starts_with( $hook_suffix, 'toplevel_page_fosse' )` /
	 * `str_starts_with( $hook_suffix, 'fosse_page_' )` regression, which
	 * loaded FOSSE's CSS onto a foreign plugin's screen.
	 *
	 * @param string $hook_suffix Hook suffix under test.
	 * @dataProvider provide_non_fosse_hook_suffixes
	 */
	#[DataProvider( 'provide_non_fosse_hook_suffixes' )]
	public function test_does_not_enqueue_admin_css_on_foreign_screens( string $hook_suffix ): void {
		wp_dequeue_style( 'fosse-admin' );
		wp_deregister_style( 'fosse-admin' );

		try {
			Menu::enqueue_assets( $hook_suffix );

			$this->assertFalse(
				wp_style_is( 'fosse-admin', 'enqueued' ),
				"Admin CSS must not load on the foreign {$hook_suffix} screen."
			);
		} finally {
			wp_dequeue_style( 'fosse-admin' );
			wp_deregister_style( 'fosse-admin' );
		}
	}

	/**
	 * FOSSE's own admin hook suffixes (mirrors the screen IDs registered in
	 * {@see Menu::add_menu()}).
	 *
	 * @return iterable<string, array{0: string}>
	 */
	public static function provide_fosse_hook_suffixes(): iterable {
		yield 'settings (top-level)' => array( 'toplevel_page_fosse' );
		yield 'status (subpage)'     => array( 'fosse_page_fosse-status' );
		yield 'wizard (hidden)'      => array( 'admin_page_fosse-wizard' );
	}

	/**
	 * Lookalike hook suffixes that share FOSSE's prefix but belong to other
	 * plugins — these must not trigger the FOSSE asset enqueue.
	 *
	 * @return iterable<string, array{0: string}>
	 */
	public static function provide_non_fosse_hook_suffixes(): iterable {
		yield 'top-level prefix lookalike' => array( 'toplevel_page_fosse-companion' );
		yield 'subpage prefix lookalike'   => array( 'fosse_page_fosse-companion-status' );
		yield 'unrelated screen'           => array( 'toplevel_page_plugins' );
		yield 'empty suffix'               => array( '' );
	}

	// --- is_fosse_admin_screen ---

	/**
	 * The three FOSSE admin screen IDs match. Anchors the public helper
	 * against the menu registration so adding a new admin page without
	 * extending the helper would surface here.
	 *
	 * @param string $screen_id Screen id under test.
	 * @dataProvider provide_fosse_admin_screens
	 */
	#[DataProvider( 'provide_fosse_admin_screens' )]
	public function test_is_fosse_admin_screen_matches_known_screens( string $screen_id ): void {
		$this->assertTrue(
			Menu::is_fosse_admin_screen( $this->fake_screen( $screen_id ) ),
			"Screen id {$screen_id} should be recognized as a FOSSE admin screen."
		);
	}

	/**
	 * Strict whitelist: substrings that contain "fosse" but aren't one of
	 * the three registered FOSSE pages must not match. Guards against the
	 * pre-fix `strpos( $id, 'fosse' )` regression where any third-party
	 * plugin slug containing "fosse" would surface FOSSE-scoped notices.
	 *
	 * @param string $screen_id Screen id under test.
	 * @dataProvider provide_non_fosse_screens
	 */
	#[DataProvider( 'provide_non_fosse_screens' )]
	public function test_is_fosse_admin_screen_rejects_unrelated_screens( string $screen_id ): void {
		$this->assertFalse(
			Menu::is_fosse_admin_screen( $this->fake_screen( $screen_id ) ),
			"Screen id {$screen_id} must not be recognized as a FOSSE admin screen."
		);
	}

	/**
	 * Screen ids registered in {@see Menu::add_menu()} that the public
	 * helper must recognize as FOSSE-owned.
	 *
	 * @return iterable<string, array{0: string}>
	 */
	public static function provide_fosse_admin_screens(): iterable {
		yield 'settings (top-level)' => array( 'toplevel_page_fosse' );
		yield 'status (subpage)'     => array( 'fosse_page_fosse-status' );
		yield 'wizard (hidden)'      => array( 'admin_page_fosse-wizard' );
	}

	/**
	 * Lookalike and unrelated screen ids that must not match — anchors the
	 * strict whitelist against the substring-match regression.
	 *
	 * @return iterable<string, array{0: string}>
	 */
	public static function provide_non_fosse_screens(): iterable {
		yield 'dashboard'                          => array( 'dashboard' );
		yield 'plugins list'                       => array( 'plugins' );
		yield 'third-party with fosse in slug'     => array( 'toplevel_page_fossify' );
		yield 'third-party subpage with substring' => array( 'tools_page_fosse-clone' );
		yield 'empty id'                           => array( '' );
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
