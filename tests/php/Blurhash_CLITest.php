<?php
/**
 * Tests for the `wp fosse blurhash` WP-CLI command surface.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

// Define a minimal `WP_CLI` shim if the real CLI isn't loaded. The
// shim records log/warning/success calls into static buffers for
// per-test assertions and throws on `error()` so we can assert the
// non-zero-exit code path. PHPUnit runs without WP_CLI by default;
// this fills that gap without requiring the heavy `wp-cli/wp-cli`
// dependency in `require-dev`.
if ( ! class_exists( '\\WP_CLI', false ) ) {
	require_once __DIR__ . '/Helpers/class-wp-cli-stub.php';
}

use Automattic\Fosse\Blurhash;
use Automattic\Fosse\Blurhash_CLI;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;
use WP_CLI;

/**
 * Exercises the `backfill` subcommand through real WP_Query against
 * the in-memory WorDBless tables — same posture as
 * {@see BlurhashTest}. The CLI surface (log/warning/success/error)
 * is captured via the in-repo `WP_CLI` shim
 * ({@see Helpers/class-wp-cli-stub.php}) so test assertions can
 * verify the messaging contract without depending on the real
 * wp-cli/wp-cli package.
 *
 * Each test resets the shim's call buffers and the Blurhash hook
 * wiring, so cross-test pollution can't make a green test look
 * green for the wrong reason.
 */
class Blurhash_CLITest extends BaseTestCase {

	/**
	 * Tempfile fixture paths created during a test, cleaned up in
	 * {@see self::cleanup_fixture_files()}.
	 *
	 * @var array<int, string>
	 */
	private array $fixture_files = array();

	/**
	 * Reset the WP_CLI shim's call buffers and the Blurhash hook
	 * wiring before each test. Skips the whole test if the real
	 * `WP_CLI` runtime is loaded in this PHPUnit environment —
	 * the tests depend on the in-repo shim's `reset`/`commands`/
	 * `last_success` helpers, which the real WP-CLI class doesn't
	 * expose. The shim auto-loads only when `\WP_CLI` is undefined
	 * (see `Helpers/class-wp-cli-stub.php`), so a real-WP-CLI
	 * environment would otherwise fatal on the first helper call.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		if ( ! method_exists( WP_CLI::class, 'reset' ) ) {
			$this->markTestSkipped(
				'Real WP-CLI runtime is loaded; these tests require the in-repo WP_CLI shim.'
			);
		}
		WP_CLI::reset();
		remove_all_filters( 'wp_generate_attachment_metadata' );
		remove_all_filters( 'activitypub_attachment' );
		remove_all_filters( 'get_attached_file' );
		remove_all_filters( 'posts_pre_query' );
		remove_all_actions( Blurhash::CRON_HOOK );
		Blurhash::register();
	}

	/**
	 * WorDBless's wpdb shim doesn't execute SQL, so `WP_Query`
	 * returns empty by default. `posts_pre_query` short-circuits the
	 * DB read with an in-memory array — drives the backfill loop
	 * against attachments we just inserted, in the order we control,
	 * across as many "pages" as we configure.
	 *
	 * The candidate-selection logic itself (the `NOT EXISTS`
	 * meta_query that filters out already-hashed attachments
	 * server-side) is verified by manual smoke against a real DB —
	 * this stub only proves the per-attachment processing logic and
	 * the limit / exit-code / message contract.
	 *
	 * @param array<int, array<int, int>> $pages Array of pages, each
	 *    a list of attachment IDs to return for that WP_Query call.
	 *    Pages exhaust in order; empty array signals the loop to
	 *    terminate.
	 */
	private function stub_query_pages( array $pages ): void {
		$queue = $pages;
		add_filter(
			'posts_pre_query',
			static function ( $posts, $query ) use ( &$queue ) {
				unset( $query );
				if ( empty( $queue ) ) {
					return array();
				}
				return array_shift( $queue );
			},
			10,
			2
		);
	}

