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
		delete_option( 'activitypub_actor_mode' );
	}

	/**
	 * Clean up after each test.
	 *
	 * @after
	 */
	#[After]
	public function clean_up(): void {
		remove_all_filters( 'fosse_register_providers' );
		Connection_Provider_Registry::reset();
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
