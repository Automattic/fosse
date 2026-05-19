<?php
/**
 * Tests for the Blurhash encoder + AP attachment injector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Blurhash;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Covers the three concerns the Blurhash class owns:
 *
 *   1. Postmeta storage helpers (get/set/delete) — pure data tests.
 *   2. Encoder + scheduler — exercises both the cron-deferred path
 *      and direct invocation (the path the WP-CLI backfill uses).
 *   3. ActivityPub `attachment[]` injection — through
 *      `apply_filters( 'activitypub_attachment', … )` so the
 *      contract that an upstream filter call wires us in is kept
 *      load-bearing in tests.
 *
 * Tests that need an actual encode (vs. round-tripping a string
 * through postmeta) generate a tiny PNG via GD into a tempfile and
 * redirect `get_attached_file` to point at it. Hosts without GD
 * skip those cases — same posture as the production class.
 */
class BlurhashTest extends BaseTestCase {

	/**
	 * Absolute paths to temp PNG fixtures created during the test
	 * run. Cleaned up in `cleanup_fixture_files()`.
	 *
	 * @var array<int, string>
	 */
	private array $fixture_files = array();

	/**
	 * Reset between-test state: clear hooks, scheduled events, and
	 * re-register the production hooks so each test exercises the
	 * real `register()` wiring instead of relying on bootstrap order.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		remove_all_filters( 'wp_generate_attachment_metadata' );
		remove_all_filters( 'activitypub_attachment' );
		remove_all_filters( 'get_attached_file' );
		remove_all_actions( Blurhash::CRON_HOOK );

		Blurhash::register();
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
	 * Insert a JPEG image attachment with optional metadata. No file
	 * on disk — only the database state needed for
	 * `wp_attachment_is_image()` and metadata lookups.
	 *
	 * @param string $filename Filename (no path).
	 * @return int Attachment post id.
	 */
	private function insert_image_attachment( string $filename = 'fixture.jpg' ): int {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'fixture',
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', $filename );
		return (int) $attachment_id;
	}

