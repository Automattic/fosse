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
}
