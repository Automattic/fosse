<?php
/**
 * Tests for Atmosphere_Provider.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Atmosphere_Provider;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

// Stub the Atmosphere plugin class so is_available() can exercise its
// positive branch. WorDBless doesn't load bundled plugins.
if ( ! class_exists( '\Atmosphere\Atmosphere' ) ) {
	require_once __DIR__ . '/fixtures/atmosphere-stub.php';
}

/**
 * Verifies Atmosphere_Provider metadata, availability, and status shape.
 */
class Atmosphere_ProviderTest extends BaseTestCase {

	/**
	 * Provider instance under test.
	 *
	 * @var Atmosphere_Provider
	 */
	private Atmosphere_Provider $provider;

	/**
	 * Set up a fresh provider before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_provider(): void {
		$this->provider = new Atmosphere_Provider();
	}

	/**
	 * Slug is 'atmosphere'.
	 */
	public function test_slug() {
		$this->assertSame( 'atmosphere', $this->provider->get_slug() );
	}

	/**
	 * Display name is 'Bluesky'.
	 */
	public function test_name() {
		$this->assertSame( 'Bluesky', $this->provider->get_name() );
	}

	/**
	 * Returns true when the Atmosphere class is loaded.
	 */
	public function test_is_available_when_atmosphere_loaded() {
		$this->assertTrue( $this->provider->is_available() );
	}

	/**
	 * Status reports disconnected and includes a user-facing message.
	 */
	public function test_status_reports_disconnected_with_message() {
		$status = $this->provider->get_status();

		$this->assertArrayHasKey( 'connected', $status );
		$this->assertFalse( $status['connected'] );
		$this->assertArrayHasKey( 'message', $status );
		$this->assertNotEmpty( $status['message'] );
	}
}
