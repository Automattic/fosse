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
 *
 * Each test uses a unique option key so the static-per-request guard
 * inside maybe_run doesn't leak state across tests (same PHP process).
 */
class BootstrapTest extends BaseTestCase {

	/**
	 * Generate a fresh option key per test to isolate static state.
	 *
	 * @var string
	 */
	private string $option_key;

	/**
	 * Ensure a clean option store per test.
	 */
	#[Before]
	public function seed_unique_option_key(): void {
		$this->option_key = 'fosse_test_bootstrap_' . uniqid( '', true );
		delete_option( $this->option_key );
	}

	/**
	 * First call invokes the activate callable and stores the version.
	 */
	public function test_first_call_invokes_activate_and_stores_version() {
		$calls    = 0;
		$activate = static function () use ( &$calls ) {
			$calls++;
		};

		Bootstrap::maybe_run( $this->option_key, '1.2.3', $activate );

		$this->assertSame( 1, $calls, 'activate callable should run once on first call' );
		$this->assertSame( '1.2.3', get_option( $this->option_key ), 'option should be set to the version' );
	}

	/**
	 * Second call with the same version is a no-op.
	 */
	public function test_second_call_with_same_version_is_noop() {
		$calls    = 0;
		$activate = static function () use ( &$calls ) {
			$calls++;
		};

		Bootstrap::maybe_run( $this->option_key, '1.2.3', $activate );
		Bootstrap::maybe_run( $this->option_key, '1.2.3', $activate );

		$this->assertSame( 1, $calls, 'activate callable should not re-run when version matches' );
	}

	/**
	 * A version mismatch re-invokes activate and updates the stored version.
	 */
	public function test_version_change_reinvokes_activate() {
		$calls    = 0;
		$activate = static function () use ( &$calls ) {
			$calls++;
		};

		Bootstrap::maybe_run( $this->option_key, '1.2.3', $activate );
		Bootstrap::maybe_run( $this->option_key, '1.2.4', $activate );

		$this->assertSame( 2, $calls, 'activate callable should re-run when version differs' );
		$this->assertSame( '1.2.4', get_option( $this->option_key ), 'option should reflect the new version' );
	}

	/**
	 * Even if the stored option vanishes mid-request (e.g. a DB write failure
	 * silently dropped our update), we do not re-invoke the activate callable
	 * again within the same request.
	 */
	public function test_static_flag_prevents_rerun_within_request_on_persist_failure() {
		$calls    = 0;
		$activate = static function () use ( &$calls ) {
			$calls++;
		};

		Bootstrap::maybe_run( $this->option_key, '1.2.3', $activate );

		// Simulate the option never persisting (DB error, read-only tablespace, etc).
		delete_option( $this->option_key );

		Bootstrap::maybe_run( $this->option_key, '1.2.3', $activate );

		$this->assertSame( 1, $calls, 'activate callable must not re-run within the same request, even if the option vanished' );
	}

	/**
	 * The flag is claimed (option written) BEFORE the activate callable runs,
	 * so a concurrent request can detect the claim and bail. We assert the
	 * ordering by reading the option from inside the callable.
	 */
	public function test_flag_is_claimed_before_activate_runs() {
		$value_seen_during_activate = null;
		$activate                   = function () use ( &$value_seen_during_activate ) {
			$value_seen_during_activate = get_option( $this->option_key );
		};

		Bootstrap::maybe_run( $this->option_key, '9.9.9', $activate );

		$this->assertSame(
			'9.9.9',
			$value_seen_during_activate,
			'the version flag must be persisted before activate() runs so a concurrent request can lose the add_option race and bail'
		);
	}

	/**
	 * Simulate a concurrent first-load request that already claimed the flag:
	 * the option exists with our exact version before maybe_run is called.
	 * This request must NOT run activate again (the early version-match guard
	 * handles it, mirroring the post-add_option-failure bail).
	 */
	public function test_preclaimed_flag_skips_activate() {
		// Another request won the add_option() race first.
		add_option( $this->option_key, '4.5.6', '', false );

		$calls    = 0;
		$activate = static function () use ( &$calls ) {
			$calls++;
		};

		Bootstrap::maybe_run( $this->option_key, '4.5.6', $activate );

		$this->assertSame( 0, $calls, 'activate must not run when the flag is already claimed at the current version' );
	}

	/**
	 * On a version change the stale flag is updated and activate re-runs —
	 * the add_option mutex is only the first-install guard, not a block on
	 * legitimate upgrade re-bootstraps.
	 */
	public function test_version_change_updates_flag_and_reruns() {
		add_option( $this->option_key, '1.0.0', '', false );

		$calls    = 0;
		$activate = static function () use ( &$calls ) {
			$calls++;
		};

		Bootstrap::maybe_run( $this->option_key, '2.0.0', $activate );

		$this->assertSame( 1, $calls, 'activate should re-run when the bundled version changed' );
		$this->assertSame( '2.0.0', get_option( $this->option_key ), 'the flag should be updated to the new version' );
	}
}