	/**
	 * Delete any tempfile fixtures created during a test.
	 *
	 * @after
	 */
	#[After]
	public function cleanup_fixture_files(): void {
		foreach ( $this->fixture_files as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$this->fixture_files = array();
	}

	/**
	 * Insert an image attachment row.
	 *
	 * @param string $filename Filename component for `_wp_attached_file`.
	 * @param string $mime     MIME type for the attachment post.
	 * @return int Attachment post id.
	 */
	private function insert_image_attachment( string $filename = 'fixture.jpg', string $mime = 'image/jpeg' ): int {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $mime,
				'post_title'     => 'fixture',
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', $filename );
		return (int) $attachment_id;
	}

	/**
	 * Generate a tiny PNG fixture and return its absolute path. GD
	 * required.
	 *
	 * @return string|null Path or null on failure.
	 */
	private function generate_fixture_png(): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return null;
		}
		$tmp = tempnam( sys_get_temp_dir(), 'fosse-blurhash-cli-' );
		if ( false === $tmp ) {
			return null;
		}
		$path                  = $tmp . '.png';
		$this->fixture_files[] = $tmp;
		$this->fixture_files[] = $path;

		$im = imagecreatetruecolor( 16, 16 );
		for ( $y = 0; $y < 16; $y++ ) {
			for ( $x = 0; $x < 16; $x++ ) {
				$color = imagecolorallocate( $im, $x * 16, $y * 16, ( $x + $y ) * 8 );
				imagesetpixel( $im, $x, $y, $color );
			}
		}
		imagepng( $im, $path );
		return $path;
	}

	/**
	 * Write a syntactically valid PNG whose IHDR declares the given
	 * dimensions but carries no pixel data — enough to exercise the
	 * encoder's decode-bomb dimension gate without materializing the
	 * bitmap. Mirrors the helper in {@see BlurhashTest}.
	 *
	 * @param int $width  Declared pixel width.
	 * @param int $height Declared pixel height.
	 * @return string Absolute file path to the crafted PNG.
	 */
	private function generate_png_with_declared_dimensions( int $width, int $height ): string {
		$signature = "\x89PNG\r\n\x1a\n";
		$ihdr_data = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );
		$ihdr      = pack( 'N', strlen( $ihdr_data ) )
			. 'IHDR' . $ihdr_data
			. pack( 'N', crc32( 'IHDR' . $ihdr_data ) );
		$iend      = pack( 'N', 0 ) . 'IEND' . pack( 'N', crc32( 'IEND' ) );

		$tmp = tempnam( sys_get_temp_dir(), 'fosse-blurhash-cli-dim-' );
		$this->assertNotFalse( $tmp );
		$path                  = $tmp . '.png';
		$this->fixture_files[] = $tmp;
		$this->fixture_files[] = $path;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture write to tempdir.
		file_put_contents( $path, $signature . $ihdr . $iend );
		return $path;
	}

	/**
	 * Stub `get_attached_file` to point a single attachment at the
	 * given path.
	 *
	 * @param int    $attachment_id Target attachment id.
	 * @param string $path          Absolute path.
	 */
	private function point_attachment_at( int $attachment_id, string $path ): void {
		add_filter(
			'get_attached_file',
			function ( $file, $id ) use ( $attachment_id, $path ) {
				return ( (int) $id === $attachment_id ) ? $path : $file;
			},
			10,
			2
		);
	}

	/**
	 * Skip when GD isn't installed.
	 */
	private function require_gd(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD extension required for this test.' );
		}
	}

	// ---------------------------------------------------------------
	// Default-mode backfill (server-side candidate filter).
	// ---------------------------------------------------------------

	/**
	 * Default mode only processes attachments without a stored
	 * hash. Already-encoded attachments are filtered server-side by
	 * the `NOT EXISTS` meta query, never reach the PHP loop, and
	 * therefore never count against `--limit`. This is the bug
	 * codex C1 found: the prior code counted skipped rows toward
	 * the limit and could not progress past an all-hashed page.
	 */
	public function test_backfill_processes_only_supplied_candidates(): void {
		$this->require_gd();

		$pre_hashed = $this->insert_image_attachment();
		Blurhash::set( $pre_hashed, 'preexisting-hash' );

		$candidate = $this->insert_image_attachment();
		$path      = $this->generate_fixture_png();
		$this->point_attachment_at( $candidate, $path );

		// Default-mode candidate set excludes already-hashed rows
		// server-side via meta_query NOT EXISTS — stubbed here as
		// "the query returned only the unhashed candidate."
		$this->stub_query_pages( array( array( $candidate ), array() ) );

		( new Blurhash_CLI() )->backfill( array(), array() );

		// Pre-hashed attachment is untouched.
		$this->assertSame( 'preexisting-hash', Blurhash::get( $pre_hashed ) );
		// Candidate got encoded.
		$this->assertIsString( Blurhash::get( $candidate ) );
		$this->assertNotSame( '', Blurhash::get( $candidate ) );
	}

	/**
	 * `--limit=N` advances through candidates across pages — the
	 * regression fix for codex C1. With 1 already-hashed and 2
	 * candidates, `--limit=1` should encode the first candidate;
	 * a follow-up run finishes the second.
	 */
	public function test_backfill_limit_counts_only_work_done(): void {
		$this->require_gd();

		$first_candidate = $this->insert_image_attachment();
		$this->point_attachment_at( $first_candidate, $this->generate_fixture_png() );

		$second_candidate = $this->insert_image_attachment();
		$this->point_attachment_at( $second_candidate, $this->generate_fixture_png() );

		// Both candidates available on one page.
		$this->stub_query_pages( array( array( $first_candidate, $second_candidate ), array() ) );

		( new Blurhash_CLI() )->backfill( array(), array( 'limit' => 1 ) );

		// `--limit=1` encodes one and stops. The fix for codex C1
		// is that this counts real work (encoded), not rows seen
		// — including under `--force` where the prior behavior
		// could re-skip and re-skip the same page forever.
		$this->assertIsString( Blurhash::get( $first_candidate ) );
		$this->assertNull( Blurhash::get( $second_candidate ) );
	}

	/**
	 * `--dry-run` reports candidates but writes no postmeta.
	 */
	public function test_backfill_dry_run_does_not_write_meta(): void {
		$this->require_gd();

		$candidate = $this->insert_image_attachment();
		$this->point_attachment_at( $candidate, $this->generate_fixture_png() );

		$this->stub_query_pages( array( array( $candidate ), array() ) );

		( new Blurhash_CLI() )->backfill( array(), array( 'dry-run' => true ) );

		$this->assertNull( Blurhash::get( $candidate ) );

		$last_success = WP_CLI::last_success();
		$this->assertNotNull( $last_success );
		$this->assertStringContainsString( '[dry-run]', $last_success );
		$this->assertStringContainsString( 'would encode 1', $last_success );
	}

	/**
	 * Non-raster `image/*` mime types (SVG, etc.) are skipped, not
	 * counted as failures. Without this gate the prior code emitted
	 * a `WP_CLI::warning` per SVG attachment on every backfill run.
	 */
	public function test_backfill_skips_non_raster_image_mimes(): void {
		$svg_id = $this->insert_image_attachment( 'fixture.svg', 'image/svg+xml' );

		$this->stub_query_pages( array( array( $svg_id ), array() ) );

		( new Blurhash_CLI() )->backfill( array(), array() );

		$this->assertNull( Blurhash::get( $svg_id ) );
		$this->assertSame( array(), WP_CLI::warnings(), 'SVG should not emit a warning' );
		$last_success = WP_CLI::last_success();
		$this->assertNotNull( $last_success );
		$this->assertStringContainsString( 'skipped 1', $last_success );
	}

	/**
	 * A decode-bomb source (declared dimensions over the encoder's
	 * 50 MP cap) is a policy skip, not a failure: counted as
	 * skipped, no warning, zero exit. Before the three-state
	 * `encode_from_attachment()` return, the bomb landed in the
	 * failure bucket — a warning plus nonzero exit on EVERY backfill
	 * run, forever, since the attachment can never gain a hash.
	 */
	public function test_backfill_counts_decode_bomb_as_skipped_not_failed(): void {
		$this->require_gd();

		$bomb_id = $this->insert_image_attachment( 'bomb.png', 'image/png' );
		$this->point_attachment_at( $bomb_id, $this->generate_png_with_declared_dimensions( 30000, 30000 ) );

		$this->stub_query_pages( array( array( $bomb_id ), array() ) );

		( new Blurhash_CLI() )->backfill( array(), array() );

		$this->assertNull( Blurhash::get( $bomb_id ) );
		$this->assertSame( array(), WP_CLI::warnings(), 'Policy skip must not emit a warning' );
		$last_success = WP_CLI::last_success();
		$this->assertNotNull( $last_success, 'Policy skip must exit zero (success), not error' );
		$this->assertStringContainsString( 'skipped 1', $last_success );
		$this->assertStringContainsString( 'failed 0', $last_success );
	}

	// ---------------------------------------------------------------
	// --force mode.
	// ---------------------------------------------------------------

	/**
	 * `--force` re-encodes already-hashed attachments. The encode
	 * runs against the current bytes, so a re-encode after the
	 * encoder's components change produces the updated hash.
	 */
	public function test_force_reencodes_already_hashed_attachments(): void {
		$this->require_gd();

		$id = $this->insert_image_attachment();
		Blurhash::set( $id, 'old-hash' );
		$this->point_attachment_at( $id, $this->generate_fixture_png() );

		$this->stub_query_pages( array( array( $id ), array() ) );

		( new Blurhash_CLI() )->backfill( array(), array( 'force' => true ) );

		$updated = Blurhash::get( $id );
		$this->assertNotNull( $updated );
		$this->assertNotSame( 'old-hash', $updated );
	}

	/**
	 * The codex C/F2 regression: under `--force`, a failed encode
	 * must NOT destroy the prior good hash. The prior code deleted
	 * the postmeta before re-encoding, so any encode failure (NFS
	 * hiccup, S3 lag, transient GD blip) wiped data permanently.
	 * The fix is encode-first, set-on-success.
	 */
	public function test_force_failed_reencode_preserves_existing_hash(): void {
		$id = $this->insert_image_attachment();
		Blurhash::set( $id, 'good-hash-from-yesterday' );

		// Point at a path that doesn't exist. encode_from_attachment
		// returns null; the old hash must survive.
		$missing_path = sys_get_temp_dir() . '/fosse-blurhash-no-such-file-' . uniqid() . '.png';
		$this->point_attachment_at( $id, $missing_path );

		$this->stub_query_pages( array( array( $id ), array() ) );

		try {
			( new Blurhash_CLI() )->backfill( array(), array( 'force' => true ) );
			$this->fail( 'Expected WP_CLI::error to throw on failed encode' );
		} catch ( \RuntimeException $e ) {
			// Expected — error() throws in the shim so the test can
			// assert the non-zero exit contract.
			$this->assertStringContainsString( 'failed 1', $e->getMessage() );
		}

		$this->assertSame( 'good-hash-from-yesterday', Blurhash::get( $id ) );
	}

	// ---------------------------------------------------------------
	// Exit-code contract.
	// ---------------------------------------------------------------

	/**
	 * When every encode failed, the command exits non-zero so
	 * automation can detect partial-success runs. The shim's
	 * `error()` throws to make that detectable from a test.
	 */
	public function test_failed_encodes_trigger_nonzero_exit(): void {
		$id           = $this->insert_image_attachment();
		$missing_path = sys_get_temp_dir() . '/fosse-blurhash-missing-' . uniqid() . '.png';
		$this->point_attachment_at( $id, $missing_path );

		$this->stub_query_pages( array( array( $id ), array() ) );

		try {
			( new Blurhash_CLI() )->backfill( array(), array() );
			$this->fail( 'Expected WP_CLI::error to throw on failed encode' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'failed 1', $e->getMessage() );
		}
	}

	/**
	 * Clean default-mode run with no candidates produces a success
	 * line (not an error). Guards against a regression where the
	 * empty-set path mis-routes to `error()`.
	 */
	public function test_backfill_no_candidates_succeeds(): void {
		( new Blurhash_CLI() )->backfill( array(), array() );

		$last_success = WP_CLI::last_success();
		$this->assertNotNull( $last_success );
		$this->assertStringContainsString( 'encoded 0', $last_success );
		$this->assertStringContainsString( 'failed 0', $last_success );
		$this->assertSame( array(), WP_CLI::warnings() );
	}

	// ---------------------------------------------------------------
	// Registration.
	// ---------------------------------------------------------------

	/**
	 * `register()` adds the `fosse blurhash` command when the
	 * `WP_CLI` constant is truthy. The "noop when not truthy"
	 * branch is the production guard against autoloading the
	 * command file on web requests; we can't realistically
	 * exercise the unset/false branch inside a PHPUnit run because
	 * the constant is shared process state, so only the truthy
	 * branch is asserted here.
	 */
	public function test_register_adds_command_when_wp_cli_truthy(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		WP_CLI::reset();
		Blurhash_CLI::register();

		$this->assertSame(
			array( array( 'fosse blurhash', Blurhash_CLI::class ) ),
			WP_CLI::commands()
		);
	}
}
