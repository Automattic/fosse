<?php
/**
 * Tests for the unified Settings page handler.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Atmosphere\OAuth\Encryption;
use Automattic\Fosse\Admin\AP_Provider;
use Automattic\Fosse\Admin\Bluesky_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use Automattic\Fosse\Admin\Setup_Page;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the unified save handler drives every available provider's
 * `save_settings()` and emits the right notice/redirect on completion.
 */
class Setup_PageTest extends BaseTestCase {

	/**
	 * Reset registry, options, and global state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'fosse-test-auth-key' );
		}
		if ( ! defined( 'AUTH_SALT' ) ) {
			define( 'AUTH_SALT', 'fosse-test-auth-salt' );
		}

		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
		Bluesky_Provider::register_provider();

		delete_option( 'activitypub_actor_mode' );
		delete_option( 'activitypub_support_post_types' );
		delete_option( 'activitypub_blog_identifier' );
		delete_option( 'atmosphere_connection' );
		delete_option( 'atmosphere_auto_publish' );

		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- reset core settings-error storage for test isolation.

		remove_all_filters( 'wp_redirect' );

		// AP registers its sanitize callback during `admin_init`; tests don't
		// fire that. Wire the filter manually so the unified save exercises
		// the full AP path.
		remove_all_filters( 'sanitize_option_activitypub_blog_identifier' );
		if ( class_exists( '\Activitypub\Sanitize' ) ) {
			add_filter(
				'sanitize_option_activitypub_blog_identifier',
				array( '\Activitypub\Sanitize', 'blog_identifier' )
			);
		}
	}

	/**
	 * Tear down request-scoped state.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_state(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test cleanup.
		$_POST    = array();
		$_REQUEST = array();
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'sanitize_option_activitypub_blog_identifier' );
	}

	/**
	 * The class exposes the canonical save action slug — the template,
	 * Menu wiring, and any tests that simulate the form submission read
	 * from this constant so a renamed action stays consistent everywhere.
	 */
	public function test_save_action_constant_is_canonical(): void {
		$this->assertSame( 'fosse_save_settings', Setup_Page::SAVE_ACTION );
	}

	/**
	 * The register_hooks() call wires the unified admin-post handler so
	 * the form submission can find it.
	 */
	public function test_register_hooks_attaches_admin_post_handler(): void {
		remove_all_actions( 'admin_post_' . Setup_Page::SAVE_ACTION );

		Setup_Page::register_hooks();

		$this->assertNotFalse(
			has_action( 'admin_post_' . Setup_Page::SAVE_ACTION, array( Setup_Page::class, 'handle_save' ) )
		);
	}

