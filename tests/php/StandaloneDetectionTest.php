<?php
/**
 * Tests for activation-based standalone-backend detection.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies `fosse_plugin_is_active()` — the activation-only check that backs
 * fosse.php's decision to load (or skip) the bundled ActivityPub / Atmosphere
 * copies. The critical case is a standalone activated under a NON-CANONICAL
 * folder name: it must be detected via the `active_plugins` scan so the
 * bundled copy is not loaded and we never fatal on "Cannot redeclare".
 *
 * Detection is activation-only by design (per PR #228): a merely-installed but
 * inactive standalone does NOT suppress the bundle — federation keeps working
 * via the bundled copy — so there are no on-disk / inactive cases here.
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
	 * No standalone active: not detected, so the bundled copy loads.
	 */
	public function test_no_active_standalone_is_not_detected(): void {
		$this->assertFalse( fosse_plugin_is_active( 'activitypub/activitypub.php' ) );
	}

	/**
	 * The canonical active_plugins entry is detected.
	 */
	public function test_canonical_active_entry_is_detected(): void {
		update_option( 'active_plugins', array( 'activitypub/activitypub.php' ) );

		$this->assertTrue( fosse_plugin_is_active( 'activitypub/activitypub.php' ) );
	}

	/**
	 * The regression case: a standalone active under a non-canonical folder
	 * name (a GitHub clone at `wordpress-activitypub/`) is still detected via
	 * the basename suffix match, so FOSSE does not also load the bundled copy.
	 */
	public function test_noncanonical_folder_name_is_detected(): void {
		update_option( 'active_plugins', array( 'wordpress-activitypub/activitypub.php' ) );

		$this->assertTrue( fosse_plugin_is_active( 'activitypub/activitypub.php' ) );
	}

	/**
	 * Atmosphere under its own non-canonical folder name is likewise caught.
	 */
	public function test_noncanonical_atmosphere_folder_is_detected(): void {
		update_option( 'active_plugins', array( 'wordpress-atmosphere/atmosphere.php' ) );

		$this->assertTrue( fosse_plugin_is_active( 'atmosphere/atmosphere.php' ) );
	}

	/**
	 * An unrelated active plugin must not trip the suffix scan.
	 */
	public function test_unrelated_active_plugin_is_not_detected(): void {
		update_option( 'active_plugins', array( 'some-other-plugin/some-other-plugin.php' ) );

		$this->assertFalse( fosse_plugin_is_active( 'activitypub/activitypub.php' ) );
	}

	/**
	 * A plugin whose folder merely resembles the slug but whose main file
	 * differs is not a false positive — the scan keys on the `/activitypub.php`
	 * suffix, not the folder name.
	 */
	public function test_unrelated_plugin_with_similar_basename_is_not_detected(): void {
		update_option( 'active_plugins', array( 'activitypub-extras/activitypub-extras.php' ) );

		$this->assertFalse( fosse_plugin_is_active( 'activitypub/activitypub.php' ) );
	}
}
