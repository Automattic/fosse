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
		remove_all_filters( 'status_header' );
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
	 * The settings section is empty while disconnected — no auto-publish
	 * toggle, no connect form. Connect/disconnect actions render via
	 * `render_connection_actions()` outside the unified Settings form.
	 */
	public function test_render_setup_section_disconnected_is_empty() {
		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertSame( '', trim( $output ) );
		$this->assertStringNotContainsString( 'fosse_connect_bluesky', $output );
		$this->assertStringNotContainsString( 'fosse_disconnect_bluesky', $output );
	}

	/**
	 * Connected settings section exposes the auto-publish toggle as a
	 * fields-only fragment (no opening `<form>` tag) so it can sit inside
	 * the unified Settings form alongside General + AP fields.
	 */
	public function test_render_setup_section_connected_renders_auto_publish_toggle() {
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

		$this->assertStringContainsString( 'class="fosse-settings-section"', $output );
		$this->assertStringContainsString( '<h3>Bluesky publishing</h3>', $output );
		$this->assertStringContainsString( 'name="atmosphere_auto_publish"', $output );
		$this->assertStringContainsString( 'Auto-publish', $output );
		$this->assertStringNotContainsString( '<form', $output );
		$this->assertStringNotContainsString( 'fosse_connect_bluesky', $output );
		$this->assertStringNotContainsString( 'fosse_disconnect_bluesky', $output );
	}

	/**
	 * Auto-publish checkbox reflects the stored option. With Atmosphere's
	 * default ('1' = enabled), an unset option still renders the box checked.
	 */
	public function test_render_setup_section_auto_publish_checked_by_default() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);
		delete_option( 'atmosphere_auto_publish' );

		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		// WordPress's `checked()` may emit `checked='checked'` or a bare
		// `checked` attribute depending on core version; match either form
		// so the assertion stays stable across the supported floor.
		$this->assertMatchesRegularExpression(
			'~<input[^>]+name="atmosphere_auto_publish"[^>]+(checked(?:=\'checked\'|="checked")?)~',
			$output
		);
	}

	/**
	 * When auto-publish is explicitly disabled ('0'), the checkbox renders
	 * unchecked.
	 */
	public function test_render_setup_section_auto_publish_unchecked_when_disabled() {
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

		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertDoesNotMatchRegularExpression(
			'~<input[^>]+name="atmosphere_auto_publish"[^>]+(checked(?:=\'checked\'|="checked")?)~',
			$output
		);
	}

	/**
	 * Connection panel carries the fragment target id (`fosse-provider-bluesky`)
	 * referenced from the Status page reconnect link. Renaming the id without
	 * updating that link would silently break navigation.
	 */
	public function test_render_connection_actions_has_anchor_id() {
		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="fosse-provider-bluesky"', $output );
		$this->assertStringContainsString( 'class="fosse-connection-section"', $output );
		$this->assertStringContainsString( '<h3>Bluesky</h3>', $output );
	}

	/**
	 * Disconnected connection panel renders the connect action.
	 */
	public function test_render_connection_actions_disconnected_contains_connect_form() {
		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fosse_connect_bluesky', $output );
		$this->assertStringContainsString( 'bluesky_handle', $output );
	}

	/**
	 * Connected connection panel renders the disconnect action and exposes
	 * the connection details (handle, DID, PDS, token health).
	 */
	public function test_render_connection_actions_connected_contains_disconnect_form() {
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
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fosse_disconnect_bluesky', $output );
		$this->assertStringContainsString( 'alice.bsky.social', $output );
	}

	/**
	 * Disconnected connection panel links out to bsky.app so users without
	 * an account aren't dead-ended at the connect form.
	 */
	public function test_render_connection_actions_disconnected_links_to_bluesky_signup() {
		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fosse-bluesky-signup', $output );
		$this->assertStringContainsString( 'https://bsky.app/', $output );
		$this->assertStringContainsString( 'Need a Bluesky account', $output );
	}

	/**
	 * Connected connection panel omits the sign-up affordance — the user
	 * already has an account, so prompting to create one is noise.
	 */
	public function test_render_connection_actions_connected_omits_signup_link() {
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
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'fosse-bluesky-signup', $output );
		$this->assertStringNotContainsString( 'https://bsky.app/', $output );
	}

	/**
	 * Status card surfaces the reconnect UI and a details element with the
	 * raw error when the stored access token can't be decrypted.
	 */
	public function test_render_status_card_token_error_shows_reconnect_ui() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => 'garbage',
			)
		);

		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Reconnect required', $output );
		$this->assertStringContainsString( '#fosse-provider-bluesky', $output );
		$this->assertStringContainsString( '<details', $output );
	}

	/**
	 * Status card reports OK token health and omits the reconnect UI when
	 * the connection is healthy.
	 */
	public function test_render_status_card_ok_state_omits_reconnect_ui() {
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
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<td[^>]*>\s*OK\s*<\/td>/', $output );
		$this->assertStringNotContainsString( 'Reconnect required', $output );
		$this->assertStringNotContainsString( '<details', $output );
	}

	/**
	 * Status card no longer carries a "Manage Bluesky settings" deep link
	 * (issue #74) — the sidebar Settings menu entry replaces those per-card
	 * navigation affordances.
	 */
	public function test_render_status_card_omits_manage_settings_link() {
		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Manage Bluesky settings', $output );
		$this->assertStringNotContainsString( 'fosse-status-card__manage', $output );
	}

	/**
	 * Status card renders DID, handle, and PDS as token elements with
	 * `<wbr>` break opportunities so a long identifier doesn't overflow
	 * the card. Each token type carries its own BEM modifier so the CSS
	 * can scope `overflow-wrap` to just DIDs and URLs.
	 */
	public function test_render_status_card_token_markup_for_long_identifiers() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:longidentifierthatwouldotherwiseoverflow',
				'handle'       => 'someone.with.a.very.long.subdomain.example.org',
				'pds_endpoint' => 'https://very-long-pds-host.example.com/some/deep/path',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fosse-status-card__table', $output );
		$this->assertStringContainsString( 'fosse-status-card__label', $output );
		$this->assertStringContainsString( 'fosse-status-card__value', $output );

		$this->assertStringContainsString( 'fosse-status-card__token--did', $output );
		$this->assertStringContainsString( 'fosse-status-card__token--handle', $output );
		$this->assertStringContainsString( 'fosse-status-card__token--url', $output );

		// DID renders with <wbr> after each `:` separator.
		$this->assertStringContainsString( 'did:<wbr>plc:<wbr>longidentifier', $output );

		// PDS URL renders with <wbr> after the scheme and before each `/`.
		$this->assertStringContainsString( 'https://<wbr>very-long-pds-host.example.com<wbr>/some', $output );

		// Handle renders with <wbr> after each `.`.
		$this->assertStringContainsString( 'someone.<wbr>with.<wbr>a.<wbr>very.<wbr>long.<wbr>subdomain.<wbr>example.<wbr>org', $output );

		// Sanity: the raw un-tokenized value must NOT appear anywhere in the
		// output. The `<wbr>` markers above prove the formatter ran; this
		// extra pair guards against a future refactor that emits both the
		// tokenized form AND the bare value (e.g. as a `title=` attribute or
		// a sibling fallback).
		$this->assertStringNotContainsString( 'did:plc:longidentifierthatwouldotherwiseoverflow', $output );
		$this->assertStringNotContainsString( 'https://very-long-pds-host.example.com/some/deep/path', $output );
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
	 * A stored DID that doesn't match AT Proto syntax is rejected with a 404.
	 */
	public function test_atproto_did_well_known_response_rejects_malformed_did() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => "did:plc:abc\n<script>alert(1)</script>",
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
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
	 * A stored DID with a single trailing newline is rejected (PHP's $ would have allowed it).
	 */
	public function test_atproto_did_well_known_response_rejects_did_with_trailing_newline() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => "did:plc:test123\n",
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
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
	 * The suppression hook is a no-op for unrelated atmosphere_wellknown query vars.
	 */
	public function test_maybe_suppress_atmosphere_well_known_no_op_for_other_query_vars() {
		global $wp_query;
		$wp_query = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolating $wp_query state for the suppression hook.
		set_query_var( 'atmosphere_wellknown', 'publication' );
		add_filter( 'fosse_serve_atproto_did_well_known', '__return_false' );

		$status_header_called = $this->capture_status_header();

		$this->provider->maybe_suppress_atmosphere_well_known();

		$this->assertSame( 'publication', get_query_var( 'atmosphere_wellknown' ) );
		$this->assertFalse( $wp_query->is_404() );
		$this->assertNull( $status_header_called->code, 'status_header should not be called for unrelated query vars.' );
	}

	/**
	 * The suppression hook is a no-op when FOSSE will serve the route itself.
	 */
	public function test_maybe_suppress_atmosphere_well_known_no_op_when_filter_true() {
		global $wp_query;
		$wp_query = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolating $wp_query state for the suppression hook.
		set_query_var( 'atmosphere_wellknown', 'atproto-did' );

		$status_header_called = $this->capture_status_header();

		$this->provider->maybe_suppress_atmosphere_well_known();

		$this->assertSame( 'atproto-did', get_query_var( 'atmosphere_wellknown' ) );
		$this->assertFalse( $wp_query->is_404() );
		$this->assertNull( $status_header_called->code, 'status_header should not be called when FOSSE will serve the route.' );
	}

	/**
	 * Opting out via filter clears Atmosphere's query var and forces a 404.
	 */
	public function test_maybe_suppress_atmosphere_well_known_clears_query_var_and_404s_when_opted_out() {
		global $wp_query;
		$wp_query = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolating $wp_query state for the suppression hook.
		set_query_var( 'atmosphere_wellknown', 'atproto-did' );
		add_filter( 'fosse_serve_atproto_did_well_known', '__return_false' );

		$status_header_called = $this->capture_status_header();

		$this->provider->maybe_suppress_atmosphere_well_known();

		$this->assertSame( '', get_query_var( 'atmosphere_wellknown' ) );
		$this->assertTrue( $wp_query->is_404() );
		$this->assertSame( 404, $status_header_called->code, 'status_header( 404 ) should be sent on opt-out.' );
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
		$this->assertStringContainsString( 'valid handle', strtolower( implode( ' ', $messages ) ) );
		$this->assertStringContainsString( 'example.com', strtolower( implode( ' ', $messages ) ) );
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
	 * Provider registers the expected hooks. Settings save is centralized
	 * in {@see Setup_Page::handle_save()} so the provider's own
	 * `register_hooks()` does NOT register an admin-post handler for it.
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

	// --- save_settings ----------------------------------------------------

	/**
	 * A submitted, checked auto-publish input persists `'1'` so Atmosphere's
	 * publish flow remains enabled after save.
	 */
	public function test_save_settings_stores_auto_publish_when_checked() {
		$this->seed_connected_atmosphere_connection();

		$ok = $this->provider->save_settings( array( 'atmosphere_auto_publish' => '1' ) );

		$this->assertTrue( $ok );
		$this->assertSame( '1', get_option( 'atmosphere_auto_publish' ) );
	}

	/**
	 * An omitted checkbox while connected is the legitimate "disabled"
	 * submission — HTML forms drop unchecked checkboxes from the POST body,
	 * so absence must persist `'0'` instead of preserving the previous value.
	 */
	public function test_save_settings_disables_auto_publish_when_unchecked() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '1' );

		$ok = $this->provider->save_settings( array() );

		$this->assertTrue( $ok );
		$this->assertSame( '0', get_option( 'atmosphere_auto_publish' ) );
	}

	/**
	 * An empty-string value (defensive) reads as "unchecked" and disables
	 * auto-publish — guards against malformed POST bodies that submit the
	 * field name without a value.
	 */
	public function test_save_settings_disables_auto_publish_for_empty_value() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '1' );

		$this->provider->save_settings( array( 'atmosphere_auto_publish' => '' ) );

		$this->assertSame( '0', get_option( 'atmosphere_auto_publish' ) );
	}

	/**
	 * A Settings save while disconnected must NOT touch
	 * `atmosphere_auto_publish` — the toggle isn't rendered in that state,
	 * so an omitted checkbox is the absence of a setting, not an
	 * intentional "uncheck". Without this guard a routine General-section
	 * save would silently flip the option to `'0'`.
	 */
	public function test_save_settings_disconnected_preserves_auto_publish() {
		// Provider is disconnected by set_up_provider() (no connection seed).
		update_option( 'atmosphere_auto_publish', '1' );

		$ok = $this->provider->save_settings( array() );

		$this->assertTrue( $ok );
		$this->assertSame( '1', get_option( 'atmosphere_auto_publish' ) );
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
	 * Capture the next status_header call into a returned object's `code` property.
	 *
	 * @return object Object with a nullable int `code` property; null until status_header fires.
	 */
	private function capture_status_header(): object {
		$capture       = new \stdClass();
		$capture->code = null;
		add_filter(
			'status_header',
			static function ( $header, $code ) use ( $capture ) {
				$capture->code = (int) $code;
				return $header;
			},
			10,
			2
		);
		return $capture;
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
