<?php
/**
 * Tests for AP_Provider.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\AP_Provider;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies AP_Provider metadata, status shape, and option projection.
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

		delete_option( 'fosse_ap_actor_mode' );
		delete_option( 'fosse_ap_support_post_types' );
		delete_option( 'activitypub_actor_mode' );
		delete_option( 'activitypub_support_post_types' );

		$this->provider->register_hooks();
	}

	/**
	 * Remove projection filters after each test.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_filters(): void {
		remove_filter( 'pre_option_activitypub_actor_mode', array( $this->provider, 'project_actor_mode' ), 20 );
		remove_filter( 'pre_option_activitypub_support_post_types', array( $this->provider, 'project_post_types' ), 20 );
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
	 * Setting fosse_ap_actor_mode projects to activitypub_actor_mode.
	 */
	public function test_projection_sets_ap_actor_mode() {
		update_option( 'fosse_ap_actor_mode', 'blog' );

		$this->assertSame( 'blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Setting fosse_ap_support_post_types projects to activitypub_support_post_types.
	 */
	public function test_projection_sets_ap_post_types() {
		update_option( 'fosse_ap_support_post_types', array( 'post', 'page' ) );

		$this->assertSame( array( 'post', 'page' ), get_option( 'activitypub_support_post_types' ) );
	}

	/**
	 * When no FOSSE option exists, AP's own stored value is returned.
	 */
	public function test_projection_falls_through_when_fosse_option_absent() {
		update_option( 'activitypub_actor_mode', 'actor_blog' );

		$this->assertSame( 'actor_blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * An earlier pre_option filter (e.g. AP's constant override) takes
	 * precedence over FOSSE's projection.
	 */
	public function test_projection_respects_earlier_filter() {
		// Simulate AP's own constant-based override at priority 10.
		$constant_override = static fn() => 'blog';
		add_filter( 'pre_option_activitypub_actor_mode', $constant_override, 10 );

		update_option( 'fosse_ap_actor_mode', 'actor' );

		// AP's constant override should win over FOSSE's value.
		$this->assertSame( 'blog', get_option( 'activitypub_actor_mode' ) );

		remove_filter( 'pre_option_activitypub_actor_mode', $constant_override, 10 );
	}

	/**
	 * Status reflects the projected actor mode.
	 */
	public function test_status_reflects_projected_actor_mode() {
		update_option( 'fosse_ap_actor_mode', 'actor_blog' );

		$this->assertSame( 'actor_blog', $this->provider->get_status()['actor_mode'] );
	}

	/**
	 * Status reflects the projected post types.
	 */
	public function test_status_reflects_projected_post_types() {
		update_option( 'fosse_ap_support_post_types', array( 'page' ) );

		$this->assertSame( array( 'page' ), $this->provider->get_status()['post_types'] );
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
			'action'                      => 'fosse_save_ap_settings',
			'_wpnonce'                    => wp_create_nonce( 'fosse_save_ap_settings' ),
			'fosse_ap_actor_mode'         => 'blog',
			'fosse_ap_support_post_types' => array( 'post' ),
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
		$this->simulate_save_request( array( 'fosse_ap_actor_mode' => 'actor_blog' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor_blog', get_option( 'fosse_ap_actor_mode' ) );
	}

	/**
	 * Valid save stores the post types option.
	 */
	public function test_handle_save_stores_post_types() {
		$this->simulate_save_request( array( 'fosse_ap_support_post_types' => array( 'post', 'page' ) ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( array( 'post', 'page' ), get_option( 'fosse_ap_support_post_types' ) );
	}

	/**
	 * Invalid actor mode is rejected — option is not updated.
	 */
	public function test_handle_save_rejects_invalid_actor_mode() {
		update_option( 'fosse_ap_actor_mode', 'actor' );
		$this->simulate_save_request( array( 'fosse_ap_actor_mode' => 'evil_mode' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor', get_option( 'fosse_ap_actor_mode' ) );
	}

	/**
	 * Invalid post types are filtered out.
	 */
	public function test_handle_save_filters_invalid_post_types() {
		$this->simulate_save_request(
			array( 'fosse_ap_support_post_types' => array( 'post', 'nonexistent_type', 'page' ) )
		);

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'fosse_ap_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertContains( 'page', $saved );
		$this->assertNotContains( 'nonexistent_type', $saved );
	}

	/**
	 * Non-array post types input is safely handled.
	 */
	public function test_handle_save_handles_non_array_post_types() {
		$this->simulate_save_request( array( 'fosse_ap_support_post_types' => 'not_an_array' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertIsArray( get_option( 'fosse_ap_support_post_types' ) );
	}
}
