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

	/**
	 * Provider registers the expected hooks.
	 */
	public function test_register_hooks_adds_actions() {
		$this->provider->register_hooks();

		$this->assertNotFalse( has_action( 'admin_post_fosse_connect_bluesky', array( $this->provider, 'handle_connect' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_fosse_disconnect_bluesky', array( $this->provider, 'handle_disconnect' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $this->provider, 'handle_oauth_callback' ) ) );
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
}
