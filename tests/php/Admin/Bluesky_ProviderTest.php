<?php
/**
 * Tests for Bluesky_Provider.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Atmosphere\OAuth\Encryption;
use Automattic\Fosse\Admin\Bluesky_Domain_Handle;
use Automattic\Fosse\Admin\Bluesky_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use Automattic\Fosse\Provider_Loader;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
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
		// Reset the loader's idempotency flag too — `test_registers_through_provider_loader`
		// calls `Provider_Loader::boot()`, which would silently no-op on a second test
		// invocation in the same PHP process if the static `$booted` flag leaked.
		Provider_Loader::reset();
		delete_option( 'atmosphere_connection' );
		delete_option( 'atmosphere_identity' );
		delete_option( 'atmosphere_auto_publish' );
		delete_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE );
		delete_transient( 'fosse_bluesky_profile_' . sanitize_key( 'did:plc:test123' ) );

		// Reset the one-shot revert hand-off so a prior test's successful
		// revert can't surface a ghost snapshot in this test's reads.
		$reflection = new ReflectionClass( Bluesky_Domain_Handle::class );
		if ( $reflection->hasProperty( 'last_revert_snapshot' ) ) {
			$reflection->getProperty( 'last_revert_snapshot' )->setValue( null, null );
		}

		remove_all_filters( 'fosse_register_providers' );
		remove_all_filters( 'atmosphere_oauth_redirect_uri' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'admin_post_fosse_connect_bluesky' );
		remove_all_filters( 'admin_post_fosse_disconnect_bluesky' );
		remove_all_filters( 'admin_post_fosse_set_bluesky_domain_handle' );
		remove_all_filters( 'admin_init' );
		remove_all_filters( 'fosse_serve_atproto_did_well_known' );
		remove_all_filters( 'status_header' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_option_atmosphere_connection' );
		remove_all_filters( Bluesky_Domain_Handle::FILTER_ENABLED );
		remove_all_filters( Bluesky_Domain_Handle::FILTER_PRE_UPDATE );
		remove_all_filters( 'home_url' );

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
	 * Default status is disconnected with empty connection fields.
	 */
	public function test_status_disconnected_by_default() {
		$status = $this->provider->get_status();

		$this->assertFalse( $status['connected'] );
		$this->assertSame( '', $status['handle'] );
		$this->assertSame( '', $status['did'] );
		$this->assertSame( '', $status['pds_endpoint'] );
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

		$status = $this->provider->get_status();

		$this->assertTrue( $status['connected'] );
		$this->assertSame( 'alice.bsky.social', $status['handle'] );
		$this->assertSame( 'did:plc:test123', $status['did'] );
		$this->assertSame( 'https://bsky.social', $status['pds_endpoint'] );
		$this->assertNull( $status['token_error'] );
	}

	/**
	 * `get_status()` re-reads the connection state after the token-health
	 * probe so a refresh that deletes `atmosphere_connection` mid-call
	 * (e.g. permanent OAuth failure: `invalid_grant`, `invalid_client`,
	 * `unauthorized_client`) is reflected in the returned status, not
	 * frozen as the pre-deletion view. Otherwise the memo would freeze
	 * `connected=true` for the rest of the request and the admin would
	 * see a green status at the exact moment publishing credentials were
	 * invalidated.
	 *
	 * Simulates the deletion side-effect via a one-shot
	 * `pre_option_atmosphere_connection` filter: first read sees the
	 * connection (so `is_connected()` returns true and we enter the
	 * token-probe branch), then the filter wipes the option and returns
	 * `array()` thereafter. The implementation under test calls
	 * `is_connected()` → probe → `get_connection()` / `is_connected()`
	 * a second time, which now sees the deleted state.
	 */
	public function test_status_re_reads_connection_after_token_probe_deletion(): void {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		// Mimic Atmosphere's refresh path deleting the connection during
		// `access_token()`. Reads 1-2 return the live connection (so
		// `is_connected()` returns true and the implementation enters the
		// token-probe branch). Reads 3+ return empty — Atmosphere's
		// permanent-failure deletion is what the implementation under
		// test must observe when it re-reads after the probe.
		$connected_payload = array(
			'did'          => 'did:plc:test123',
			'handle'       => 'alice.bsky.social',
			'pds_endpoint' => 'https://bsky.social',
			'access_token' => Encryption::encrypt( 'token' ),
		);
		$reads             = 0;
		add_filter(
			'pre_option_atmosphere_connection',
			static function () use ( &$reads, $connected_payload ) {
				++$reads;
				return $reads <= 2 ? $connected_payload : array();
			}
		);

		$status = $this->provider->get_status();

		$this->assertFalse(
			$status['connected'],
			'After the token probe deletes the connection, get_status() must reflect the deletion — not the pre-delete view.'
		);
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
	 * `render_setup_section()` is a no-op in both connected and disconnected
	 * states. The auto-publish toggle that used to live here was removed
	 * (Atmosphere has no per-post manual publish UI to back it up); the
	 * connect / disconnect actions render via `render_connection_actions()`
	 * outside the unified Settings form. Other interface methods (status
	 * card, save_settings) keep working — this test just pins the
	 * "nothing renders into the unified Settings form" contract.
	 */
	public function test_render_setup_section_is_no_op_when_disconnected() {
		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	/**
	 * Connected state also renders nothing — the toggle was the only thing
	 * gated on `connected`, so removing it leaves no Bluesky-specific row
	 * in the unified Settings form.
	 */
	public function test_render_setup_section_is_no_op_when_connected() {
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

		$this->assertSame( '', trim( $output ) );
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
		$this->assertStringContainsString( 'Bluesky handle', $output );
		$this->assertStringContainsString( 'Connect Bluesky', $output );
		$this->assertStringContainsString( 'Disconnected', $output );
		$this->assertStringNotContainsString( 'class="form-table"', $output );
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
		$this->assertStringContainsString( 'Disconnect Bluesky', $output );
		$this->assertStringContainsString( 'Connected account', $output );
		$this->assertStringContainsString( 'Bluesky handle', $output );
		$this->assertStringContainsString( 'alice.bsky.social', $output );
		$this->assertStringContainsString( 'Account ID', $output );
		$this->assertStringContainsString( 'PDS endpoint', $output );
		$this->assertStringContainsString( 'Token health', $output );
		$this->assertStringNotContainsString( 'class="form-table"', $output );
	}

	/**
	 * When a snapshot exists for the connected DID and the current
	 * handle no longer matches the site host, the Settings panel
	 * surfaces drift-specific copy instead of the first-time setup
	 * copy. Same CTA button — only the framing changes.
	 */
	public function test_render_connection_actions_renders_drift_copy_when_snapshot_exists() {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'oldhandle.example',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);
		update_option(
			Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			false
		);

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Realign your Bluesky handle with this site', $output );
		$this->assertStringContainsString( 'FOSSE previously set your Bluesky handle', $output );
		// First-time-setup copy must not also surface.
		$this->assertStringNotContainsString( 'You can replace it with', $output );
		// CTA button itself is unchanged.
		$this->assertStringContainsString( 'fosse_set_bluesky_domain_handle', $output );
	}

	/**
	 * Disconnected connection panel links out to bsky.app so users without
	 * an account aren't dead-ended at the connect form.
	 */
	public function test_render_connection_actions_disconnected_links_to_bluesky_signup() {
		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'https://bsky.app/', $output );
		$this->assertStringContainsString( 'Need a Bluesky account', $output );
		$this->assertStringContainsString( 'Create one', $output );
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

		$this->assertStringNotContainsString( 'Create one', $output );
		$this->assertStringNotContainsString( 'Need a Bluesky account', $output );
	}

	/**
	 * `redirect_with_notice()` escapes the message before storing it in the
	 * `'atmosphere'` settings-error group. The audit's escaping finding turns
	 * on this — `settings_errors()` (and WP's auto-render via `admin_notices`)
	 * output the stored `message` field as raw HTML, so any `WP_Error` text
	 * from upstream OAuth/PDS/`sync_publication()` paths must be neutralized
	 * at storage so every render site is safe.
	 *
	 * Drives the helper through reflection (it's private and ends in `exit`,
	 * which the redirect trap converts to a thrown exception) and inspects
	 * the resulting global to assert escape happened.
	 */
	public function test_redirect_with_notice_escapes_message_at_storage(): void {
		$this->arm_redirect_trap();

		try {
			$this->invoke_redirect_with_notice( '<img src=x onerror="alert(1)">', 'error' );
			$this->fail( 'Expected redirect_with_notice to redirect.' );
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors = get_settings_errors( 'atmosphere' );
		$this->assertNotEmpty( $errors );

		$message = (string) ( $errors[0]['message'] ?? '' );
		$this->assertStringNotContainsString( '<img', $message );
		$this->assertStringContainsString( '&lt;img', $message );
		$this->assertSame( 'error', $errors[0]['type'] ?? '' );
	}

	/**
	 * The escape applies on every type, not just errors. A success notice
	 * with HTML from an upstream payload (unlikely but conceivable) is also
	 * neutralized.
	 */
	public function test_redirect_with_notice_escapes_message_on_success_type(): void {
		$this->arm_redirect_trap();

		try {
			$this->invoke_redirect_with_notice( '<b>Connected</b>', 'success' );
			$this->fail( 'Expected redirect_with_notice to redirect.' );
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors = get_settings_errors( 'atmosphere' );
		$this->assertNotEmpty( $errors );

		$message = (string) ( $errors[0]['message'] ?? '' );
		$this->assertStringNotContainsString( '<b>', $message );
		$this->assertStringContainsString( '&lt;b&gt;', $message );
	}

	/**
	 * Plain ASCII messages survive the escape unchanged so the routine
	 * notices ("Disconnected from Bluesky.", "Successfully connected to
	 * Bluesky.") still read naturally.
	 */
	public function test_redirect_with_notice_passes_plain_text_through(): void {
		$this->arm_redirect_trap();

		try {
			$this->invoke_redirect_with_notice( 'Disconnected from Bluesky.', 'info' );
			$this->fail( 'Expected redirect_with_notice to redirect.' );
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors = get_settings_errors( 'atmosphere' );
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'Disconnected from Bluesky.', $errors[0]['message'] ?? '' );
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
		$this->assertSame( 1, substr_count( $output, 'Open Bluesky settings' ) );
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

		$this->assertMatchesRegularExpression( '/<dt[^>]*>\s*Token Health\s*<\/dt>\s*<dd[^>]*>\s*OK\s*<\/dd>/', $output );
		$this->assertStringNotContainsString( 'Reconnect required', $output );
		$this->assertStringNotContainsString( '<details', $output );
	}

	/**
	 * Disconnected status card links directly to Bluesky connection settings.
	 */
	public function test_render_status_card_disconnected_state_links_to_settings() {
		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Open Bluesky settings', $output );
		$this->assertStringContainsString( 'admin.php?page=fosse#fosse-provider-bluesky', $output );
	}

	/**
	 * Connected healthy status card keeps the settings link out of the card
	 * action row; token-error recovery still renders inline with token health.
	 */
	public function test_render_status_card_connected_state_omits_settings_action_row() {
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

		$this->assertStringNotContainsString( 'Open Bluesky settings', $output );
	}

	/**
	 * Connected status card links the Bluesky handle to the public profile.
	 */
	public function test_render_status_card_links_handle_to_public_profile(): void {
		$this->seed_connected_atmosphere_connection();

		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'~<a[^>]+href="https://bsky\.app/profile/did%3Aplc%3Atest123"[^>]*>\s*<code class="fosse-token fosse-status-card__token fosse-token--handle fosse-status-card__token--handle">@alice\.<wbr>bsky\.<wbr>social</code>\s*</a>~',
			$output
		);
	}

	/**
	 * Connected settings panel links the Bluesky handle and includes the cached
	 * follower count from the Bluesky profile response.
	 */
	public function test_render_connection_actions_connected_links_handle_and_shows_followers(): void {
		$this->seed_api_capable_atmosphere_connection();
		$request_count = $this->mock_bluesky_profile_followers( 1234 );

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'~<a[^>]+href="https://bsky\.app/profile/did%3Aplc%3Atest123"[^>]*>\s*<code class="fosse-token fosse-admin-token fosse-token--handle fosse-admin-token--handle">@alice\.<wbr>bsky\.<wbr>social</code>\s*</a>~',
			$output
		);
		$this->assertMatchesRegularExpression( '~<dt[^>]*>\s*Followers\s*</dt>\s*<dd[^>]*>\s*1,234\s*</dd>~', $output );

		ob_start();
		$this->provider->render_connection_actions();
		ob_get_clean();

		$this->assertSame( 1, $request_count(), 'The second render should use the cached Bluesky profile count.' );
	}

	/**
	 * Connected settings panel omits the linked handle row when the stored
	 * Atmosphere connection is partial and has no handle to name.
	 */
	public function test_render_connection_actions_connected_omits_handle_row_when_handle_empty(): void {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => '',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Connected account', $output );
		$this->assertStringContainsString( 'Account ID', $output );
		$this->assertDoesNotMatchRegularExpression( '~<dt class="fosse-detail-list__term">\s*Bluesky handle\s*</dt>~', $output );
		$this->assertStringNotContainsString( 'https://bsky.app/profile/"', $output );
		$this->assertStringNotContainsString( '>@</code>', $output );
	}

	/**
	 * Connected status card includes the cached Bluesky follower count too, so
	 * the Settings and Status surfaces expose the same account summary.
	 */
	public function test_render_status_card_shows_followers(): void {
		$this->seed_api_capable_atmosphere_connection();
		$this->mock_bluesky_profile_followers( 9876 );

		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '~<dt[^>]*>\s*Followers\s*</dt>\s*<dd[^>]*>\s*9,876\s*</dd>~', $output );
	}

	/**
	 * A status snapshot with a token_error set short-circuits before any
	 * HTTP call so a stale OAuth session cannot block admin rendering on
	 * the 30s wp_remote_request timeout.
	 */
	public function test_get_followers_count_short_circuits_when_token_error_present(): void {
		$request_count = $this->mock_bluesky_profile_followers( 1234 );

		$followers = $this->invoke_get_followers_count(
			array(
				'connected'   => true,
				'did'         => 'did:plc:test123',
				'handle'      => 'alice.bsky.social',
				'token_error' => 'invalid_token',
			)
		);

		$this->assertNull( $followers );
		$this->assertSame( 0, $request_count(), 'No profile request must fire when token_error is set.' );
	}

	/**
	 * A disconnected status short-circuits before any HTTP call.
	 */
	public function test_get_followers_count_short_circuits_when_disconnected(): void {
		$request_count = $this->mock_bluesky_profile_followers( 1234 );

		$followers = $this->invoke_get_followers_count(
			array(
				'connected'   => false,
				'did'         => 'did:plc:test123',
				'handle'      => 'alice.bsky.social',
				'token_error' => null,
			)
		);

		$this->assertNull( $followers );
		$this->assertSame( 0, $request_count(), 'No profile request must fire when status is disconnected.' );
	}

	/**
	 * A WP_Error from the profile fetch suppresses the Followers row and is
	 * negative-cached so a second render skips the live request (otherwise a
	 * PDS outage would block every admin page render on the 30s timeout).
	 */
	public function test_render_status_card_negative_caches_wp_error_from_profile(): void {
		$this->seed_api_capable_atmosphere_connection();
		$request_count = $this->mock_bluesky_profile_response(
			new \WP_Error( 'http_request_failed', 'connection refused' )
		);

		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Followers', $output );

		ob_start();
		$this->provider->render_status_card();
		ob_get_clean();

		$this->assertSame( 1, $request_count(), 'Negative-cache must suppress the second profile request after a WP_Error.' );
	}

	/**
	 * A profile response missing followersCount yields null and is
	 * negative-cached.
	 */
	public function test_render_status_card_handles_missing_followers_count_key(): void {
		$this->seed_api_capable_atmosphere_connection();
		$this->mock_bluesky_profile_response_body(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			)
		);

		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Followers', $output );
	}

	/**
	 * A non-numeric followersCount value (e.g. an unexpected payload shape)
	 * yields null and is negative-cached rather than coerced to 0.
	 */
	public function test_render_status_card_handles_non_numeric_followers_count(): void {
		$this->seed_api_capable_atmosphere_connection();
		$this->mock_bluesky_profile_response_body(
			array(
				'did'            => 'did:plc:test123',
				'handle'         => 'alice.bsky.social',
				'followersCount' => 'nope',
			)
		);

		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Followers', $output );
	}

	/**
	 * A negative followersCount value is clamped to 0 so the UI never
	 * surfaces a nonsensical negative number.
	 */
	public function test_render_status_card_clamps_negative_followers_count_to_zero(): void {
		$this->seed_api_capable_atmosphere_connection();
		$this->mock_bluesky_profile_followers( -5 );

		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '~<dt[^>]*>\s*Followers\s*</dt>\s*<dd[^>]*>\s*0\s*</dd>~', $output );
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

		$this->assertStringContainsString( 'fosse-token--did', $output );
		$this->assertStringContainsString( 'fosse-token--handle', $output );
		$this->assertStringContainsString( 'fosse-token--url', $output );
		$this->assertStringNotContainsString( 'widefat striped', $output );

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
	 * The well-known route must keep serving the DID while a stored identity
	 * needs OAuth reauthorization. Domain handles depend on this endpoint to
	 * resolve before the reconnect flow can even redirect to the auth server.
	 */
	public function test_atproto_did_well_known_response_uses_identity_during_reauth() {
		update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://bsky.social',
			)
		);
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => '',
				'needs_reauth' => true,
			)
		);

		$this->assertFalse( \Atmosphere\is_connected() );
		$this->assertTrue( \Atmosphere\has_identity() );

		$this->assertSame(
			array(
				'status' => 200,
				'did'    => 'did:plc:test123',
			),
			$this->get_atproto_did_well_known_response( '/.well-known/atproto-did' )
		);
	}

	/**
	 * A legacy connection row with identity data still serves the DID even
	 * without a live access token. Atmosphere lazily migrates this shape into
	 * `atmosphere_identity`, and FOSSE should follow that source of truth.
	 */
	public function test_atproto_did_well_known_response_serves_did_from_legacy_connection_row() {
		update_option(
			'atmosphere_connection',
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			)
		);

		$this->assertSame(
			array(
				'status' => 200,
				'did'    => 'did:plc:test123',
			),
			$this->get_atproto_did_well_known_response( '/.well-known/atproto-did' )
		);
	}

	/**
	 * Sites without any persisted AT Protocol identity return 404.
	 */
	public function test_atproto_did_well_known_response_returns_404_without_identity() {
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
			'no dot, single label'                 => array( 'alice' ),
			'leading dot'                          => array( '.bsky.social' ),
			'trailing dot'                         => array( 'alice.bsky.social.' ),
			'space inside'                         => array( 'alice bsky.social' ),
			'underscore'                           => array( 'al_ice.bsky.social' ),
			'mastodon style'                       => array( '@alice@host.example' ),
			'leading hyphen label'                 => array( '-alice.bsky.social' ),
			'interior zero-width space (U+200B)'   => array( "alice\u{200B}.bsky.social" ),
			'interior left-to-right mark (U+200E)' => array( "alice.bsky\u{200E}.social" ),
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
		// the handle's auth server, and capture the normalized handle it resolved.
		$captured_handle = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured_handle ) {
				// The first HTTP call from authorize() is handle resolution.
				// Capture the URL to verify the normalized handle was passed.
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
		$this->assertStringContainsString( 'alice.bsky.social', $captured_handle );
		$this->assertStringNotContainsString( '@alice', $captured_handle );
		$this->assertStringNotContainsString( '%40alice', $captured_handle );
	}

	/**
	 * Invisible Unicode formatting bytes at the edges of a copied handle
	 * are stripped before validation. They are visually indistinguishable
	 * from a valid handle, but the ASCII handle regex would reject them if
	 * they survived. Interior formatting bytes are NOT stripped — see
	 * {@see self::invalid_handle_provider()} for those cases.
	 *
	 * @dataProvider boundary_invisible_handle_provider
	 *
	 * @param string $raw_handle Raw user input.
	 */
	#[DataProvider( 'boundary_invisible_handle_provider' )]
	public function test_handle_connect_strips_edge_invisible_unicode_formatting( string $raw_handle ) {
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce'       => wp_create_nonce( 'fosse_connect_bluesky' ),
			'bluesky_handle' => $raw_handle,
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$captured_url = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
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

		$this->assertNotNull(
			$captured_url,
			'Expected pre_http_request to fire from Client::authorize() after removing invisible formatting bytes.'
		);
		$this->assertSame(
			'devdotdev.bsky.social',
			wp_parse_url( (string) $captured_url, PHP_URL_HOST ),
			'Normalized handle should be the resolved host of the authorize lookup URL.'
		);
	}

	/**
	 * Data provider for edge-placed invisible Unicode formatting bytes.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function boundary_invisible_handle_provider(): array {
		return array(
			'trailing pop directional formatting (U+202C)' => array( "devdotdev.bsky.social\u{202C}" ),
			'leading byte-order mark (U+FEFF)'             => array( "\u{FEFF}devdotdev.bsky.social" ),
			'leading and trailing zero-width space (U+200B)' => array( "\u{200B}devdotdev.bsky.social\u{200B}" ),
		);
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
		$this->assertNotFalse( has_action( 'admin_post_fosse_enable_bluesky_auto_publish', array( $this->provider, 'handle_enable_auto_publish' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $this->provider, 'handle_oauth_callback' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( $this->provider, 'maybe_render_auto_publish_disabled_notice' ) ) );
		$this->assertSame( 1, has_action( 'init', array( $this->provider, 'serve_atproto_did_well_known' ) ) );
		$this->assertSame( 1, has_action( 'template_redirect', array( $this->provider, 'maybe_suppress_atmosphere_well_known' ) ) );
		$this->assertNotFalse( has_filter( 'atmosphere_oauth_redirect_uri', array( $this->provider, 'filter_oauth_redirect_uri' ) ) );
	}

	// --- save_settings ----------------------------------------------------

	/**
	 * `save_settings()` is currently a no-op (the auto-publish toggle was
	 * removed from `render_setup_section()` because Atmosphere has no
	 * per-post manual publish UI to back it up). It must still return
	 * true so the unified Settings save's all-or-nothing semantics
	 * succeed, and it must NOT touch `atmosphere_auto_publish` — the
	 * option remains stored at its existing value (default `'1'`)
	 * regardless of POST contents.
	 */
	public function test_save_settings_is_no_op_and_returns_true() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '1' );

		// Even a payload that LOOKS like the legacy unchecked-checkbox
		// shape (omitted key) must not flip the option to '0' — there's
		// no input rendered, so its absence is meaningless.
		$ok = $this->provider->save_settings( array() );

		$this->assertTrue( $ok );
		$this->assertSame( '1', get_option( 'atmosphere_auto_publish' ) );

		// And a payload that explicitly carries the legacy field is also
		// ignored — safety against a stale form somehow being POSTed.
		$ok = $this->provider->save_settings( array( 'atmosphere_auto_publish' => '0' ) );

		$this->assertTrue( $ok );
		$this->assertSame( '1', get_option( 'atmosphere_auto_publish' ) );
	}

	/**
	 * Pins the upstream contract that FOSSE's "preserved option" claim
	 * depends on: when `atmosphere_auto_publish` is absent from the
	 * database, `get_option()` returns `'1'` (auto-publish enabled).
	 * Atmosphere's publish hook reads the option with the same default,
	 * so an absent option is functionally equivalent to "on."
	 *
	 * If a future Atmosphere bump flips the documented default, or if
	 * something registers the option with a different default at
	 * `register_setting()` time, this assertion catches the contract
	 * change before it silently disables Bluesky publishing for every
	 * default-state site.
	 */
	public function test_absent_atmosphere_auto_publish_option_defaults_to_enabled() {
		delete_option( 'atmosphere_auto_publish' );

		$this->assertFalse(
			get_option( 'atmosphere_auto_publish' ),
			'Sanity check: option should be absent so the default-fallback path runs.'
		);
		$this->assertSame(
			'1',
			get_option( 'atmosphere_auto_publish', '1' ),
			'FOSSE removed the auto-publish UI on the assumption that an absent option reads as enabled. If this assertion fails, the assumption is no longer safe and the recovery notice / handler in Bluesky_Provider must be reconsidered.'
		);
	}

	// --- is_auto_publish_enabled --------------------------------------

	/**
	 * Helper returns true when the option is absent — encodes the
	 * "default-on" contract upstream Atmosphere shares. Pairs with
	 * `test_absent_atmosphere_auto_publish_option_defaults_to_enabled`,
	 * which pins the raw `get_option()` half of the same contract.
	 */
	public function test_is_auto_publish_enabled_true_when_option_absent() {
		delete_option( 'atmosphere_auto_publish' );

		$this->assertTrue( Bluesky_Provider::is_auto_publish_enabled() );
	}

	/**
	 * Helper returns true when the option is explicitly `'1'`.
	 */
	public function test_is_auto_publish_enabled_true_when_explicitly_on() {
		update_option( 'atmosphere_auto_publish', '1' );

		$this->assertTrue( Bluesky_Provider::is_auto_publish_enabled() );
	}

	/**
	 * Helper returns false when the option is explicitly `'0'` — the
	 * recovery-notice population. The recovery-notice gate uses a
	 * separate raw read because it must distinguish "explicit `'0'`"
	 * from "absent"; this helper conflates the two by design (absent
	 * reads as enabled).
	 */
	public function test_is_auto_publish_enabled_false_when_explicitly_off() {
		update_option( 'atmosphere_auto_publish', '0' );

		$this->assertFalse( Bluesky_Provider::is_auto_publish_enabled() );
	}

	// --- maybe_render_auto_publish_disabled_notice ----------------------

	/**
	 * Notice fires when the option is explicitly `'0'` and Bluesky is
	 * connected, on a FOSSE admin screen, for a user who can manage
	 * options. Renders a warning notice with a one-click re-enable form.
	 */
	public function test_auto_publish_disabled_notice_renders_when_explicitly_off() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '0' );
		$this->become_admin();
		set_current_screen( 'toplevel_page_fosse' );

		ob_start();
		$this->provider->maybe_render_auto_publish_disabled_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-warning', $output );
		$this->assertStringContainsString( 'Bluesky auto-publishing is off.', $output );
		$this->assertStringContainsString( 'fosse_enable_bluesky_auto_publish', $output );
		$this->assertStringContainsString( 'Turn auto-publishing back on', $output );
	}

	/**
	 * Notice does NOT fire when the option is at its absent / default-on
	 * state — that's the silent-majority path and would be noise.
	 */
	public function test_auto_publish_disabled_notice_silent_when_option_absent() {
		$this->seed_connected_atmosphere_connection();
		delete_option( 'atmosphere_auto_publish' );
		$this->become_admin();
		set_current_screen( 'toplevel_page_fosse' );

		ob_start();
		$this->provider->maybe_render_auto_publish_disabled_notice();
		$output = ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	/**
	 * Notice does NOT fire when the option is explicitly `'1'` — same
	 * default-on intent, just with the option materialized.
	 */
	public function test_auto_publish_disabled_notice_silent_when_explicitly_on() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '1' );
		$this->become_admin();
		set_current_screen( 'toplevel_page_fosse' );

		ob_start();
		$this->provider->maybe_render_auto_publish_disabled_notice();
		$output = ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	/**
	 * Notice does NOT fire on non-FOSSE screens — even when the user is
	 * in the at-risk state, we limit the surface to avoid leaking across
	 * wp-admin.
	 */
	public function test_auto_publish_disabled_notice_silent_off_fosse_screens() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '0' );
		$this->become_admin();
		set_current_screen( 'dashboard' );

		ob_start();
		$this->provider->maybe_render_auto_publish_disabled_notice();
		$output = ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	/**
	 * Notice does NOT fire when Bluesky is disconnected — there's nothing
	 * for auto-publish to do anyway, so the notice would be misleading.
	 */
	public function test_auto_publish_disabled_notice_silent_when_disconnected() {
		// No `seed_connected_atmosphere_connection()` — disconnected.
		update_option( 'atmosphere_auto_publish', '0' );
		$this->become_admin();
		set_current_screen( 'toplevel_page_fosse' );

		ob_start();
		$this->provider->maybe_render_auto_publish_disabled_notice();
		$output = ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	/**
	 * Notice does NOT fire for users without `manage_options`.
	 */
	public function test_auto_publish_disabled_notice_silent_for_subscriber() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '0' );
		set_current_screen( 'toplevel_page_fosse' );

		$this->become_subscriber();

		ob_start();
		$this->provider->maybe_render_auto_publish_disabled_notice();
		$output = ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	// --- handle_enable_auto_publish -----------------------------------

	/**
	 * The re-enable handler flips the option to `'1'` and redirects with
	 * a success notice, recovering sites stranded by the toggle removal.
	 */
	public function test_handle_enable_auto_publish_flips_option_and_redirects() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '0' );
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'fosse_enable_bluesky_auto_publish' ),
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
			$this->provider->handle_enable_auto_publish();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( '1', get_option( 'atmosphere_auto_publish' ) );
	}

	/**
	 * Re-enable handler rejects requests with a missing or invalid nonce.
	 */
	public function test_handle_enable_auto_publish_rejects_bad_nonce() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '0' );
		$this->become_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => 'invalid_nonce_value',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->install_wp_die_handler();
		$this->expectException( \RuntimeException::class );
		$this->provider->handle_enable_auto_publish();
	}

	/**
	 * Re-enable handler rejects subscribers — option must not change for
	 * users without `manage_options`.
	 */
	public function test_handle_enable_auto_publish_rejects_subscriber() {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '0' );

		$this->become_subscriber();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'fosse_enable_bluesky_auto_publish' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->install_wp_die_handler();
		$this->expectException( \RuntimeException::class );
		try {
			$this->provider->handle_enable_auto_publish();
		} finally {
			$this->assertSame(
				'0',
				get_option( 'atmosphere_auto_publish' ),
				'Subscriber must not be able to flip the option.'
			);
		}
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
	 * Install a `wp_redirect` filter that throws `RedirectFired` so a
	 * helper that ends in `wp_safe_redirect( ... ); exit;` can be tested
	 * without process-killing.
	 *
	 * @return void
	 */
	private function arm_redirect_trap(): void {
		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);
	}

	/**
	 * Drive the private `redirect_with_notice( message, type )` helper for
	 * tests that need to exercise the storage-side escape without going
	 * through a full handler path. Reflection is appropriate here because
	 * the callers in question are themselves private code paths.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type.
	 * @return void
	 */
	private function invoke_redirect_with_notice( string $message, string $type ): void {
		( new ReflectionMethod( Bluesky_Provider::class, 'redirect_with_notice' ) )
			->invoke( $this->provider, $message, $type );
	}

	/**
	 * Invoke the private static `get_followers_count` method directly so
	 * error-branch tests don't depend on the live OAuth probe that
	 * `get_status()` runs.
	 *
	 * @param array<string, mixed> $status Status snapshot to pass.
	 * @return int|null
	 */
	private function invoke_get_followers_count( array $status ): ?int {
		$method = new ReflectionMethod( Bluesky_Provider::class, 'get_followers_count' );
		$result = $method->invoke( null, $status );

		return $result;
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
	 * Seed a connected Atmosphere connection with the DPoP key required for
	 * authenticated profile API calls.
	 *
	 * @return void
	 */
	private function seed_api_capable_atmosphere_connection(): void {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
				'dpop_jwk'     => Encryption::encrypt( wp_json_encode( \Atmosphere\OAuth\DPoP::generate_key(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) ),
			)
		);
	}

	/**
	 * Mock the Bluesky profile API response and expose how often it was called.
	 *
	 * @param int $followers Followers count to return.
	 * @return callable(): int
	 */
	private function mock_bluesky_profile_followers( int $followers ): callable {
		$requests = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$requests, $followers ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- pre_http_request signature.
				if ( false === strpos( (string) $url, '/xrpc/app.bsky.actor.getProfile' ) ) {
					return $preempt;
				}

				++$requests;

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'did'            => 'did:plc:test123',
							'handle'         => 'alice.bsky.social',
							'followersCount' => $followers,
						),
						JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		return static function () use ( &$requests ): int {
			return $requests;
		};
	}

	/**
	 * Mock the Bluesky profile API to return a fixed body shape (post-JSON-decode).
	 *
	 * Used by error-branch tests that need to assert on missing keys or
	 * non-numeric followersCount values without rebuilding the full pre_http_request
	 * shape in each test.
	 *
	 * @param array<string, mixed> $body Decoded body to return.
	 * @return callable(): int Returns how many requests the mock saw.
	 */
	private function mock_bluesky_profile_response_body( array $body ): callable {
		$requests = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$requests, $body ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- pre_http_request signature.
				if ( false === strpos( (string) $url, '/xrpc/app.bsky.actor.getProfile' ) ) {
					return $preempt;
				}

				++$requests;

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		return static function () use ( &$requests ): int {
			return $requests;
		};
	}

	/**
	 * Mock the Bluesky profile API to return an arbitrary pre_http_request
	 * payload — used to inject WP_Error or non-2xx responses.
	 *
	 * @param mixed $response Value to return from the pre_http_request filter.
	 * @return callable(): int Returns how many requests the mock saw.
	 */
	private function mock_bluesky_profile_response( $response ): callable {
		$requests = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$requests, $response ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- pre_http_request signature.
				if ( false === strpos( (string) $url, '/xrpc/app.bsky.actor.getProfile' ) ) {
					return $preempt;
				}

				++$requests;

				return $response;
			},
			10,
			3
		);

		return static function () use ( &$requests ): int {
			return $requests;
		};
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
		set_transient(
			'atmosphere_oauth_dpop_jwk',
			\Atmosphere\OAuth\Encryption::encrypt(
				(string) wp_json_encode( \Atmosphere\OAuth\DPoP::generate_key() ) // phpcs:ignore Jetpack.Functions.JsonEncodeFlags.Missing -- test fixture.
			),
			HOUR_IN_SECONDS
		);
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

	// ---- domain-handle integration: explicit confirm flow ----

	/**
	 * Force home_url() to a fixed value so domain-handle assertions are stable.
	 *
	 * @param string $url Forced URL.
	 * @return void
	 */
	private function force_home_url( string $url ): void {
		add_filter(
			'home_url',
			static function ( $existing, $path ) use ( $url ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
				return rtrim( $url, '/' ) . ( '' === $path ? '' : $path );
			},
			10,
			2
		);
	}

	/**
	 * Settings panel surfaces the confirm button when the user is connected
	 * with a non-matching handle on a root install.
	 */
	public function test_render_connection_actions_renders_domain_handle_panel_when_eligible(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connected_atmosphere_connection();

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fosse_set_bluesky_domain_handle', $output );
		$this->assertStringContainsString( 'Use example.com as my Bluesky handle', $output );
		$this->assertStringContainsString( 'Heads up: replacing your handle is destructive', $output );
		$this->assertStringContainsString( 'Use your domain as your Bluesky handle', $output );
	}

	/**
	 * Settings panel uses the empty-handle copy when the connection has no
	 * handle yet (e.g. a freshly-completed OAuth round-trip where Atmosphere
	 * hasn't populated the handle field). The panel still renders so the
	 * confirm button surfaces; the alternate copy avoids fabricating a
	 * "replace your current handle" sentence with no current handle to name.
	 */
	public function test_render_connection_actions_renders_domain_handle_panel_when_handle_empty(): void {
		$this->force_home_url( 'https://example.com' );
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => '',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fosse_set_bluesky_domain_handle', $output );
		$this->assertStringContainsString( 'You can set your Bluesky handle to example.com', $output );
		$this->assertStringContainsString( 'Use example.com as my Bluesky handle', $output );
		$this->assertStringContainsString( 'Heads up: replacing your handle is destructive', $output );
	}

	/**
	 * Settings panel suppresses the confirm button when the feature is disabled.
	 */
	public function test_render_connection_actions_omits_panel_when_feature_disabled(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connected_atmosphere_connection();
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'fosse_set_bluesky_domain_handle', $output );
	}

	/**
	 * Subdirectory installs do not render the confirm panel.
	 */
	public function test_render_connection_actions_omits_panel_for_subdirectory_install(): void {
		$this->force_home_url( 'https://example.com/blog' );
		$this->seed_connected_atmosphere_connection();

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'fosse_set_bluesky_domain_handle', $output );
	}

	/**
	 * The confirm panel disappears once the handle already matches the host.
	 */
	public function test_render_connection_actions_omits_panel_when_handle_already_matches(): void {
		$this->force_home_url( 'https://example.com' );
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'fosse_set_bluesky_domain_handle', $output );
	}

	/**
	 * The handle-set handler delegates to Bluesky_Domain_Handle and redirects
	 * back to the FOSSE Settings page on a normal (non-wizard) submission.
	 */
	public function test_handle_set_domain_handle_invokes_service_and_redirects(): void {
		// Don't force a custom home_url here: wp_safe_redirect() validates the
		// admin_url host against the allowed hosts derived from home_url, and
		// a forced home_url with the default admin_url would mismatch and
		// fall back to a bare wp-admin redirect. The set_handle service is
		// exercised under a forced home_url in Bluesky_Domain_HandleTest.
		$this->seed_connected_atmosphere_connection();
		$this->become_admin();

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		$captured_handle = null;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function ( $pre, $handle ) use ( &$captured_handle ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
				$captured_handle = $handle;
				return true;
			},
			10,
			2
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'fosse_set_bluesky_domain_handle' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$captured_redirect = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured_redirect ) {
				$captured_redirect = (string) $location;
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_set_domain_handle();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( $site_host, $captured_handle );
		$this->assertNotNull( $captured_redirect );
		$this->assertStringContainsString( 'page=fosse', $captured_redirect );
		$this->assertStringNotContainsString( 'page=fosse-wizard', $captured_redirect );

		// Snapshot is the new {did, handle} shape, bound to the current
		// account so a later reconnect-to-different-account can't push the
		// wrong handle on disconnect.
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE )
		);
	}

	/**
	 * The handle-set handler returns to the wizard when the wizard return
	 * context was carried in the form payload.
	 */
	public function test_handle_set_domain_handle_redirects_to_wizard_when_wizard_origin(): void {
		// See note in test_handle_set_domain_handle_invokes_service_and_redirects
		// — leaving home_url default avoids the wp_safe_redirect host-mismatch
		// fallback path.
		$this->seed_connected_atmosphere_connection();
		$this->become_admin();

		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce'                             => wp_create_nonce( 'fosse_set_bluesky_domain_handle' ),
			Bluesky_Provider::RETURN_CONTEXT_FIELD => Bluesky_Provider::RETURN_CONTEXT_WIZARD,
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$captured_redirect = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured_redirect ) {
				$captured_redirect = (string) $location;
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			$this->provider->handle_set_domain_handle();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured_redirect );
		$this->assertStringContainsString( 'page=fosse-wizard', $captured_redirect );
		$this->assertStringContainsString( 'step=bluesky', $captured_redirect );
	}

	/**
	 * Subscribers cannot trigger the handle change.
	 */
	public function test_handle_set_domain_handle_rejects_subscriber(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connected_atmosphere_connection();
		$this->become_subscriber();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'fosse_set_bluesky_domain_handle' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->install_wp_die_handler();

		$this->expectException( \RuntimeException::class );
		$this->provider->handle_set_domain_handle();
	}

	/**
	 * A bad nonce is rejected — the call must never run silently.
	 */
	public function test_handle_set_domain_handle_rejects_bad_nonce(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connected_atmosphere_connection();
		$this->become_admin();

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'_wpnonce' => 'invalid-nonce',
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->install_wp_die_handler();

		try {
			$this->provider->handle_set_domain_handle();
			$this->fail( 'wp_die() should have been triggered.' );
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- expected wp_die.
			unset( $e );
		}

		$this->assertFalse( $captured, 'A bad nonce must short-circuit before the call runs.' );
	}

	/**
	 * Disconnect attempts a handle revert before the OAuth token disappears,
	 * and successfully clears the snapshot when the revert succeeds.
	 */
	public function test_handle_disconnect_reverts_previously_set_handle(): void {
		$this->seed_connected_atmosphere_connection();
		update_option(
			Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			false
		);

		$this->become_admin();

		$received = null;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function ( $pre, $handle ) use ( &$received ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
				$received = $handle;
				return true;
			},
			10,
			2
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

		$this->assertSame( 'alice.bsky.social', $received );
		$this->assertFalse( get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ) );
		$this->assertFalse( \Atmosphere\is_connected() );
	}

	/**
	 * Disconnect proceeds even if the revert call fails. The snapshot is
	 * preserved so a future retry can still revert. Critically, the user
	 * sees a SINGLE combined "Disconnected, but couldn't restore your
	 * previous handle" warning — not a yellow warning followed by a
	 * cheerful green "Disconnected" success that's easy to read past.
	 */
	public function test_handle_disconnect_proceeds_when_revert_fails(): void {
		$this->seed_connected_atmosphere_connection();
		update_option(
			Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			false
		);

		$this->become_admin();

		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static fn() => new \WP_Error( 'fake_pds', 'token revoked' )
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

		$this->assertFalse( \Atmosphere\is_connected(), 'Disconnect must finish even when revert fails.' );
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE )
		);

		$errors = get_settings_errors( 'atmosphere' );
		$types  = wp_list_pluck( $errors, 'type' );
		$this->assertContains( 'warning', $types );
		$this->assertNotContains( 'info', $types, 'Cheerful "Disconnected" success must not stack on top of the warning.' );

		$messages = wp_list_pluck( $errors, 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== strpos( $m, 'Disconnected from Bluesky' ) && false !== strpos( $m, 'token revoked' )
			),
			'The single warning notice must communicate both the disconnect and the failed revert.'
		);
	}

	/**
	 * Disconnect that fails AFTER a successful PDS revert must re-persist
	 * the snapshot. Without this, the revert path cleared
	 * OPTION_PREVIOUS_HANDLE and a future disconnect retry would have
	 * nothing to revert to — leaving the user's now-domain handle stranded
	 * on the PDS with no FOSSE-assisted recovery.
	 */
	public function test_handle_disconnect_restores_snapshot_when_disconnect_fails_after_revert(): void {
		$this->seed_connected_atmosphere_connection();
		update_option(
			Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			false
		);

		$this->become_admin();

		// Simulate a successful PDS revert — the filter short-circuits the
		// real updateHandle call.
		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );

		// Pin the connection in place so Atmosphere's delete_option call
		// can't make is_connected() flip to false. The pin must reflect the
		// SYNCED handle (post-revert local cache update) so the test
		// matches the production sequencing where the local handle is
		// reverted before the disconnect attempt.
		$pinned = array(
			'did'          => 'did:plc:test123',
			'handle'       => 'alice.bsky.social',
			'pds_endpoint' => 'https://bsky.social',
			'access_token' => Encryption::encrypt( 'token' ),
		);
		add_filter(
			'pre_option_atmosphere_connection',
			static function () use ( $pinned ) {
				return $pinned;
			}
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

		// The disconnect surfaced an error notice — it really did fail.
		$errors = get_settings_errors( 'atmosphere' );
		$types  = array_column( $errors, 'type' );
		$this->assertContains( 'error', $types, 'Disconnect-fail branch must surface an error notice.' );

		// And critically: the snapshot is back, so a retry of disconnect
		// later will still attempt the revert (now a no-op against an
		// already-reverted handle, but the option is in the expected shape).
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ),
			'Snapshot must be re-persisted when disconnect fails after a successful revert.'
		);
	}

	/**
	 * The Settings-page disconnect form documents what disconnect will do
	 * when a domain-handle change is currently in effect, so users aren't
	 * surprised by a side-effect their click triggers.
	 */
	public function test_render_connection_actions_surfaces_pending_revert_note(): void {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);
		update_option(
			Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			false
		);

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$decoded         = html_entity_decode( $output, ENT_QUOTES, 'UTF-8' );
		$note_position   = strpos( $decoded, 'Disconnecting will also restore alice.bsky.social as this account\'s Bluesky handle' );
		$button_position = strpos( $decoded, 'Disconnect Bluesky' );

		$this->assertStringContainsString( 'Disconnecting will also restore alice.bsky.social as this account\'s Bluesky handle', $decoded );
		$this->assertStringContainsString( 'alice.bsky.social', $output );
		$this->assertIsInt( $note_position );
		$this->assertIsInt( $button_position );
		$this->assertGreaterThan( $note_position, $button_position );
	}

	/**
	 * The disconnect note is suppressed when the snapshot belongs to a
	 * different DID — the disconnect handler will refuse the revert
	 * anyway, and rendering the note would falsely promise it.
	 */
	public function test_render_connection_actions_omits_revert_note_for_mismatched_did(): void {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:current-account',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);
		update_option(
			Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => 'did:plc:other-account',
				'handle' => 'somebody-else.bsky.social',
			),
			false
		);

		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Disconnecting will also restore', $output );
		$this->assertStringNotContainsString( 'somebody-else.bsky.social', $output );
	}

	/**
	 * Disconnect against an account that does NOT match the snapshot's DID
	 * leaves the snapshot alone and runs no revert call — protects users
	 * who reconnected to a different Bluesky account between set + disconnect.
	 */
	public function test_handle_disconnect_skips_revert_when_snapshot_did_does_not_match(): void {
		$this->seed_connected_atmosphere_connection();
		update_option(
			Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => 'did:plc:other-account',
				'handle' => 'somebody-else.bsky.social',
			),
			false
		);

		$this->become_admin();

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
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

		$this->assertFalse( $captured, 'Mismatched-DID snapshot must not trigger an updateHandle call.' );
		$this->assertFalse( \Atmosphere\is_connected() );
		// Snapshot is preserved — the legitimate account may reconnect later.
		$this->assertNotFalse( get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ) );
	}
}
