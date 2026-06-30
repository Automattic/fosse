<?php
/**
 * Tests for the bundled-vs-standalone coexistence detection.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies fosse_detect_standalone() classifies coexistence states.
 *
 * The function backs fosse.php's decision to load (or suppress) the
 * bundled ActivityPub / Atmosphere copies. The critical case is a
 * standalone installed under a non-canonical folder name: it must be
 * detected via the active_plugins scan so the bundled copy is suppressed
 * and we never fatal on "Cannot redeclare".
 */
class StandaloneDetectionTest extends BaseTestCase {

	/**
	 * Reset active_plugins between tests so scans start clean.
	 *
	 * @before
	 */
	#[Before]
	public function clear_active_plugins(): void {
		delete_option( 'active_plugins' );
	}

	/**
	 * Leave no residue for other tests sharing the process.
	 *
	 * @after
	 */
	#[After]
	public function restore_active_plugins(): void {
		delete_option( 'active_plugins' );
	}

	/**
	 * A clean install with no standalone present returns '' so the bundled
	 * copy loads.
	 */
	public function test_no_standalone_returns_empty(): void {
		$this->assertSame(
			'',
			fosse_detect_standalone( 'FOSSE_TEST_NEVER_DEFINED_CONST', 'activitypub/activitypub.php' )
		);
	}

	/**
	 * A defined version constant means the standalone already loaded.
	 */
	public function test_defined_constant_reports_loaded(): void {
		// Deterministic per-test name: unique within the process (no other
		// test defines it) without random input that would vary across runs.
		$const = 'FOSSE_TEST_STANDALONE_VERSION_' . strtoupper( substr( md5( __METHOD__ ), 0, 8 ) );
		if ( ! defined( $const ) ) {
			define( $const, '1.0.0' );
		}

		$this->assertSame(
			'loaded',
			fosse_detect_standalone( $const, 'activitypub/activitypub.php' )
		);
	}

	/**
	 * The canonical active_plugins entry is detected as active.
	 */
	public function test_canonical_active_entry_reports_active(): void {
		update_option( 'active_plugins', array( 'activitypub/activitypub.php' ) );

		$this->assertSame(
			'active',
			fosse_detect_standalone( 'FOSSE_TEST_NEVER_DEFINED_CONST', 'activitypub/activitypub.php' )
		);
	}

	/**
	 * The regression case: a standalone under a non-canonical folder name
	 * (a GitHub clone at wordpress-activitypub/) is still detected as active
	 * via the suffix match, so the bundled copy is suppressed.
	 */
	public function test_noncanonical_folder_name_reports_active(): void {
		update_option( 'active_plugins', array( 'wordpress-activitypub/activitypub.php' ) );

		$this->assertSame(
			'active',
			fosse_detect_standalone( 'FOSSE_TEST_NEVER_DEFINED_CONST', 'activitypub/activitypub.php' )
		);
	}

	/**
	 * Atmosphere under its own non-canonical folder name is likewise caught.
	 */
	public function test_noncanonical_atmosphere_folder_reports_active(): void {
		update_option( 'active_plugins', array( 'wordpress-atmosphere/atmosphere.php' ) );

		$this->assertSame(
			'active',
			fosse_detect_standalone( 'FOSSE_TEST_NEVER_DEFINED_CONST', 'atmosphere/atmosphere.php' )
		);
	}

	/**
	 * An unrelated active plugin must not trip the suffix scan.
	 */
	public function test_unrelated_active_plugin_returns_empty(): void {
		update_option( 'active_plugins', array( 'some-other-plugin/some-other-plugin.php' ) );

		$this->assertSame(
			'',
			fosse_detect_standalone( 'FOSSE_TEST_NEVER_DEFINED_CONST', 'activitypub/activitypub.php' )
		);
	}

	/**
	 * A plugin whose folder merely matches the basename but whose own main
	 * file differs is not a false positive — the scan keys on the full
	 * "/activitypub.php" suffix, not the folder.
	 */
	public function test_unrelated_plugin_with_similar_basename_returns_empty(): void {
		update_option( 'active_plugins', array( 'activitypub-extras/activitypub-extras.php' ) );

		$this->assertSame(
			'',
			fosse_detect_standalone( 'FOSSE_TEST_NEVER_DEFINED_CONST', 'activitypub/activitypub.php' )
		);
	}
}
