<?php
/**
 * Tests for AP_Provider.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\AP_Provider;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies AP_Provider metadata, status shape, and save handling.
 */
class AP_ProviderTest extends BaseTestCase {

	/**
	 * Provider instance under test.
	 *
	 * @var AP_Provider
	 */
	private AP_Provider $provider;

	/**
	 * Set up a fresh provider and clean option state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_provider(): void {
		$this->provider = new AP_Provider();

		delete_option( 'activitypub_actor_mode' );
		delete_option( 'activitypub_support_post_types' );

		// Clear stale settings errors from prior tests.
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core global reset for testing.

		$this->provider->register_hooks();
	}

	/**
	 * Slug is 'activitypub'.
	 */
	public function test_slug() {
		$this->assertSame( 'activitypub', $this->provider->get_slug() );
	}

	/**
	 * Display name is 'ActivityPub'.
	 */
	public function test_name() {
		$this->assertSame( 'ActivityPub', $this->provider->get_name() );
	}

	/**
	 * Status array contains the expected keys.
	 */
	public function test_status_has_expected_shape() {
		$status = $this->provider->get_status();

		$this->assertArrayHasKey( 'connected', $status );
		$this->assertArrayHasKey( 'actor_mode', $status );
		$this->assertArrayHasKey( 'post_types', $status );
		$this->assertArrayHasKey( 'address', $status );
	}

	/**
	 * AP is always "connected" when the plugin is loaded.
	 */
	public function test_status_always_connected() {
		$this->assertTrue( $this->provider->get_status()['connected'] );
	}

	/**
	 * Default actor mode is 'actor'.
	 */
	public function test_status_default_actor_mode() {
		$this->assertSame( 'actor', $this->provider->get_status()['actor_mode'] );
	}

	/**
	 * Default post types is array('post').
	 */
	public function test_status_default_post_types() {
		$this->assertSame( array( 'post' ), $this->provider->get_status()['post_types'] );
	}

	/**
	 * Status reflects the stored actor mode.
	 */
	public function test_status_reflects_stored_actor_mode() {
		update_option( 'activitypub_actor_mode', 'actor_blog' );

		$this->assertSame( 'actor_blog', $this->provider->get_status()['actor_mode'] );
	}

	/**
	 * Status reflects the stored post types.
	 */
	public function test_status_reflects_stored_post_types() {
		update_option( 'activitypub_support_post_types', array( 'page' ) );

		$this->assertSame( array( 'page' ), $this->provider->get_status()['post_types'] );
	}

	/**
	 * Setup section carries the fragment target id used by the Status-page
	 * "Manage ActivityPub settings" deep link. Renaming the id without
	 * updating the link would silently break navigation.
	 */
	public function test_render_setup_section_has_anchor_id() {
		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="fosse-provider-activitypub"', $output );
	}

	/**
	 * Status card deep-links back to the ActivityPub setup section. The
	 * fragment must match the id rendered by render_setup_section().
	 */
	public function test_render_status_card_has_manage_settings_link() {
		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Manage ActivityPub settings', $output );
		$this->assertStringContainsString( '#fosse-provider-activitypub', $output );
	}

	/**
	 * Setup UI explains the available actor modes and links to blog profile settings.
	 */
	public function test_render_setup_section_explains_actor_modes() {
		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Each WordPress author publishes from their own fediverse profile.', $output );
		$this->assertStringContainsString( 'One site-wide profile publishes every post, regardless of author.', $output );
		$this->assertStringContainsString( 'Authors keep individual profiles, and the site also has its own blog profile.', $output );
		$this->assertStringContainsString( 'Changing modes does not move followers between profiles.', $output );
		$this->assertStringContainsString( 'Configure the site-wide blog profile name, image, and description', $output );
		$this->assertStringContainsString( '<fieldset aria-describedby="fosse-activitypub-actor-mode-note">', $output );
		$this->assertStringContainsString( '<legend class="screen-reader-text">Actor Mode</legend>', $output );
		$this->assertMatchesRegularExpression(
			'~<input[^>]+id="fosse-activitypub-actor-mode-actor"[^>]+aria-describedby="fosse-activitypub-actor-mode-actor-desc fosse-activitypub-actor-mode-note"[^>]+/>~',
			$output
		);
		$this->assertMatchesRegularExpression(
			'~<input[^>]+id="fosse-activitypub-actor-mode-blog"[^>]+aria-describedby="fosse-activitypub-actor-mode-blog-desc fosse-activitypub-actor-mode-note"[^>]+/>~',
			$output
		);
		$this->assertMatchesRegularExpression(
			'~<input[^>]+id="fosse-activitypub-actor-mode-actor-blog"[^>]+aria-describedby="fosse-activitypub-actor-mode-actor-blog-desc fosse-activitypub-actor-mode-note"[^>]+/>~',
			$output
		);
		$this->assertStringContainsString( 'id="fosse-activitypub-actor-mode-actor-desc"', $output );
		$this->assertStringContainsString( 'id="fosse-activitypub-actor-mode-blog-desc"', $output );
		$this->assertStringContainsString( 'id="fosse-activitypub-actor-mode-actor-blog-desc"', $output );
		$this->assertStringContainsString( '<p id="fosse-activitypub-actor-mode-note" class="description">', $output );
		$this->assertStringContainsString( '<div class="fosse-activitypub-actor-mode-note">', $output );
		$this->assertMatchesRegularExpression(
			'~<a href="[^"]*options-general\.php\?page=activitypub(?:&#038;|&amp;|&)tab=blog-profile">Blog profile settings</a>~',
			$output
		);
	}

