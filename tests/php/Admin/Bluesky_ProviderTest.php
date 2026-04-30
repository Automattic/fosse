<?php
/**
 * Tests for Bluesky_Provider.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Atmosphere\OAuth\Encryption;
use Automattic\Fosse\Admin\Bluesky_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use Automattic\Fosse\Provider_Loader;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use WorDBless\BaseTestCase;

/**
 * Verifies Bluesky_Provider metadata, registration, status, and handlers.
 */
class Bluesky_ProviderTest extends BaseTestCase {

	/**
	 * Provider instance under test.
	 *
	 * @var Bluesky_Provider
	 */
	private Bluesky_Provider $provider;

	/**
	 * Clean state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_provider(): void {
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'fosse-test-auth-key' );
		}

		if ( ! defined( 'AUTH_SALT' ) ) {
			define( 'AUTH_SALT', 'fosse-test-auth-salt' );
		}

		$this->provider = new Bluesky_Provider();

		Connection_Provider_Registry::reset();
		delete_option( 'atmosphere_connection' );
		delete_option( 'atmosphere_auto_publish' );

		remove_all_filters( 'fosse_register_providers' );
		remove_all_filters( 'atmosphere_oauth_redirect_uri' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'admin_post_fosse_connect_bluesky' );
		remove_all_filters( 'admin_post_fosse_disconnect_bluesky' );
		remove_all_filters( 'admin_init' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_option_atmosphere_connection' );

		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- reset core settings-error storage for test isolation.
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_globals(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test cleanup.
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();

		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_option_atmosphere_connection' );
	}

	/**
	 * Install a wp_die handler that throws an exception instead of exiting.
	 *
	 * @return void
	 */
	private function install_wp_die_handler(): void {
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new \RuntimeException( wp_kses( $message, array() ) );
				};
			}
		);
	}

	/**
	 * Slug is 'bluesky'.
	 */
	public function test_slug() {
		$this->assertSame( 'bluesky', $this->provider->get_slug() );
	}

	/**
	 * Display name is 'Bluesky'.
	 */
	public function test_name() {
		$this->assertSame( 'Bluesky', $this->provider->get_name() );
	}

	/**
	 * Atmosphere is available in the bundled test environment.
	 */
	public function test_is_available() {
		$this->assertTrue( $this->provider->is_available() );
	}

	/**
	 * Provider self-registers through the loader.
	 */
	public function test_registers_through_provider_loader() {
		Bluesky_Provider::init();
		Provider_Loader::boot();

		$this->assertNotNull( Connection_Provider_Registry::get_provider( 'bluesky' ) );
	}

	/**
	 * Default status is disconnected with auto-publish enabled.
	 */
	public function test_status_disconnected_by_default() {
		$status = $this->provider->get_status();

		$this->assertFalse( $status['connected'] );
		$this->assertSame( '', $status['handle'] );
		$this->assertSame( '', $status['did'] );
		$this->assertSame( '', $status['pds_endpoint'] );
		$this->assertTrue( $status['auto_publish'] );
		$this->assertNull( $status['token_error'] );
	}

	/**
	 * Connected status reflects the Atmosphere connection option.
	 */
	public function test_status_connected_reflects_connection() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);
		update_option( 'atmosphere_auto_publish', '0' );

		$status = $this->provider->get_status();

		$this->assertTrue( $status['connected'] );
		$this->assertSame( 'alice.bsky.social', $status['handle'] );
		$this->assertSame( 'did:plc:test123', $status['did'] );
		$this->assertSame( 'https://bsky.social', $status['pds_endpoint'] );
		$this->assertFalse( $status['auto_publish'] );
		$this->assertNull( $status['token_error'] );
	}

	/**
	 * Corrupt tokens surface as token health errors without dropping connection state.
	 */
	public function test_status_reports_token_error() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => 'garbage',
			)
		);

		$status = $this->provider->get_status();

		$this->assertTrue( $status['connected'] );
		$this->assertNotNull( $status['token_error'] );
	}

	/**
	 * Disconnected setup UI renders the connect action.
	 */
	public function test_render_setup_section_disconnected_contains_connect_form() {
		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fosse_connect_bluesky', $output );
		$this->assertStringContainsString( 'bluesky_handle', $output );
	}

	/**
	 * Connected setup UI renders the disconnect action.
	 */
	public function test_render_setup_section_connected_contains_disconnect_form() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fosse_disconnect_bluesky', $output );
		$this->assertStringContainsString( 'alice.bsky.social', $output );
	}

	/**
	 * FOSSE-initiated OAuth should return to the FOSSE setup page.
	 */
	public function test_filter_oauth_redirect_uri_returns_fosse_page() {
		$this->assertSame(
			admin_url( 'admin.php?page=fosse' ),
			$this->provider->filter_oauth_redirect_uri( admin_url( 'options-general.php?page=atmosphere' ) )
		);
	}

	/**
	 * Disconnect clears the Atmosphere connection and redirects back to FOSSE.
	 */
	public function test_handle_disconnect_clears_connection_and_redirects() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'fosse_disconnect_bluesky' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_disconnect();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( array(), get_option( 'atmosphere_connection', array() ) );
		$this->assertNotEmpty( get_settings_errors( 'atmosphere' ) );
	}

	/**
	 * Disconnect clears any orphan wizard return-context transient so a
	 * subsequent connect attempt starts from a clean slate.
	 */
	public function test_handle_disconnect_clears_wizard_return_context() {
		$this->seed_connected_atmosphere_connection();

		$this->become_admin();

		$transient_key = 'fosse_bluesky_oauth_return_' . get_current_user_id();
		set_transient(
			$transient_key,
			array(
				'context' => 'wizard',
				'state'   => 'pending-state',
			),
			HOUR_IN_SECONDS
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'fosse_disconnect_bluesky' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_disconnect();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertFalse( get_transient( $transient_key ) );
	}

	/**
	 * Disconnect surfaces an error notice when the connection option fails
	 * to clear (e.g. a hostile filter pins it). The success notice would
	 * otherwise mislead the user into thinking the disconnect worked.
	 */
	public function test_handle_disconnect_reports_error_when_connection_persists() {
		$this->seed_connected_atmosphere_connection();

		// Pin the connection in place so Atmosphere's delete_option call
		// can't make is_connected() flip to false.
		$pinned = get_option( 'atmosphere_connection' );
		add_filter(
			'pre_option_atmosphere_connection',
			static function () use ( $pinned ) {
				return $pinned;
			}
		);

		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'fosse_disconnect_bluesky' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_disconnect();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors = get_settings_errors( 'atmosphere' );
		$types  = array_column( $errors, 'type' );

		$this->assertNotEmpty( $errors );
		$this->assertContains( 'error', $types );
		$this->assertNotContains( 'info', $types );
	}

	// --- handle_oauth_callback: success branches ---

	/**
	 * A fully successful OAuth round-trip (token exchange OK, connection
	 * persisted, sync_publication OK) emits the success notice and
	 * redirects back to the originating screen.
	 */
	public function test_handle_oauth_callback_success_emits_success_notice() {
		$this->become_admin();

		$state = 'state-success';
		$this->fake_atmosphere_oauth_environment( $state );
		$this->intercept_token_endpoint_success();
		$this->intercept_pds_create_record_success();

		set_transient(
			'fosse_bluesky_oauth_return_' . get_current_user_id(),
			array(
				'context' => 'wizard',
				'state'   => $state,
			),
			HOUR_IN_SECONDS
		);

		$captured = null;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array(
			'page'  => 'fosse',
			'code'  => 'auth-code',
			'state' => $state,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_oauth_callback();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'page=fosse-wizard', $captured );

		$errors = get_settings_errors( 'atmosphere' );
		$types  = array_column( $errors, 'type' );
		$this->assertContains( 'success', $types );
		$this->assertNotContains( 'error', $types );
		$this->assertNotContains( 'warning', $types );

		$this->assertTrue( \Atmosphere\is_connected() );
	}

	/**
	 * Token exchange succeeds but the connection option does not persist
	 * (e.g. DB write fails or a filter blocks it). The user must see an
	 * error notice rather than a misleading success banner.
	 */
	public function test_handle_oauth_callback_warns_when_connection_not_persisted() {
		$this->become_admin();

		$state = 'state-not-saved';
		$this->fake_atmosphere_oauth_environment( $state );
		$this->intercept_token_endpoint_success();

		// Force is_connected() to return false even though Atmosphere
		// reports success — simulate a lost option write.
		add_filter( 'pre_option_atmosphere_connection', static fn() => array() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array(
			'page'  => 'fosse',
			'code'  => 'auth-code',
			'state' => $state,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_oauth_callback();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors   = get_settings_errors( 'atmosphere' );
		$types    = array_column( $errors, 'type' );
		$messages = array_column( $errors, 'message' );

		$this->assertContains( 'error', $types );
		$this->assertNotContains( 'success', $types );
		$this->assertStringContainsString( 'not saved', strtolower( implode( ' ', $messages ) ) );
	}

	/**
	 * Token exchange succeeds and the connection persists, but the PDS
	 * publication record write fails. The user should see a `warning`
	 * notice (connected, but publication setup failed) rather than a
	 * green success banner.
	 */
	public function test_handle_oauth_callback_warns_when_sync_publication_fails() {
		$this->become_admin();

		$state = 'state-sync-error';
		$this->fake_atmosphere_oauth_environment( $state );
		$this->intercept_token_endpoint_success();
		$this->intercept_pds_create_record_failure();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array(
			'page'  => 'fosse',
			'code'  => 'auth-code',
			'state' => $state,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_oauth_callback();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors = get_settings_errors( 'atmosphere' );
		$types  = array_column( $errors, 'type' );

		$this->assertContains( 'warning', $types );
		$this->assertNotContains( 'success', $types );
	}

	/**
	 * Callback URLs missing exactly one of code/state surface an error notice
	 * instead of silently rendering an empty page that the user can't act on.
	 */
	public function test_handle_oauth_callback_partial_params_show_error() {
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array(
			'page' => 'fosse',
			'code' => 'auth-code-only',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_oauth_callback();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors = get_settings_errors( 'atmosphere' );
		$types  = array_column( $errors, 'type' );

		$this->assertContains( 'error', $types );
	}

	/**
	 * Callback URLs with neither code nor state are normal admin page hits
	 * and must remain a no-op (no redirect, no notice).
	 */
	public function test_handle_oauth_callback_no_params_is_silent() {
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array( 'page' => 'fosse' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->provider->handle_oauth_callback();

		$this->assertEmpty( get_settings_errors( 'atmosphere' ) );
	}

	// --- handle validation ---

	/**
	 * Malformed handles are rejected with an actionable error notice
	 * before any HTTP call to Atmosphere\OAuth\Client::authorize().
	 *
	 * @dataProvider invalid_handle_provider
	 *
	 * @param string $raw_handle Raw user input.
	 */
	#[DataProvider( 'invalid_handle_provider' )]
	public function test_handle_connect_rejects_malformed_handle( string $raw_handle ) {
		$this->become_admin();

		$network_called = false;
		add_filter(
			'pre_http_request',
			static function () use ( &$network_called ) {
				$network_called = true;
				return new \WP_Error( 'fosse_test', 'should not be called' );
			}
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce'       => wp_create_nonce( 'fosse_connect_bluesky' ),
			'bluesky_handle' => $raw_handle,
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_connect();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertFalse( $network_called, 'Authorize() must not run for malformed handles.' );

		$errors   = get_settings_errors( 'atmosphere' );
		$messages = array_column( $errors, 'message' );

		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( 'handle', strtolower( implode( ' ', $messages ) ) );
	}

	/**
	 * Data provider for malformed handles.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_handle_provider(): array {
		return array(
			'no dot, single label' => array( 'alice' ),
			'leading dot'          => array( '.bsky.social' ),
			'trailing dot'         => array( 'alice.bsky.social.' ),
			'space inside'         => array( 'alice bsky.social' ),
			'underscore'           => array( 'al_ice.bsky.social' ),
			'mastodon style'       => array( '@alice@host.example' ),
			'leading hyphen label' => array( '-alice.bsky.social' ),
		);
	}

	/**
	 * Empty-handle connect requests fail early and redirect with an error.
	 */
	public function test_handle_connect_rejects_empty_handle() {
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce'       => wp_create_nonce( 'fosse_connect_bluesky' ),
			'bluesky_handle' => '',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_connect();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors   = get_settings_errors( 'atmosphere' );
		$messages = array_column( $errors, 'message' );

		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( 'handle', strtolower( implode( ' ', $messages ) ) );
	}

	/**
	 * Setup-origin connect requests clear stale wizard OAuth return context.
	 */
	public function test_handle_connect_from_setup_clears_stale_wizard_return_context() {
		$this->become_admin();

		$return_key = 'fosse_bluesky_oauth_return_' . get_current_user_id();
		set_transient( $return_key, 'wizard', HOUR_IN_SECONDS );

		$captured = null;

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce'       => wp_create_nonce( 'fosse_connect_bluesky' ),
			'bluesky_handle' => '',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_connect();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'page=fosse', $captured );
		$this->assertStringNotContainsString( 'page=fosse-wizard', $captured );
		$this->assertFalse( get_transient( $return_key ) );
	}

	/**
	 * Empty-handle connect requests from the wizard redirect back to the Bluesky step.
	 */
	public function test_handle_connect_rejects_empty_handle_from_wizard() {
		$this->become_admin();

		$captured = null;

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce'             => wp_create_nonce( 'fosse_connect_bluesky' ),
			'bluesky_handle'       => '',
			'fosse_bluesky_return' => 'wizard',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_connect();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'page=fosse-wizard', $captured );
		$this->assertStringContainsString( 'step=bluesky', $captured );
		$this->assertStringContainsString( 'settings-updated=true', $captured );
	}

	/**
	 * Callback handling is a no-op when the request is not for the FOSSE page.
	 */
	public function test_handle_oauth_callback_ignores_non_fosse_page() {
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array(
			'page'  => 'atmosphere',
			'code'  => 'abc',
			'state' => 'def',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->provider->handle_oauth_callback();

		$this->assertEmpty( get_settings_errors( 'atmosphere' ) );
	}

	/**
	 * OAuth callbacks from wizard-origin flows redirect back to the Bluesky step
	 * when the inbound state matches the state the wizard marker was bound to.
	 */
	public function test_handle_oauth_callback_returns_to_wizard_when_context_was_remembered() {
		$this->become_admin();

		set_transient(
			'fosse_bluesky_oauth_return_' . get_current_user_id(),
			array(
				'context' => 'wizard',
				'state'   => 'def',
			),
			HOUR_IN_SECONDS
		);

		$captured = null;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array(
			'page'  => 'fosse',
			'code'  => 'abc',
			'state' => 'def',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_oauth_callback();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'page=fosse-wizard', $captured );
		$this->assertStringContainsString( 'step=bluesky', $captured );
		$this->assertStringContainsString( 'settings-updated=true', $captured );
		$this->assertFalse( get_transient( 'fosse_bluesky_oauth_return_' . get_current_user_id() ) );
	}

	/**
	 * A callback whose state does not match the wizard marker leaves the
	 * marker in place and falls back to the default (Setup-page) redirect,
	 * so a legitimate callback that arrives later can still recover it.
	 */
	public function test_handle_oauth_callback_with_mismatched_state_preserves_wizard_marker() {
		$this->become_admin();

		$transient_key = 'fosse_bluesky_oauth_return_' . get_current_user_id();
		set_transient(
			$transient_key,
			array(
				'context' => 'wizard',
				'state'   => 'real-state',
			),
			HOUR_IN_SECONDS
		);

		$captured = null;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array(
			'page'  => 'fosse',
			'code'  => 'abc',
			'state' => 'stale-state',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_oauth_callback();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'page=fosse', $captured );
		$this->assertStringNotContainsString( 'page=fosse-wizard', $captured );

		$stored = get_transient( $transient_key );
		$this->assertIsArray( $stored );
		$this->assertSame( 'wizard', $stored['context'] );
		$this->assertSame( 'real-state', $stored['state'] );
	}

	/**
	 * Legacy single-string transients (pre state-binding) are not honored —
	 * an upgraded site with a stale legacy marker falls back to the default
	 * redirect rather than mistaking a string for a valid bound context.
	 */
	public function test_handle_oauth_callback_ignores_legacy_string_transient() {
		$this->become_admin();

		// Pre-state-binding transients stored just the literal context string.
		set_transient( 'fosse_bluesky_oauth_return_' . get_current_user_id(), 'wizard', HOUR_IN_SECONDS );

		$captured = null;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array(
			'page'  => 'fosse',
			'code'  => 'abc',
			'state' => 'def',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_oauth_callback();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringNotContainsString( 'page=fosse-wizard', $captured );
	}

	// --- unauthorized user tests ---

	/**
	 * Connect rejects non-admin users.
	 */
	public function test_handle_connect_rejects_subscriber() {
		$this->become_subscriber();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce'       => wp_create_nonce( 'fosse_connect_bluesky' ),
			'bluesky_handle' => 'alice.bsky.social',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->install_wp_die_handler();
		$this->expectException( \RuntimeException::class );
		$this->provider->handle_connect();
	}

	/**
	 * Disconnect rejects non-admin users.
	 */
	public function test_handle_disconnect_rejects_subscriber() {
		$this->become_subscriber();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'fosse_disconnect_bluesky' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->install_wp_die_handler();
		$this->expectException( \RuntimeException::class );
		$this->provider->handle_disconnect();
	}

	/**
	 * OAuth callback rejects non-admin users with wp_die.
	 */
	public function test_handle_oauth_callback_rejects_subscriber() {
		$this->become_subscriber();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array(
			'page'  => 'fosse',
			'code'  => 'abc',
			'state' => 'def',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->install_wp_die_handler();
		$this->expectException( \RuntimeException::class );
		$this->provider->handle_oauth_callback();
	}

	// --- nonce tests ---

	/**
	 * Connect rejects requests with missing or invalid nonce.
	 */
	public function test_handle_connect_rejects_bad_nonce() {
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce'       => 'invalid_nonce_value',
			'bluesky_handle' => 'alice.bsky.social',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->install_wp_die_handler();
		$this->expectException( \RuntimeException::class );
		$this->provider->handle_connect();
	}

	/**
	 * Disconnect rejects requests with missing or invalid nonce.
	 */
	public function test_handle_disconnect_rejects_bad_nonce() {
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => 'invalid_nonce_value',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->install_wp_die_handler();
		$this->expectException( \RuntimeException::class );
		$this->provider->handle_disconnect();
	}

	// --- handle normalization ---

	/**
	 * Leading @ is stripped from the submitted handle before authorize.
	 */
	public function test_handle_connect_strips_leading_at() {
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce'       => wp_create_nonce( 'fosse_connect_bluesky' ),
			'bluesky_handle' => '@alice.bsky.social',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Intercept the HTTP request that Client::authorize() makes to
		// the handle's auth server, and capture the handle it resolved.
		$captured_handle = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured_handle ) {
				// The first HTTP call from authorize() is handle resolution.
				// Capture the URL to verify no leading @ was passed.
				$captured_handle = $url;
				// Return an error to short-circuit the flow.
				return new \WP_Error( 'fosse_test_intercept', 'intercepted' );
			},
			10,
			3
		);

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_connect();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		// Verify pre_http_request fired (authorize() made an HTTP call).
		$this->assertNotNull(
			$captured_handle,
			'Expected pre_http_request to fire from Client::authorize() — handle normalization could not be verified.'
		);
		$this->assertStringNotContainsString( '@alice', $captured_handle );
	}

	/**
	 * Provider registers the expected hooks.
	 */
	public function test_register_hooks_adds_actions() {
		$this->provider->register_hooks();

		$this->assertNotFalse( has_action( 'admin_post_fosse_connect_bluesky', array( $this->provider, 'handle_connect' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_fosse_disconnect_bluesky', array( $this->provider, 'handle_disconnect' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $this->provider, 'handle_oauth_callback' ) ) );
		$this->assertNotFalse( has_filter( 'atmosphere_oauth_redirect_uri', array( $this->provider, 'filter_oauth_redirect_uri' ) ) );
	}

	/**
	 * Seed a connected Atmosphere connection (handle, did, encrypted token).
	 *
	 * @return void
	 */
	private function seed_connected_atmosphere_connection(): void {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);
	}

	/**
	 * Seed the OAuth transients Atmosphere\OAuth\Client::handle_callback()
	 * expects to find when validating an inbound callback.
	 *
	 * @param string $state          Stored OAuth state value.
	 * @param string $token_endpoint Token endpoint URL the test will intercept.
	 * @return void
	 */
	private function fake_atmosphere_oauth_environment(
		string $state,
		string $token_endpoint = 'https://example.test/oauth/token'
	): void {
		set_transient( 'atmosphere_oauth_state', $state, HOUR_IN_SECONDS );
		set_transient( 'atmosphere_oauth_verifier', 'test-verifier-' . uniqid( '', true ), HOUR_IN_SECONDS );
		set_transient( 'atmosphere_oauth_dpop_jwk', \Atmosphere\OAuth\DPoP::generate_key(), HOUR_IN_SECONDS );
		set_transient(
			'atmosphere_oauth_resolved',
			array(
				'did'          => 'did:plc:test123',
				'pds_endpoint' => 'https://bsky.social',
				'auth_server'  => array(
					'token_endpoint' => $token_endpoint,
					'issuer_url'     => 'https://example.test',
				),
				'handle'       => 'alice.bsky.social',
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Intercept the OAuth token endpoint and return a 200 with valid token data.
	 *
	 * @param string $token_endpoint Token endpoint URL to match.
	 * @return void
	 */
	private function intercept_token_endpoint_success( string $token_endpoint = 'https://example.test/oauth/token' ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $token_endpoint ) {
				if ( $url !== $token_endpoint ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( // phpcs:ignore Jetpack.Functions.JsonEncodeFlags.Missing -- test fixture, no escaping concerns.
						array(
							'access_token'  => 'access-tk',
							'refresh_token' => 'refresh-tk',
							'expires_in'    => 3600,
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Intercept PDS createRecord/putRecord requests with a successful response,
	 * so Publisher::sync_publication() returns array (not WP_Error).
	 *
	 * @return void
	 */
	private function intercept_pds_create_record_success(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false === strpos( $url, '/xrpc/com.atproto.repo.' ) ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( // phpcs:ignore Jetpack.Functions.JsonEncodeFlags.Missing -- test fixture, no escaping concerns.
						array(
							'uri' => 'at://did:plc:test123/site.standard.publication/self',
							'cid' => 'bafyrei-test-cid',
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Intercept PDS createRecord/putRecord requests with a 500 response,
	 * so Publisher::sync_publication() returns WP_Error.
	 *
	 * @return void
	 */
	private function intercept_pds_create_record_failure(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false === strpos( $url, '/xrpc/com.atproto.repo.' ) ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => wp_json_encode( // phpcs:ignore Jetpack.Functions.JsonEncodeFlags.Missing -- test fixture, no escaping concerns.
						array(
							'error'   => 'InternalServerError',
							'message' => 'PDS unavailable',
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Create and authenticate an administrator for handler tests.
	 *
	 * @return void
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

	/**
	 * Create and authenticate a subscriber (non-admin) for rejection tests.
	 *
	 * @return void
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
}