	/**
	 * Generate a small PNG to a tempfile and return its absolute
	 * path. Test is responsible for marking the attachment with a
	 * `get_attached_file` filter pointing at the returned path.
	 *
	 * @param bool $corrupt If true, write garbage bytes instead of a real PNG.
	 * @param int  $width   Pixel width of the generated image.
	 * @param int  $height  Pixel height of the generated image.
	 * @return string|null Absolute file path, or null if GD unavailable.
	 */
	private function generate_fixture_image( bool $corrupt = false, int $width = 16, int $height = 16 ): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return null;
		}

		$tmp = tempnam( sys_get_temp_dir(), 'fosse-blurhash-' );
		if ( false === $tmp ) {
			return null;
		}
		$path                  = $tmp . '.png';
		$this->fixture_files[] = $tmp;
		$this->fixture_files[] = $path;

		if ( $corrupt ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture write to tempdir.
			file_put_contents( $path, 'not a real PNG' );
			return $path;
		}

		$im      = imagecreatetruecolor( $width, $height );
		$scale_x = max( 1, (int) ( 255 / max( 1, $width - 1 ) ) );
		$scale_y = max( 1, (int) ( 255 / max( 1, $height - 1 ) ) );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$r     = min( 255, $x * $scale_x );
				$g     = min( 255, $y * $scale_y );
				$b     = min( 255, ( $x + $y ) * ( ( $scale_x + $scale_y ) / 2 ) );
				$color = imagecolorallocate( $im, $r, $g, (int) $b );
				imagesetpixel( $im, $x, $y, $color );
			}
		}
		imagepng( $im, $path );
		// PHP 8.0+ collects GdImage when it leaves scope; no manual imagedestroy() needed.
		return $path;
	}

	/**
	 * Redirect `get_attached_file` for one attachment id to a
	 * specific absolute path — the only-touch-this-one matcher
	 * keeps fixtures from cross-contaminating sibling tests.
	 *
	 * @param int    $attachment_id Target attachment id.
	 * @param string $path          Absolute file path.
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
	 * Helper: assert GD is available, else skip.
	 */
	private function require_gd(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD extension required for this test.' );
		}
	}

	// ---------------------------------------------------------------
	// Postmeta storage helpers.
	// ---------------------------------------------------------------

	/**
	 * `get()` returns null for an attachment with no stored hash —
	 * the federation injector keys off this to no-op cleanly when a
	 * site hasn't backfilled or the cron hasn't fired yet.
	 */
	public function test_get_returns_null_when_no_meta(): void {
		$id = $this->insert_image_attachment();
		$this->assertNull( Blurhash::get( $id ) );
	}

	/**
	 * Round trip: set then get returns the same string.
	 */
	public function test_set_then_get_round_trips(): void {
		$id   = $this->insert_image_attachment();
		$hash = 'LEHV6nWB2yk8pyo0adR*.7kCMdnj';
		Blurhash::set( $id, $hash );
		$this->assertSame( $hash, Blurhash::get( $id ) );
	}

	/**
	 * Empty-string meta is treated as absent — guards against a
	 * site that manually clears the field by writing empty rather
	 * than calling delete().
	 */
	public function test_get_returns_null_for_empty_string_meta(): void {
		$id = $this->insert_image_attachment();
		update_post_meta( $id, Blurhash::META_KEY, '' );
		$this->assertNull( Blurhash::get( $id ) );
	}

	/**
	 * Whitespace-only meta is also treated as absent — prevents an
	 * encoder bug or a malformed import from leaking a useless
	 * value into the federation envelope.
	 */
	public function test_get_returns_null_for_whitespace_only_meta(): void {
		$id = $this->insert_image_attachment();
		update_post_meta( $id, Blurhash::META_KEY, "   \n\t" );
		$this->assertNull( Blurhash::get( $id ) );
	}

	/**
	 * Non-string meta is treated as absent — defensive against a
	 * site that wrote an array via update_post_meta directly.
	 */
	public function test_get_returns_null_for_non_string_meta(): void {
		$id = $this->insert_image_attachment();
		update_post_meta( $id, Blurhash::META_KEY, array( 'unexpected' ) );
		$this->assertNull( Blurhash::get( $id ) );
	}

	/**
	 * `delete()` removes the stored hash entirely (vs. leaving an
	 * empty value behind).
	 */
	public function test_delete_removes_stored_hash(): void {
		$id = $this->insert_image_attachment();
		Blurhash::set( $id, 'LEHV6nWB2yk8pyo0adR*.7kCMdnj' );
		Blurhash::delete( $id );
		$this->assertNull( Blurhash::get( $id ) );
		$this->assertSame( '', get_post_meta( $id, Blurhash::META_KEY, true ) );
	}

	// ---------------------------------------------------------------
	// Encoder.
	// ---------------------------------------------------------------

	/**
	 * Non-image attachments (audio, video, pdf, etc.) bail before
	 * touching GD — keeps the encoder cheap on mixed-media uploads.
	 */
	public function test_encode_returns_null_for_non_image_attachment(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'audio/mpeg',
				'post_title'     => 'fixture',
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', 'fixture.mp3' );

		$this->assertNull( Blurhash::encode_from_attachment( (int) $attachment_id ) );
	}

	/**
	 * Missing-file path: image attachment exists in DB but the
	 * underlying file is gone (CDN purge, manual delete, S3 offload
	 * pointing at a stale path). Returns null without warning.
	 */
	public function test_encode_returns_null_for_missing_file(): void {
		$id = $this->insert_image_attachment( 'this-file-does-not-exist.jpg' );
		$this->point_attachment_at( $id, '/tmp/fosse-blurhash-nonexistent-' . uniqid() . '.png' );

		$this->assertNull( Blurhash::encode_from_attachment( $id ) );
	}

	/**
	 * Happy path: real PNG on disk → encoder returns a non-empty
	 * string. Hash content isn't asserted (the library owns the
	 * algorithm); the contract being tested is "we plumb GD pixels
	 * into the encoder and get a string out."
	 */
	public function test_encode_returns_string_for_real_image_via_get_attached_file(): void {
		$this->require_gd();

		$id   = $this->insert_image_attachment();
		$path = $this->generate_fixture_image();
		$this->assertNotNull( $path, 'fixture generation should succeed when GD is present' );
		$this->point_attachment_at( $id, $path );

		$hash = Blurhash::encode_from_attachment( $id );
		$this->assertIsString( $hash );
		$this->assertNotSame( '', $hash );
	}

	/**
	 * Oversized sources get downscaled before the per-pixel loop
	 * runs, so the encoder doesn't allocate a pathological PHP
	 * pixel array when a fallback path lands on a multi-megapixel
	 * original. Generates a 256×256 fixture (4× the MAX_ENCODE_EDGE
	 * of 64) and asserts the encode still completes with a hash.
	 */
	public function test_encode_caps_oversized_image_dimensions(): void {
		$this->require_gd();

		$id   = $this->insert_image_attachment();
		$path = $this->generate_fixture_image( false, 256, 256 );
		$this->point_attachment_at( $id, $path );

		$hash = Blurhash::encode_from_attachment( $id );
		$this->assertIsString( $hash );
		$this->assertNotSame( '', $hash );
	}

	/**
	 * Files larger than MAX_ENCODE_BYTES (8 MiB) are rejected
	 * before any read — defends against a pathological original
	 * or a filterable-metadata path pointing at a giant non-image
	 * blob. Uses a sparse-friendly stub: a tempfile padded past
	 * the cap, encoder must return null without OOMing.
	 */
	public function test_encode_returns_null_for_oversized_file(): void {
		$this->require_gd();

		$id  = $this->insert_image_attachment();
		$tmp = tempnam( sys_get_temp_dir(), 'fosse-blurhash-big-' );
		$this->assertNotFalse( $tmp );
		$path                  = $tmp . '.png';
		$this->fixture_files[] = $tmp;
		$this->fixture_files[] = $path;

		// 9 MiB of zeros — well past the 8 MiB MAX_ENCODE_BYTES.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture write to tempdir.
		file_put_contents( $path, str_repeat( "\0", 9 * 1024 * 1024 ) );
		$this->point_attachment_at( $id, $path );

		$this->assertNull( Blurhash::encode_from_attachment( $id ) );
	}

	/**
	 * Path traversal in the intermediate-size branch is rejected
	 * by the basedir containment check — defends against a
	 * malicious or buggy `image_get_intermediate_size` filter
	 * return value escaping the uploads root via `..` segments.
	 *
	 * We seed `_wp_attachment_metadata` with a `file` containing
	 * traversal segments. Without the basedir containment check,
	 * the encoder would resolve to a path outside `wp_upload_dir`'s
	 * basedir and read whatever is there.
	 */
	public function test_resolve_rejects_intermediate_path_outside_basedir(): void {
		$this->require_gd();

		$id = $this->insert_image_attachment();
		update_post_meta(
			$id,
			'_wp_attachment_metadata',
			array(
				'width'  => 16,
				'height' => 16,
				'file'   => 'fixture.png',
				'sizes'  => array(
					'thumbnail' => array(
						'file'      => '../../../../etc/passwd',
						'width'     => 16,
						'height'    => 16,
						'mime-type' => 'image/png',
					),
				),
			)
		);

		// No get_attached_file filter — fallback is purely realpath()
		// + is_readable() against whatever path WP resolves the
		// attachment to, which for an attachment with no real file
		// is unreadable. So the traversal branch is the only path
		// that could leak something, and it must reject.
		$this->assertNull( Blurhash::encode_from_attachment( $id ) );
	}

	/**
	 * Same fixture encoded twice yields the same hash — the
	 * encoder is deterministic, which lets us cache the result
	 * with confidence.
	 */
	public function test_encode_is_deterministic_for_same_input(): void {
		$this->require_gd();

		$id   = $this->insert_image_attachment();
		$path = $this->generate_fixture_image();
		$this->point_attachment_at( $id, $path );

		$first  = Blurhash::encode_from_attachment( $id );
		$second = Blurhash::encode_from_attachment( $id );

		$this->assertNotNull( $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * Corrupt image bytes (file exists but isn't a real image) →
	 * GD's `imagecreatefromstring` returns false, encoder returns
	 * null. No warnings escape.
	 */
	public function test_encode_returns_null_for_corrupt_image_bytes(): void {
		$this->require_gd();

		$id   = $this->insert_image_attachment();
		$path = $this->generate_fixture_image( true );
		$this->point_attachment_at( $id, $path );

		$this->assertNull( Blurhash::encode_from_attachment( $id ) );
	}

	/**
	 * Encoding a nonexistent attachment id returns null — guards
	 * the cron handler's race where an attachment is deleted
	 * between scheduling and the cron tick.
	 */
	public function test_encode_returns_null_for_nonexistent_attachment(): void {
		$this->assertNull( Blurhash::encode_from_attachment( 999999 ) );
	}

	// ---------------------------------------------------------------
	// schedule_encode / schedule.
	// ---------------------------------------------------------------

	/**
	 * `schedule_encode()` queues a cron event for image
	 * attachments. The filter return value (metadata) is passed
	 * through unchanged.
	 */
	public function test_schedule_encode_queues_cron_for_image_attachment(): void {
		$id       = $this->insert_image_attachment();
		$metadata = array(
			'width'  => 100,
			'height' => 100,
			'file'   => 'fixture.jpg',
		);

		$returned = Blurhash::schedule_encode( $metadata, $id );

		$this->assertSame( $metadata, $returned );
		$this->assertNotFalse( wp_next_scheduled( Blurhash::CRON_HOOK, array( $id ) ) );
	}

	/**
	 * Non-image attachments don't schedule — the cron payload
	 * would just be a no-op (encoder bails on `wp_attachment_is_image`
	 * false), so save the scheduler slot.
	 */
	public function test_schedule_encode_does_not_queue_for_non_image_attachment(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'fixture',
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', 'fixture.pdf' );

		Blurhash::schedule_encode( array(), (int) $attachment_id );

		$this->assertFalse( wp_next_scheduled( Blurhash::CRON_HOOK, array( (int) $attachment_id ) ) );
	}

	/**
	 * Scheduling for the same attachment twice does not enqueue a
	 * duplicate event — important because
	 * `wp_generate_attachment_metadata` fires on every regen
	 * (uploads, WP-CLI media regenerate, plugin-driven regen).
	 */
	public function test_schedule_is_idempotent_for_same_attachment_id(): void {
		$id = $this->insert_image_attachment();

		Blurhash::schedule( $id );
		$first_timestamp = wp_next_scheduled( Blurhash::CRON_HOOK, array( $id ) );

		Blurhash::schedule( $id );
		$second_timestamp = wp_next_scheduled( Blurhash::CRON_HOOK, array( $id ) );

		$this->assertNotFalse( $first_timestamp );
		$this->assertSame( $first_timestamp, $second_timestamp );
	}

	/**
	 * `schedule_encode()` bails on a zero / negative id rather
	 * than scheduling for garbage input — catches a misconfigured
	 * upstream caller passing a bad attachment id.
	 */
	public function test_schedule_encode_ignores_invalid_attachment_id(): void {
		$metadata = array( 'width' => 100 );
		$returned = Blurhash::schedule_encode( $metadata, 0 );

		$this->assertSame( $metadata, $returned );
		$this->assertFalse( wp_next_scheduled( Blurhash::CRON_HOOK, array( 0 ) ) );
	}

	// ---------------------------------------------------------------
	// run_encode (cron callback).
	// ---------------------------------------------------------------

	/**
	 * Cron handler stores the encoded hash for an attachment with
	 * a real fixture on disk.
	 */
	public function test_run_encode_stores_hash_when_encoder_succeeds(): void {
		$this->require_gd();

		$id   = $this->insert_image_attachment();
		$path = $this->generate_fixture_image();
		$this->point_attachment_at( $id, $path );

		Blurhash::run_encode( $id );

		$stored = Blurhash::get( $id );
		$this->assertIsString( $stored );
		$this->assertNotSame( '', $stored );
	}

	/**
	 * Already-stored hash short-circuits the encoder so cron
	 * re-fires (or re-scheduled events) don't pay the CPU cost
	 * twice.
	 */
	public function test_run_encode_skips_when_already_stored(): void {
		$id = $this->insert_image_attachment();
		Blurhash::set( $id, 'pre-existing-hash' );
		$this->point_attachment_at( $id, '/tmp/fosse-blurhash-should-not-be-read-' . uniqid() . '.png' );

		Blurhash::run_encode( $id );

		$this->assertSame( 'pre-existing-hash', Blurhash::get( $id ) );
	}

	/**
	 * Failed encode (missing file, corrupt bytes, etc.) does not
	 * store a value — guards against caching an empty string that
	 * would then leak into the federation envelope.
	 */
	public function test_run_encode_stores_nothing_when_encoder_returns_null(): void {
		$id = $this->insert_image_attachment( 'missing.jpg' );
		$this->point_attachment_at( $id, '/tmp/fosse-blurhash-no-such-file-' . uniqid() . '.png' );

		Blurhash::run_encode( $id );

		$this->assertNull( Blurhash::get( $id ) );
	}

	/**
	 * Invalid attachment id (zero or negative) bails — guards a
	 * misconfigured cron payload.
	 */
	public function test_run_encode_no_op_for_invalid_attachment_id(): void {
		Blurhash::run_encode( 0 );
		Blurhash::run_encode( -5 );
		$this->assertTrue( true ); // assertion presence; the test passes if no warnings escape.
	}

	// ---------------------------------------------------------------
	// activitypub_attachment injection.
	// ---------------------------------------------------------------

	/**
	 * Image attachment array gains a `blurhash` field when the
	 * postmeta is stored. Exercised through `apply_filters` so the
	 * `register()` hook wiring is load-bearing in this test.
	 */
	public function test_inject_adds_blurhash_via_filter_for_image_with_stored_hash(): void {
		$id = $this->insert_image_attachment();
		Blurhash::set( $id, 'LEHV6nWB2yk8pyo0adR*.7kCMdnj' );

		$attachment = array(
			'type'      => 'Image',
			'mediaType' => 'image/jpeg',
			'url'       => 'https://example.test/photo.jpg',
			'name'      => 'A photo',
		);

		$filtered = apply_filters( 'activitypub_attachment', $attachment, $id );

		$this->assertArrayHasKey( 'blurhash', $filtered );
		$this->assertSame( 'LEHV6nWB2yk8pyo0adR*.7kCMdnj', $filtered['blurhash'] );
	}

	/**
	 * No stored hash → attachment passes through unchanged. The
	 * federation never blocks on a missing placeholder.
	 */
	public function test_inject_no_op_when_no_stored_hash(): void {
		$id = $this->insert_image_attachment();

		$attachment = array(
			'type' => 'Image',
			'url'  => 'https://example.test/photo.jpg',
		);

		$filtered = apply_filters( 'activitypub_attachment', $attachment, $id );

		$this->assertArrayNotHasKey( 'blurhash', $filtered );
	}

	/**
	 * Non-image attachment types (Video, Audio, Document) never
	 * gain a blurhash, even if the stored hash somehow exists —
	 * the field is image-specific in the Mastodon contract.
	 */
	public function test_inject_no_op_for_non_image_attachment_type(): void {
		$id = $this->insert_image_attachment();
		Blurhash::set( $id, 'LEHV6nWB2yk8pyo0adR*.7kCMdnj' );

		$attachment = array(
			'type' => 'Video',
			'url'  => 'https://example.test/clip.mp4',
		);

		$filtered = apply_filters( 'activitypub_attachment', $attachment, $id );

		$this->assertArrayNotHasKey( 'blurhash', $filtered );
	}

	/**
	 * Defensive: an attachment with no `type` key (corrupted /
	 * upstream change) passes through unchanged.
	 */
	public function test_inject_no_op_when_type_key_missing(): void {
		$id = $this->insert_image_attachment();
		Blurhash::set( $id, 'LEHV6nWB2yk8pyo0adR*.7kCMdnj' );

		$attachment = array( 'url' => 'https://example.test/photo.jpg' );

		$filtered = apply_filters( 'activitypub_attachment', $attachment, $id );

		$this->assertArrayNotHasKey( 'blurhash', $filtered );
	}

	/**
	 * Defensive: a non-array filter argument (upstream pre-filter
	 * returns false / null / string) passes through untouched.
	 */
	public function test_inject_passes_through_non_array_arg(): void {
		$id = $this->insert_image_attachment();
		Blurhash::set( $id, 'LEHV6nWB2yk8pyo0adR*.7kCMdnj' );

		$this->assertFalse( apply_filters( 'activitypub_attachment', false, $id ) );
		$this->assertNull( apply_filters( 'activitypub_attachment', null, $id ) );
	}

	/**
	 * Injection survives multiple co-existing filter callbacks —
	 * the field is added without disturbing other keys an upstream
	 * filter might have added (e.g. `exifData` from bundled AP).
	 */
	public function test_inject_preserves_existing_keys(): void {
		$id = $this->insert_image_attachment();
		Blurhash::set( $id, 'LEHV6nWB2yk8pyo0adR*.7kCMdnj' );

		$attachment = array(
			'type'      => 'Image',
			'mediaType' => 'image/jpeg',
			'url'       => 'https://example.test/photo.jpg',
			'name'      => 'A photo',
			'width'     => 1600,
			'height'    => 1200,
			'exifData'  => array( 'Make' => 'Canon' ),
		);

		$filtered = apply_filters( 'activitypub_attachment', $attachment, $id );

		$this->assertSame( 'image/jpeg', $filtered['mediaType'] );
		$this->assertSame( array( 'Make' => 'Canon' ), $filtered['exifData'] );
		$this->assertSame( 1600, $filtered['width'] );
		$this->assertSame( 'LEHV6nWB2yk8pyo0adR*.7kCMdnj', $filtered['blurhash'] );
	}

	// ---------------------------------------------------------------
	// register().
	// ---------------------------------------------------------------

	/**
	 * `register()` wires the three callbacks the production class
	 * promises. Each callback is the static method on Blurhash.
	 */
	public function test_register_wires_all_three_hooks(): void {
		$this->assertNotFalse(
			has_filter( 'wp_generate_attachment_metadata', array( Blurhash::class, 'schedule_encode' ) )
		);
		$this->assertNotFalse(
			has_filter( 'activitypub_attachment', array( Blurhash::class, 'inject_blurhash' ) )
		);
		$this->assertNotFalse(
			has_action( Blurhash::CRON_HOOK, array( Blurhash::class, 'run_encode' ) )
		);
	}

	/**
	 * Encode → store → inject end-to-end: simulates a real upload
	 * lifecycle (cron runs, AP transformer fires later) and asserts
	 * the field lands in the outbound attachment array. The
	 * canonical happy-path test.
	 */
	public function test_end_to_end_encode_store_inject(): void {
		$this->require_gd();

		$id   = $this->insert_image_attachment();
		$path = $this->generate_fixture_image();
		$this->point_attachment_at( $id, $path );

		// Step 1: cron fires, encodes, stores.
		Blurhash::run_encode( $id );
		$stored = Blurhash::get( $id );
		$this->assertIsString( $stored );

		// Step 2: AP transformer builds an attachment array and
		// runs the filter chain.
		$attachment = array(
			'type'      => 'Image',
			'mediaType' => 'image/png',
			'url'       => 'https://example.test/photo.png',
			'name'      => 'A photo',
		);
		$filtered   = apply_filters( 'activitypub_attachment', $attachment, $id );

		$this->assertSame( $stored, $filtered['blurhash'] );
	}
}
