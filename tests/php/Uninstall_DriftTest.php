<?php
/**
 * Drift-detection: the procedural fallback in `uninstall.php` must list the
 * same FOSSE-owned options, transients, prefix, and user meta as
 * `Automattic\Fosse\Lifecycle`.
 *
 * Why this test exists: `uninstall.php` runs without the autoloader (the
 * fallback path is exactly the "vendor/ is missing" case the whole fallback
 * exists to handle), so it inlines the canonical lists rather than pulling
 * them from the `Lifecycle` class. Without an automated check, a sibling SDD
 * adding a new FOSSE-owned key to `Lifecycle::FOSSE_OWNED_OPTIONS` could miss
 * the `uninstall.php` mirror and silently leak rows on broken installs — the
 * exact scenario the fallback is meant to protect against.
 *
 * The test parses `uninstall.php`'s literal array contents and asserts they
 * match the corresponding `Lifecycle` constants exactly. Adding a key to
 * `Lifecycle` without updating the fallback turns into a hard test failure.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Lifecycle;
use WorDBless\BaseTestCase;

/**
 * Parses uninstall.php's hardcoded arrays and compares against Lifecycle.
 */
class Uninstall_DriftTest extends BaseTestCase {

	/**
	 * Path to the file under test.
	 *
	 * @return string
	 */
	private function uninstall_php_path(): string {
		return __DIR__ . '/../../uninstall.php';
	}

	/**
	 * Read `uninstall.php` into memory once per assertion.
	 *
	 * @return string
	 */
	private function uninstall_php_contents(): string {
		$contents = file_get_contents( $this->uninstall_php_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a test, not remote HTTP.
		$this->assertNotFalse( $contents, 'uninstall.php must be readable for drift checks.' );
		return (string) $contents;
	}

	/**
	 * Extract the string-literal entries from a PHP `array( ... )` block
	 * assigned to the given variable name. Tolerant of whitespace and
	 * comments inside the array body.
	 *
	 * @param string $contents Full PHP source.
	 * @param string $var_name Variable name (without `$`) whose array literal to extract.
	 * @return string[] Extracted string-literal entries in source order.
	 */
	private function extract_array_literals( string $contents, string $var_name ): array {
		$pattern = '/\$' . preg_quote( $var_name, '/' ) . '\s*=\s*array\s*\((.*?)\)\s*;/s';
		$this->assertSame( 1, preg_match( $pattern, $contents, $match ), "Could not locate \${$var_name} array literal in uninstall.php." );

		preg_match_all( "/'([^']+)'/", $match[1], $entries );
		return $entries[1];
	}

	/**
	 * The procedural option list in uninstall.php must match
	 * `Lifecycle::FOSSE_OWNED_OPTIONS` exactly (order matters for review
	 * diff hygiene).
	 */
	public function test_uninstall_options_match_lifecycle_constant(): void {
		$contents  = $this->uninstall_php_contents();
		$fallback  = $this->extract_array_literals( $contents, 'fosse_owned_options' );
		$canonical = Lifecycle::FOSSE_OWNED_OPTIONS;

		$this->assertSame(
			$canonical,
			$fallback,
			'uninstall.php $fosse_owned_options has drifted from Lifecycle::FOSSE_OWNED_OPTIONS. Update both in lockstep.'
		);
	}

	/**
	 * The procedural transient list must match
	 * `Lifecycle::FOSSE_OWNED_TRANSIENTS` exactly.
	 */
	public function test_uninstall_transients_match_lifecycle_constant(): void {
		$contents  = $this->uninstall_php_contents();
		$fallback  = $this->extract_array_literals( $contents, 'fosse_owned_transients' );
		$canonical = Lifecycle::FOSSE_OWNED_TRANSIENTS;

		$this->assertSame(
			$canonical,
			$fallback,
			'uninstall.php $fosse_owned_transients has drifted from Lifecycle::FOSSE_OWNED_TRANSIENTS. Update both in lockstep.'
		);
	}

	/**
	 * The wildcard transient prefix must match
	 * `Lifecycle::FOSSE_TRANSIENT_PREFIX`.
	 */
	public function test_uninstall_transient_prefix_matches_lifecycle_constant(): void {
		$contents = $this->uninstall_php_contents();
		$this->assertSame(
			1,
			preg_match( "/\\\$fosse_transient_prefix\\s*=\\s*'([^']+)'\\s*;/", $contents, $match ),
			'Could not locate $fosse_transient_prefix literal in uninstall.php.'
		);

		$this->assertSame(
			Lifecycle::FOSSE_TRANSIENT_PREFIX,
			$match[1],
			'uninstall.php $fosse_transient_prefix has drifted from Lifecycle::FOSSE_TRANSIENT_PREFIX. Update both in lockstep.'
		);
	}

	/**
	 * Every FOSSE-owned user meta key in `Lifecycle::FOSSE_OWNED_USER_META`
	 * must be referenced by a literal `delete_metadata( 'user', 0, '<key>', '', true )`
	 * call inside uninstall.php.
	 */
	public function test_uninstall_user_meta_keys_match_lifecycle_constant(): void {
		$contents = $this->uninstall_php_contents();
		preg_match_all( "/delete_metadata\\(\\s*'user'\\s*,\\s*0\\s*,\\s*'([^']+)'\\s*,\\s*''\\s*,\\s*true\\s*\\)\\s*;/", $contents, $matches );

		$this->assertSame(
			Lifecycle::FOSSE_OWNED_USER_META,
			$matches[1],
			'uninstall.php user meta cleanup has drifted from Lifecycle::FOSSE_OWNED_USER_META. Update both in lockstep.'
		);
	}
}
