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

	/**
	 * `uninstall.php` is the entrypoint WordPress calls when the plugin is
	 * deleted via the Plugins screen. It must exist at repo root, and it
	 * must bail when invoked outside the WP uninstall context (so a casual
	 * `php uninstall.php` doesn't wipe options).
	 */
	public function test_uninstall_php_exists_and_guards_on_wp_uninstall_plugin(): void {
		$uninstall_path = __DIR__ . '/../../uninstall.php';
		$this->assertFileExists( $uninstall_path, 'uninstall.php must exist at plugin root.' );

		$contents = file_get_contents( $uninstall_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a test, not remote HTTP.
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $contents, 'uninstall.php must guard on WP_UNINSTALL_PLUGIN.' );
	}
}