	/**
	 * A clean unified save persists post types (the cross-protocol option),
	 * actor mode (AP-side), and auto-publish (Atmosphere-side) in one shot
	 * and posts the success notice to the FOSSE settings group.
	 */
	public function test_handle_save_persists_general_and_per_provider_settings(): void {
		$this->seed_connected_atmosphere_connection();
		$this->become_admin();

		$this->simulate_post(
			array(
				'activitypub_actor_mode'         => 'actor_blog',
				'activitypub_support_post_types' => array( 'post', 'page' ),
				'atmosphere_auto_publish'        => '1',
			)
		);

		$this->arm_redirect_trap();

		try {
			Setup_Page::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor_blog', get_option( 'activitypub_actor_mode' ) );
		$this->assertSame( array( 'post', 'page' ), get_option( 'activitypub_support_post_types' ) );
		$this->assertSame( '1', get_option( 'atmosphere_auto_publish' ) );

		$codes = array_column( get_settings_errors( 'fosse' ), 'code' );
		$this->assertContains( 'fosse_saved', $codes );
	}

	/**
	 * Unchecking the auto-publish box drops the field from the POST body —
	 * the unified save must persist `'0'` instead of preserving the prior
	 * value. Mirrors the Bluesky_Provider unit test through the full handler.
	 */
	public function test_handle_save_disables_auto_publish_when_field_omitted(): void {
		$this->seed_connected_atmosphere_connection();
		update_option( 'atmosphere_auto_publish', '1' );
		$this->become_admin();

		$this->simulate_post(
			array(
				'activitypub_actor_mode'         => 'actor',
				'activitypub_support_post_types' => array( 'post' ),
				// `atmosphere_auto_publish` intentionally omitted (unchecked).
			)
		);

		$this->arm_redirect_trap();

		try {
			Setup_Page::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( '0', get_option( 'atmosphere_auto_publish' ) );
	}

	/**
	 * A Settings save while Bluesky is disconnected does NOT flip
	 * `atmosphere_auto_publish` to `'0'` even though the toggle isn't in
	 * the form. The Bluesky provider's disconnect guard owns the policy;
	 * this test exercises it through the full unified handler.
	 */
	public function test_handle_save_disconnected_preserves_auto_publish(): void {
		// No `seed_connected_atmosphere_connection()` — provider stays
		// disconnected so save_settings() should bail out.
		update_option( 'atmosphere_auto_publish', '1' );
		$this->become_admin();

		$this->simulate_post(
			array(
				'activitypub_actor_mode'         => 'actor',
				'activitypub_support_post_types' => array( 'post' ),
			)
		);

		$this->arm_redirect_trap();

		try {
			Setup_Page::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( '1', get_option( 'atmosphere_auto_publish' ) );
	}

	/**
	 * General-section post-type save filters out post types that aren't
	 * registered as public — anything submitted via a crafted POST that
	 * isn't a valid public type is silently dropped before the option
	 * write.
	 */
	public function test_handle_save_filters_invalid_post_types(): void {
		$this->become_admin();

		$this->simulate_post(
			array(
				'activitypub_actor_mode'         => 'blog',
				'activitypub_support_post_types' => array( 'post', 'nonexistent_type', 'page' ),
			)
		);

		$this->arm_redirect_trap();

		try {
			Setup_Page::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertContains( 'page', $saved );
		$this->assertNotContains( 'nonexistent_type', $saved );
	}

	/**
	 * A nested-array element inside the post-types list is dropped before
	 * sanitization rather than tripping `sanitize_text_field`'s array-to-
	 * string warning (`failOnWarning` would turn that into a test failure
	 * here, and it would surface as a PHP notice in production). Real
	 * string elements alongside the nested junk still survive.
	 */
	public function test_handle_save_drops_nested_array_post_types(): void {
		$this->become_admin();

		$this->simulate_post(
			array(
				'activitypub_actor_mode'         => 'blog',
				'activitypub_support_post_types' => array(
					'post',
					array( 'malicious', 'nested' ),
					'page',
				),
			)
		);

		$this->arm_redirect_trap();

		try {
			Setup_Page::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertSame( array( 'post', 'page' ), $saved );
	}

	/**
	 * A non-array POST value for post types collapses cleanly to an empty
	 * list rather than tripping a type warning (`failOnWarning` would turn
	 * one into a test failure).
	 */
	public function test_handle_save_handles_non_array_post_types(): void {
		$this->become_admin();

		$this->simulate_post(
			array(
				'activitypub_actor_mode'         => 'blog',
				'activitypub_support_post_types' => 'not_an_array',
			)
		);

		$this->arm_redirect_trap();

		try {
			Setup_Page::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertIsArray( get_option( 'activitypub_support_post_types' ) );
	}

	/**
	 * The General post-types option is owned by Setup_Page itself, not by
	 * any provider — so the save lands even on a hypothetical install
	 * where ActivityPub is missing. Re-asserts the architectural contract
	 * that General writes don't depend on the AP provider being available.
	 */
	public function test_handle_save_persists_post_types_without_ap_provider(): void {
		Connection_Provider_Registry::reset();
		// Deliberately do NOT register AP so save_settings() can't write
		// the option indirectly. Bluesky stays registered but disconnected.
		Bluesky_Provider::register_provider();

		$this->become_admin();

		$this->simulate_post(
			array(
				'activitypub_support_post_types' => array( 'post', 'page' ),
			)
		);

		$this->arm_redirect_trap();

		try {
			Setup_Page::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( array( 'post', 'page' ), get_option( 'activitypub_support_post_types' ) );
	}

	/**
	 * A provider-side rejection (here: AP's blog-identifier sanitizer
	 * adding an error) suppresses the blanket `fosse_saved` success notice
	 * so the user isn't told everything saved while a sibling field
	 * silently failed. The provider's own error notice still surfaces.
	 */
	public function test_handle_save_suppresses_success_notice_on_provider_failure(): void {
		add_filter(
			'sanitize_option_activitypub_blog_identifier',
			static function ( $value ) {
				add_settings_error(
					'activitypub_blog_identifier',
					'forced_failure',
					'Forced rejection.',
					'error'
				);
				return $value;
			},
			11
		);

		$this->become_admin();

		$this->simulate_post(
			array(
				'activitypub_actor_mode'         => 'blog',
				'activitypub_support_post_types' => array( 'post' ),
				'activitypub_blog_identifier'    => 'whatever',
			)
		);

		$this->arm_redirect_trap();

		try {
			Setup_Page::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$codes = array_column( get_settings_errors( 'fosse' ), 'code' );
		$this->assertContains( 'forced_failure', $codes );
		$this->assertNotContains( 'fosse_saved', $codes );
	}

	/**
	 * Non-administrators cannot trigger the save handler; `wp_die` fires
	 * before any option write happens.
	 */
	public function test_handle_save_rejects_non_admin(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_setup_subscriber_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'subscriber',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );

		$this->simulate_post( array( 'activitypub_actor_mode' => 'blog' ) );

		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new \RuntimeException( wp_kses( (string) $message, array() ) );
				};
			}
		);

		$this->expectException( \RuntimeException::class );
		Setup_Page::handle_save();
	}

	/**
	 * Saved redirect lands back on the FOSSE Settings page so users see the
	 * post-save notice.
	 */
	public function test_handle_save_redirects_back_to_settings_page(): void {
		$this->become_admin();

		$this->simulate_post(
			array(
				'activitypub_actor_mode'         => 'blog',
				'activitypub_support_post_types' => array( 'post' ),
			)
		);

		$captured = $this->arm_redirect_trap();

		try {
			Setup_Page::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured->location );
		$this->assertStringContainsString( 'page=fosse', (string) $captured->location );
	}

	// --- render() — General section ---------------------------------------

	/**
	 * The Settings page heading reads "FOSSE Settings" (issue #75) so it
	 * matches the renamed sidebar entry.
	 */
	public function test_render_uses_settings_heading(): void {
		$this->become_admin();

		$output = $this->capture_render();

		$this->assertStringContainsString( 'FOSSE Settings', $output );
	}

	/**
	 * The unified Settings form posts to admin-post.php with the canonical
	 * action and a fresh nonce.
	 */
	public function test_render_emits_unified_form_with_save_action_and_nonce(): void {
		$this->become_admin();

		$output = $this->capture_render();

		$this->assertStringContainsString( 'id="fosse-settings"', $output );
		$this->assertStringContainsString( 'name="action" value="' . Setup_Page::SAVE_ACTION . '"', $output );
		$this->assertStringContainsString( 'name="_wpnonce"', $output );
		$this->assertStringContainsString( 'Save settings', $output );
	}

	/**
	 * The General section renders post-types checkboxes and (when AP is
	 * available) the actor-mode radios. These cross-protocol controls
	 * moved up from the per-provider sections in issue #36.
	 */
	public function test_render_general_section_contains_post_types_and_actor_mode(): void {
		$this->become_admin();

		$output = $this->capture_render();

		$this->assertStringContainsString( 'id="fosse-section-general"', $output );
		$this->assertStringContainsString( 'name="activitypub_support_post_types[]"', $output );
		$this->assertStringContainsString( 'name="activitypub_actor_mode"', $output );
	}

	// --- helpers ----------------------------------------------------------

	/**
	 * Render the Settings page and return its output.
	 *
	 * @return string
	 */
	private function capture_render(): string {
		ob_start();
		Setup_Page::render();
		return (string) ob_get_clean();
	}

	/**
	 * Seed the POST superglobal with `_wpnonce` and the supplied fields.
	 *
	 * @param array<string, mixed> $fields POST fields.
	 * @return void
	 */
	private function simulate_post( array $fields ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array_merge(
			array(
				'_wpnonce' => wp_create_nonce( Setup_Page::SAVE_ACTION ),
				'action'   => Setup_Page::SAVE_ACTION,
			),
			$fields
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Capture the next wp_redirect into a returned object's `location`.
	 *
	 * @return object
	 */
	private function arm_redirect_trap(): object {
		$capture           = new \stdClass();
		$capture->location = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( $capture ) {
				$capture->location = (string) $location;
				throw new RedirectFired( 'redirect' );
			}
		);
		return $capture;
	}

	/**
	 * Create and authenticate an administrator.
	 *
	 * @return void
	 */
	private function become_admin(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_setup_admin_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );
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
	 * Fail the test if the value is a WP_Error.
	 *
	 * @param mixed $value Value to check.
	 * @return void
	 */
	private function assertNotWPError( $value ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- camelCase mirrors PHPUnit's assertion style.
		if ( is_wp_error( $value ) ) {
			$this->fail( 'Unexpected WP_Error: ' . $value->get_error_message() );
		}
		$this->assertFalse( is_wp_error( $value ) );
	}
}
