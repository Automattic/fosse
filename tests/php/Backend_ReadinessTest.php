<?php
/**
 * Tests for the backend readiness probe.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Backend_Readiness;
use WorDBless\BaseTestCase;

/**
 * Exercises the version-constant + source-detection logic in isolation,
 * driven through a tiny harness over the private `evaluate()` /
 * `resolve_source()` methods.
 *
 * The public `activitypub_status()` / `atmosphere_status()` entry points
 * read `*_VERSION` and `*_PLUGIN_DIR` constants directly, which we can't
 * (un)define in a single PHP process — so we drive the underlying
 * decision logic via reflection on the private helpers and cover the
 * constant-reading entry points with one happy-path assertion using
 * whatever copy the test bootstrap has already loaded.
 */
class Backend_ReadinessTest extends BaseTestCase {

	/**
	 * Run the private `evaluate()` with explicit inputs.
	 *
	 * @param string      $slug          Plugin slug.
	 * @param string|null $version       Loaded version, or null.
	 * @param string|null $plugin_dir    Loaded plugin dir, or null.
	 * @param string      $min_version   Required minimum.
	 */
	private function evaluate( string $slug, ?string $version, ?string $plugin_dir, string $min_version ): array {
		$method = ( new \ReflectionClass( Backend_Readiness::class ) )->getMethod( 'evaluate' );

		return $method->invoke( null, $slug, $version, $plugin_dir, $min_version );
	}

	/**
	 * An undefined version constant means the backend isn't loaded at all.
	 */
	public function test_missing_when_version_constant_is_undefined(): void {
		$report = $this->evaluate( 'activitypub', null, null, '8.4.0' );

		$this->assertSame( Backend_Readiness::STATUS_MISSING, $report['status'] );
		$this->assertSame( Backend_Readiness::SOURCE_NONE, $report['source'] );
		$this->assertNull( $report['installed_version'] );
		$this->assertSame( '8.4.0', $report['required_version'] );
	}

	/**
	 * Bundled tag header lags the actual code; resolving as `bundled`
	 * short-circuits the version comparison so a stale tag header doesn't
	 * flip the report into too-old.
	 */
	public function test_bundled_source_is_trusted_regardless_of_constant_version(): void {
		// Bundled tag header lags the actual code; the version constant
		// can be older than the minimum even though the bundle ships the
		// surface FOSSE needs. Resolving as `bundled` short-circuits the
		// version comparison.
		$bundled_dir = realpath( __DIR__ . '/../../bundled/activitypub' );
		$this->assertNotFalse( $bundled_dir, 'Bundled AP dir must exist for this test.' );

		$report = $this->evaluate( 'activitypub', '8.3.0', $bundled_dir, '8.4.0' );

		$this->assertSame( Backend_Readiness::STATUS_OK, $report['status'] );
		$this->assertSame( Backend_Readiness::SOURCE_BUNDLED, $report['source'] );
		$this->assertSame( '8.3.0', $report['installed_version'] );
	}

	/**
	 * Standalone install below the floor reports too-old, not OK.
	 */
	public function test_standalone_too_old_when_below_minimum(): void {
		$report = $this->evaluate(
			'activitypub',
			'8.3.0',
			'/var/www/html/wp-content/plugins/activitypub',
			'8.4.0'
		);

		$this->assertSame( Backend_Readiness::STATUS_TOO_OLD, $report['status'] );
		$this->assertSame( Backend_Readiness::SOURCE_STANDALONE, $report['source'] );
		$this->assertSame( '8.3.0', $report['installed_version'] );
	}

	/**
	 * Standalone install at or above the floor reports OK.
	 */
	public function test_standalone_ok_when_at_or_above_minimum(): void {
		$report = $this->evaluate(
			'atmosphere',
			'1.1.0',
			'/var/www/html/wp-content/plugins/atmosphere',
			'1.1.0'
		);

		$this->assertSame( Backend_Readiness::STATUS_OK, $report['status'] );
		$this->assertSame( Backend_Readiness::SOURCE_STANDALONE, $report['source'] );
	}

	/**
	 * A standalone strictly above the floor reports OK (not just the equal
	 * boundary covered above).
	 */
	public function test_standalone_ok_when_above_minimum(): void {
		$report = $this->evaluate(
			'activitypub',
			'9.9.9',
			'/var/www/html/wp-content/plugins/activitypub',
			'9.0.2'
		);

		$this->assertSame( Backend_Readiness::STATUS_OK, $report['status'] );
		$this->assertSame( Backend_Readiness::SOURCE_STANDALONE, $report['source'] );
	}

