<?php
/**
 * Tests for Onboarding_Wizard.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\AP_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use Automattic\Fosse\Admin\Onboarding_Wizard;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use WorDBless\BaseTestCase;

/**
 * Verifies wizard completion tracking, form handling, and skip/reset flows.
 */
class Onboarding_WizardTest extends BaseTestCase {

	/**
	 * Clean option and registry state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		delete_option( Onboarding_Wizard::COMPLETED_OPTION );
		delete_option( 'activitypub_actor_mode' );
		delete_option( 'activitypub_support_post_types' );
		delete_option( Onboarding_Wizard::REDIRECT_OPTION );
		delete_transient( Onboarding_Wizard::REDIRECT_TRANSIENT );

		// Most tests assume an ActivityPub provider is registered. Tests
		// that need the unavailable path call Connection_Provider_Registry::reset()
		// explicitly; the @after restores the provider for the next test.
		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
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
		remove_all_actions( 'fosse_wizard_unauthorized' );

		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
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
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
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
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
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
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Saving appearance fires AP's native update_option_* hook so
	 * downstream subscribers (the federation scheduler) propagate the
	 * mode change. Pre-seeding the option ensures the second write
	 * fires update_option_* (rather than add_option_*).
	 */
	public function test_handle_save_appearance_fires_native_update_hook() {
		update_option( 'activitypub_actor_mode', 'actor' );

		$fired = false;
		add_action(
			'update_option_activitypub_actor_mode',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$this->simulate_save_request( 'appearance', array( 'activitypub_actor_mode' => 'blog' ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( $fired, 'update_option_activitypub_actor_mode should fire on value change.' );
	}

	// --- handle_save: content step ---

	/**
	 * Saving the content step stores post types.
	 */
	public function test_handle_save_content_stores_post_types() {
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array( 'post', 'page' ) ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
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
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
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
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
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
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
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
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertFalse( Onboarding_Wizard::is_complete() );
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
				throw new RedirectFired( 'redirect' );
			},
			9
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'step=content', $captured );
		$this->assertStringContainsString( 'error=empty_post_types', $captured );
		$this->assertSame( array( 'post' ), get_option( 'activitypub_support_post_types' ) );
	}

	// --- audit hook: render capability failure ---

	/**
	 * Render fires the audit hook (and dies) for a non-admin user.
	 */
	public function test_render_unauthorized_fires_audit(): void {
		$this->become_subscriber();
		$captured = array();
		$this->hook_audit_capture( $captured );
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::render();
		} finally {
			$this->assertCount( 1, $captured );
			$this->assertSame( 'fosse_wizard_render', $captured[0][0] );
			$this->assertSame( 'capability', $captured[0][2] );
		}
	}

	// --- degraded render ---

	/**
	 * Render shows a clear notice when no AP provider is registered,
	 * instead of trying to render steps that depend on AP actor data.
	 */
	public function test_render_shows_degraded_notice_when_activitypub_unavailable(): void {
		$this->become_admin();
		Connection_Provider_Registry::reset();

		ob_start();
		try {
			Onboarding_Wizard::render();
		} finally {
			$output = ob_get_clean();
		}

		$this->assertStringContainsString( 'Setup is unavailable', $output );
		$this->assertStringContainsString( 'ActivityPub', $output );
	}

	// --- audit hook: handler cap/nonce failures (parameterized) ---

