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
use ReflectionMethod;
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
		remove_all_filters( 'fosse_serve_atproto_did_well_known' );

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
	 * The well-known route helper ignores unrelated request paths.
	 */
	public function test_atproto_did_well_known_response_ignores_other_paths() {
		$this->assertNull( $this->get_atproto_did_well_known_response( '/about' ) );
	}

	/**
	 * The well-known route helper returns the connected DID as plain response data.
	 */
	public function test_atproto_did_well_known_response_returns_connected_did() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		$this->assertSame(
			array(
				'status' => 200,
				'did'    => 'did:plc:test123',
			),
			$this->get_atproto_did_well_known_response( '/.well-known/atproto-did?ignored=1' )
		);
	}

	/**
	 * A stored DID is not enough to serve the well-known route without a connection.
	 */
	public function test_atproto_did_well_known_response_requires_connected_atmosphere() {
		update_option(
			'atmosphere_connection',
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			)
		);

		$this->assertSame(
			array(
				'status' => 404,
				'did'    => '',
			),
			$this->get_atproto_did_well_known_response( '/.well-known/atproto-did' )
		);
	}

	/**
	 * The FOSSE opt-out filter prevents FOSSE from serving the well-known route.
	 */
	public function test_atproto_did_well_known_response_respects_opt_out_filter() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		add_filter( 'fosse_serve_atproto_did_well_known', '__return_false' );

		$this->assertNull( $this->get_atproto_did_well_known_response( '/.well-known/atproto-did' ) );
	}

	/**
	 * The suppression hook is a no-op for unrelated atmosphere_wellknown query vars.
	 */
	public function test_maybe_suppress_atmosphere_well_known_no_op_for_other_query_vars() {
		global $wp_query;
		$wp_query = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolating $wp_query state for the suppression hook.
		set_query_var( 'atmosphere_wellknown', 'publication' );
		add_filter( 'fosse_serve_atproto_did_well_known', '__return_false' );

		$this->provider->maybe_suppress_atmosphere_well_known();

		$this->assertSame( 'publication', get_query_var( 'atmosphere_wellknown' ) );
		$this->assertFalse( $wp_query->is_404() );
	}

	/**
	 * The suppression hook is a no-op when FOSSE will serve the route itself.
	 */
	public function test_maybe_suppress_atmosphere_well_known_no_op_when_filter_true() {
		global $wp_query;
		$wp_query = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolating $wp_query state for the suppression hook.
		set_query_var( 'atmosphere_wellknown', 'atproto-did' );

		$this->provider->maybe_suppress_atmosphere_well_known();

		$this->assertSame( 'atproto-did', get_query_var( 'atmosphere_wellknown' ) );
		$this->assertFalse( $wp_query->is_404() );
	}

	/**
	 * Opting out via filter clears Atmosphere's query var and forces a 404.
	 */
	public function test_maybe_suppress_atmosphere_well_known_clears_query_var_and_404s_when_opted_out() {
		global $wp_query;
		$wp_query = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolating $wp_query state for the suppression hook.
		set_query_var( 'atmosphere_wellknown', 'atproto-did' );
		add_filter( 'fosse_serve_atproto_did_well_known', '__return_false' );

		$this->provider->maybe_suppress_atmosphere_well_known();

		$this->assertSame( '', get_query_var( 'atmosphere_wellknown' ) );
		$this->assertTrue( $wp_query->is_404() );
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
				throw new \Exception( 'redirect' );
			}
		);

		try {
			$this->provider->handle_disconnect();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( array(), get_option( 'atmosphere_connection', array() ) );
		$this->assertNotEmpty( get_settings_errors( 'atmosphere' ) );
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
				throw new \Exception( 'redirect' );
			}
		);

		try {
			$this->provider->handle_connect();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors   = get_settings_errors( 'atmosphere' );
		$messages = array_column( $errors, 'message' );

		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( 'handle', strtolower( implode( ' ', $messages ) ) );
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
				throw new \Exception( 'redirect' );
			}
		);

		try {
			$this->provider->handle_connect();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
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
		$this->assertSame( 1, has_action( 'init', array( $this->provider, 'serve_atproto_did_well_known' ) ) );
		$this->assertSame( 1, has_action( 'template_redirect', array( $this->provider, 'maybe_suppress_atmosphere_well_known' ) ) );
		$this->assertNotFalse( has_filter( 'atmosphere_oauth_redirect_uri', array( $this->provider, 'filter_oauth_redirect_uri' ) ) );
	}

	/**
	 * Invoke the private well-known response helper via reflection.
	 *
	 * @param string $request_uri Request URI.
	 * @return array{status:int,did:string}|null
	 */
	private function get_atproto_did_well_known_response( string $request_uri ): ?array {
		$method = new ReflectionMethod( Bluesky_Provider::class, 'get_atproto_did_well_known_response' );
		return $method->invoke( $this->provider, $request_uri );
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