	// --- handle_save tests ---------------------------------------------------

	/**
	 * Create an admin user and set up a simulated save request.
	 *
	 * @param array<string, mixed> $post_data POST data to merge in.
	 * @return void
	 */
	private function simulate_save_request( array $post_data = array() ): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_admin_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$defaults = array(
			'action'                         => 'fosse_save_ap_settings',
			'_wpnonce'                       => wp_create_nonce( 'fosse_save_ap_settings' ),
			'activitypub_actor_mode'         => 'blog',
			'activitypub_support_post_types' => array( 'post' ),
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup, nonce is in the data.
		$_POST    = array_merge( $defaults, $post_data );
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Catch the redirect so exit doesn't kill the test.
		add_filter(
			'wp_redirect',
			static function () {
				throw new \Exception( 'redirect' );
			}
		);
	}

	/**
	 * Valid save stores the actor mode option.
	 */
	public function test_handle_save_stores_actor_mode() {
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'actor_blog' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor_blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Valid save stores the post types option.
	 */
	public function test_handle_save_stores_post_types() {
		$this->simulate_save_request( array( 'activitypub_support_post_types' => array( 'post', 'page' ) ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( array( 'post', 'page' ), get_option( 'activitypub_support_post_types' ) );
	}

	/**
	 * Invalid actor mode is rejected — option is not updated.
	 */
	public function test_handle_save_rejects_invalid_actor_mode() {
		update_option( 'activitypub_actor_mode', 'actor' );
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'evil_mode' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Invalid actor mode produces an error notice, not a success notice.
	 */
	public function test_handle_save_error_notice_on_invalid_mode() {
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'evil_mode' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors = get_settings_errors( 'fosse' );
		$codes  = array_column( $errors, 'code' );
		$this->assertContains( 'fosse_invalid_mode', $codes );
		$this->assertNotContains( 'fosse_saved', $codes );
	}

	/**
	 * Valid save notifies AP's scheduler so federation propagates the mode
	 * change. WordPress fires add_option_<name> on first save and
	 * update_option_<name> on subsequent value changes; AP hooks both.
	 */
	public function test_handle_save_notifies_ap_actor_mode_scheduler() {
		$fired = false;
		$mark  = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'add_option_activitypub_actor_mode', $mark );
		add_action( 'update_option_activitypub_actor_mode', $mark );

		// First save (option does not yet exist) fires add_option_*.
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'blog' ) );
		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}
		$this->assertTrue( $fired, 'add_option_activitypub_actor_mode should fire on first save.' );

		// Second save (value change) fires update_option_*.
		$fired = false;
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'actor_blog' ) );
		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}
		$this->assertTrue( $fired, 'update_option_activitypub_actor_mode should fire on value change.' );
	}

	/**
	 * Invalid post types are filtered out.
	 */
	public function test_handle_save_filters_invalid_post_types() {
		$this->simulate_save_request(
			array( 'activitypub_support_post_types' => array( 'post', 'nonexistent_type', 'page' ) )
		);

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertContains( 'page', $saved );
		$this->assertNotContains( 'nonexistent_type', $saved );
	}

	/**
	 * Non-array post types input is safely handled.
	 */
	public function test_handle_save_handles_non_array_post_types() {
		$this->simulate_save_request( array( 'activitypub_support_post_types' => 'not_an_array' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertIsArray( get_option( 'activitypub_support_post_types' ) );
	}
}
