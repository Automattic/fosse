<?php
/**
 * Tests for Provider_Loader.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Admin\AP_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use Automattic\Fosse\Provider_Loader;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies that Provider_Loader boots providers and attaches hooks
 * unconditionally — not gated behind is_admin().
 */
class Provider_LoaderTest extends BaseTestCase {

	/**
	 * Clean state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		Connection_Provider_Registry::reset();
		delete_option( 'fosse_ap_actor_mode' );
		delete_option( 'activitypub_actor_mode' );

		// Remove any lingering projection filters from prior tests.
		remove_all_filters( 'pre_option_activitypub_actor_mode' );
		remove_all_filters( 'pre_option_activitypub_support_post_types' );
	}

	/**
	 * Clean up filters after each test.
	 *
	 * @after
	 */
	#[After]
	public function clean_filters(): void {
		remove_all_filters( 'pre_option_activitypub_actor_mode' );
		remove_all_filters( 'pre_option_activitypub_support_post_types' );
		remove_all_filters( 'fosse_register_providers' );
		Connection_Provider_Registry::reset();
	}

	/**
	 * Projection filters are active after boot() without is_admin().
	 *
	 * This is the regression test for the bug where fosse.php gated
	 * provider hooks behind is_admin(), causing AP to read its own
	 * stored options on front-end, REST, WebFinger, and cron requests.
	 */
	public function test_projection_active_after_boot_without_is_admin() {
		// Simulate the provider init that runs in fosse.php.
		AP_Provider::init();
		Provider_Loader::boot();

		// Set a FOSSE option.
		update_option( 'fosse_ap_actor_mode', 'blog' );

		// AP should read FOSSE's value via the projection filter.
		$this->assertSame( 'blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Providers are registered in the registry after boot().
	 */
	public function test_providers_registered_after_boot() {
		AP_Provider::init();
		Provider_Loader::boot();

		$this->assertNotNull( Connection_Provider_Registry::get_provider( 'activitypub' ) );
	}
}
