<?php
/**
 * Smoke test: the plugin file loads under the test bootstrap.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use WorDBless\BaseTestCase;

/**
 * Verifies the plugin bootstraps under WorDBless.
 */
class PluginLoadsTest extends BaseTestCase {

	/**
	 * The plugin header (Name, TextDomain, …) is parseable by WordPress.
	 */
	public function test_plugin_header_is_readable() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( __DIR__ . '/../../fosse.php', false, false );

		$this->assertSame( 'FOSSE', $data['Name'] );
		$this->assertSame( 'fosse', $data['TextDomain'] );
	}
}