	/**
	 * Each handler fires fosse_wizard_unauthorized before wp_die() on
	 * capability or nonce failure, and preserves handler-specific state.
	 *
	 * @dataProvider unauthorized_handler_provider
	 *
	 * @param string $handler      Handler method name.
	 * @param string $action       Wizard action passed to the audit hook.
	 * @param string $nonce_action Nonce action used by the handler.
	 * @param string $reason       'capability' or 'nonce'.
	 */
	#[DataProvider( 'unauthorized_handler_provider' )]
	public function test_unauthorized_handler_fires_audit_and_dies(
		string $handler,
		string $action,
		string $nonce_action,
		string $reason
	): void {
		// Reset preserves: handle_reset preserves the completed flag,
		// so seed it before the request runs.
		if ( 'handle_reset' === $handler ) {
			Onboarding_Wizard::mark_complete();
		}

		if ( 'capability' === $reason ) {
			$this->become_subscriber();
		} else {
			$this->become_admin();
		}

		$nonce_value = 'capability' === $reason
			? wp_create_nonce( $nonce_action )
			: 'invalid';

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$post = array(
			'action'   => $action,
			'_wpnonce' => $nonce_value,
		);
		if ( 'handle_save' === $handler ) {
			$post['fosse_wizard_step']      = 'appearance';
			$post['activitypub_actor_mode'] = 'blog';
		}
		$_POST    = $post;
		$_REQUEST = $post;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$captured = array();
		$this->hook_audit_capture( $captured );
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::$handler();
		} finally {
			$this->assertCount( 1, $captured, 'audit hook should fire exactly once' );
			$this->assertSame( $action, $captured[0][0] );
			$this->assertSame( $reason, $captured[0][2] );

			switch ( $handler ) {
				case 'handle_save':
					$this->assertFalse( get_option( 'activitypub_actor_mode' ) );
					break;
				case 'handle_skip':
				case 'handle_complete':
					$this->assertFalse( Onboarding_Wizard::is_complete() );
					break;
				case 'handle_reset':
					$this->assertTrue( Onboarding_Wizard::is_complete() );
					break;
			}
		}
	}

	/**
	 * Data provider for the unauthorized-handler test.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function unauthorized_handler_provider(): array {
		return array(
			'save: capability'     => array( 'handle_save', 'fosse_wizard_save', 'fosse_wizard', 'capability' ),
			'save: nonce'          => array( 'handle_save', 'fosse_wizard_save', 'fosse_wizard', 'nonce' ),
			'skip: capability'     => array( 'handle_skip', 'fosse_wizard_skip', 'fosse_wizard_skip', 'capability' ),
			'skip: nonce'          => array( 'handle_skip', 'fosse_wizard_skip', 'fosse_wizard_skip', 'nonce' ),
			'complete: capability' => array( 'handle_complete', 'fosse_wizard_complete', 'fosse_wizard_complete', 'capability' ),
			'complete: nonce'      => array( 'handle_complete', 'fosse_wizard_complete', 'fosse_wizard_complete', 'nonce' ),
			'reset: capability'    => array( 'handle_reset', 'fosse_wizard_reset', 'fosse_wizard_reset', 'capability' ),
			'reset: nonce'         => array( 'handle_reset', 'fosse_wizard_reset', 'fosse_wizard_reset', 'nonce' ),
		);
	}

	// --- normalize_handle_preview ---

	/**
	 * Empty input returns empty string.
	 */
	public function test_normalize_handle_preview_blank_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( '' ) );
	}

	/**
	 * Single `@` (no local-part, no domain) returns empty string.
	 */
	public function test_normalize_handle_preview_at_only_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( '@' ) );
	}

	/**
	 * `@host` (missing local-part) returns empty string.
	 */
	public function test_normalize_handle_preview_at_host_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( '@host.example' ) );
	}

	/**
	 * `user@` (missing domain) returns empty string.
	 */
	public function test_normalize_handle_preview_user_at_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( 'user@' ) );
	}

	/**
	 * Plain `host` (no `@`) returns empty string.
	 */
	public function test_normalize_handle_preview_no_at_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( 'host.example' ) );
	}

	/**
	 * Multiple `@`s past the leading one (malformed) return empty string.
	 */
	public function test_normalize_handle_preview_multi_at_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( 'user@host@extra' ) );
	}

	/**
	 * Bare `user@host` is normalized to `@user@host`.
	 */
	public function test_normalize_handle_preview_user_host_prepends_at(): void {
		$this->assertSame( '@user@host.example', $this->call_normalize( 'user@host.example' ) );
	}

	/**
	 * `@user@host` keeps the leading `@` and is returned unchanged.
	 */
	public function test_normalize_handle_preview_at_user_host_passes_through(): void {
		$this->assertSame( '@user@host.example', $this->call_normalize( '@user@host.example' ) );
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
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );
	}

	/**
	 * Authenticate as an administrator.
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
	 * Hook fosse_wizard_unauthorized and append the firing args to the
	 * caller's array each time it fires. Pass the bucket by reference
	 * so the caller can inspect it after the request runs.
	 *
	 * @param array<int, array<int, mixed>> $captured Bucket to populate.
	 */
	private function hook_audit_capture( array &$captured ): void {
		add_action(
			'fosse_wizard_unauthorized',
			static function () use ( &$captured ): void {
				$captured[] = func_get_args();
			},
			10,
			3
		);
	}

	/**
	 * Invoke the private normalize_handle_preview() helper via reflection.
	 *
	 * @param string $handle Raw handle.
	 * @return string
	 */
	private function call_normalize( string $handle ): string {
		$method = new ReflectionMethod( Onboarding_Wizard::class, 'normalize_handle_preview' );
		return (string) $method->invoke( null, $handle );
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
		$this->assertNotWPError( $user_id );
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
				throw new RedirectFired( 'redirect' );
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
		$this->assertNotWPError( $user_id );
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
				throw new RedirectFired( 'redirect' );
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
		$this->assertNotWPError( $user_id );
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
				throw new RedirectFired( 'redirect' );
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
		$this->assertNotWPError( $user_id );
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
				throw new RedirectFired( 'redirect' );
			}
		);
	}
}