	/**
	 * A backend loaded from a resolvable path that is neither the bundled
	 * tree nor under WP_PLUGIN_DIR (an mu-plugin, platform shim, or symlink
	 * outside the plugins dir) is reported `incompatible` — FOSSE can't
	 * vouch for its surface, so it must not silently report OK.
	 */
	public function test_incompatible_when_loaded_outside_bundled_and_plugins(): void {
		$outside = realpath( sys_get_temp_dir() );
		$this->assertNotFalse( $outside, 'System temp dir must resolve for this test.' );

		$report = $this->evaluate( 'activitypub', '9.9.9', $outside, '9.0.2' );

		$this->assertSame( Backend_Readiness::STATUS_INCOMPATIBLE, $report['status'] );
		$this->assertSame( Backend_Readiness::SOURCE_UNKNOWN, $report['source'] );
	}

	/**
	 * A RESOLVABLE plugin dir under WP_PLUGIN_DIR is classified `standalone`
	 * via the real path-prefix match (not the unresolvable-path fallback the
	 * other standalone tests hit), then floor-enforced. Uses WP_PLUGIN_DIR
	 * itself — a path that resolves and satisfies the prefix match by equality
	 * — so no fixture directory has to be created (keeps the test free of
	 * WP_Filesystem-flagged mkdir/rmdir calls). Exercises the live
	 * `path_within( $loaded, WP_PLUGIN_DIR )` branch end-to-end.
	 */
	public function test_resolvable_plugin_dir_is_detected_as_standalone(): void {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || false === realpath( WP_PLUGIN_DIR ) ) {
			$this->markTestSkipped( 'WP_PLUGIN_DIR does not resolve in this environment.' );
		}

		$report = $this->evaluate( 'activitypub', '8.0.0', WP_PLUGIN_DIR, '9.0.2' );

		$this->assertSame( Backend_Readiness::SOURCE_STANDALONE, $report['source'] );
		$this->assertSame( Backend_Readiness::STATUS_TOO_OLD, $report['status'] );
	}

	/**
	 * Defensive: a missing `*_PLUGIN_DIR` constant falls back to standalone
	 * (enforce the floor) rather than silently trusting an unknown load as
	 * bundled.
	 */
	public function test_resolve_source_unknown_dir_falls_back_to_standalone(): void {
		// Defensive: if a backend defines its version constant but not its
		// `*_PLUGIN_DIR` constant, treat the load as standalone (enforce
		// the version floor) rather than silently trusting it as bundled.
		$report = $this->evaluate( 'activitypub', '8.0.0', null, '8.4.0' );

		$this->assertSame( Backend_Readiness::SOURCE_STANDALONE, $report['source'] );
		$this->assertSame( Backend_Readiness::STATUS_TOO_OLD, $report['status'] );
	}

	/**
	 * `all()` returns a report for each backend, keyed by slug.
	 */
	public function test_all_aggregates_both_backends(): void {
		$reports = Backend_Readiness::all();

		$this->assertArrayHasKey( 'activitypub', $reports );
		$this->assertArrayHasKey( 'atmosphere', $reports );
		$this->assertSame( 'activitypub', $reports['activitypub']['slug'] );
		$this->assertSame( 'atmosphere', $reports['atmosphere']['slug'] );
	}

	/**
	 * Smoke test: the constant-reading entry points return a well-formed
	 * report shape for whatever copy the bootstrap loaded.
	 */
	public function test_live_constants_report_a_known_status(): void {
		// We can't safely mutate constants in-process, so this test just
		// asserts the entry points return a well-formed report shape for
		// whatever the bootstrap loaded.
		$report = Backend_Readiness::activitypub_status();
		$this->assertContains(
			$report['status'],
			array(
				Backend_Readiness::STATUS_OK,
				Backend_Readiness::STATUS_MISSING,
				Backend_Readiness::STATUS_TOO_OLD,
				Backend_Readiness::STATUS_INCOMPATIBLE,
			)
		);
		$this->assertSame( 'activitypub', $report['slug'] );
		$this->assertSame( Backend_Readiness::min_activitypub_version(), $report['required_version'] );
	}

	/**
	 * The standalone floor is derived from the bundled plugin's own
	 * `Version:` header, so it can never drift from what `tools/sync-bundled.sh`
	 * actually ships. Read the headers independently and assert the accessors
	 * match — this is the regression guard that keeps the floor honest across
	 * bundle resyncs.
	 */
	public function test_floors_match_the_bundled_version_headers(): void {
		$ap_header   = get_file_data(
			__DIR__ . '/../../bundled/activitypub/activitypub.php',
			array( 'Version' => 'Version' )
		);
		$atmo_header = get_file_data(
			__DIR__ . '/../../bundled/atmosphere/atmosphere.php',
			array( 'Version' => 'Version' )
		);

		$this->assertNotSame( '', (string) $ap_header['Version'], 'Bundled AP header must carry a Version.' );
		$this->assertNotSame( '', (string) $atmo_header['Version'], 'Bundled Atmosphere header must carry a Version.' );

		$this->assertSame( (string) $ap_header['Version'], Backend_Readiness::min_activitypub_version() );
		$this->assertSame( (string) $atmo_header['Version'], Backend_Readiness::min_atmosphere_version() );
	}
}
