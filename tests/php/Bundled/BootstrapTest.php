<?php
/**
 * Tests for the bundled-plugin first-load bootstrap.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Bundled;

use Automattic\Fosse\Bundled\Bootstrap;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies Bootstrap::maybe_run is idempotent and version-aware.
 */
class BootstrapTest extends BaseTestCase {

	const OPTION_KEY = 'fosse_test_bootstrap_marker';

	/**
	 * Reset the option between tests.
	 */
	#[Before]
	public function reset_option() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * First call invokes the activate callable and stores the version.
	 */
	public function test_first_call_invokes_activate_and_stores_version() {
		$calls = 0;
		$activate = static function () use ( &$calls ) {
			$calls++;
		};

		Bootstrap::maybe_run( self::OPTION_KEY, '1.2.3', $activate );

		$this->assertSame( 1, $calls, 'activate callable should run once on first call' );
		$this->assertSame( '1.2.3', get_option( self::OPTION_KEY ), 'option should be set to the version' );
	}

	/**
	 * Second call with the same version is a no-op.
	 */
	public function test_second_call_with_same_version_is_noop() {
		$calls = 0;
		$activate = static function () use ( &$calls ) {
			$calls++;
		};

		Bootstrap::maybe_run( self::OPTION_KEY, '1.2.3', $activate );
		Bootstrap::maybe_run( self::OPTION_KEY, '1.2.3', $activate );

		$this->assertSame( 1, $calls, 'activate callable should not re-run when version matches' );
	}

	/**
	 * A version mismatch re-invokes activate and updates the stored version.
	 */
	public function test_version_change_reinvokes_activate() {
		$calls = 0;
		$activate = static function () use ( &$calls ) {
			$calls++;
		};

		Bootstrap::maybe_run( self::OPTION_KEY, '1.2.3', $activate );
		Bootstrap::maybe_run( self::OPTION_KEY, '1.2.4', $activate );

		$this->assertSame( 2, $calls, 'activate callable should re-run when version differs' );
		$this->assertSame( '1.2.4', get_option( self::OPTION_KEY ), 'option should reflect the new version' );
	}
}
