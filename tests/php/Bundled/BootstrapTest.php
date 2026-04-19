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
}
