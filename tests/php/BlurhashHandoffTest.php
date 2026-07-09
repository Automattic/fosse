<?php
/**
 * Tests for Blurhash_Handoff.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Blurhash_Handoff;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

require_once __DIR__ . '/fixtures/class-activitypub-blurhash-stub.php';

/**
 * Verifies the lazy FOSSE-to-AP blurhash hand-off bridge.
 */
class BlurhashHandoffTest extends BaseTestCase {

	/**
	 * A valid blurhash used across cases.
	 *
	 * @var string
	 */
	private const HASH = 'LEHV6nWB2yk8pyo0adR*.7kCMdnj';

	/**
	 * Reset filter state and register the bridge fresh for each test.
	 *
	 * The boot-time `init` closure may have hooked FOSSE's own injector
	 * (when the bundled AP predates the native class); drop everything
	 * on the filter so each test exercises exactly the bridge plus
	 * whatever it registers itself.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		remove_all_filters( 'activitypub_attachment' );
		Blurhash_Handoff::register();
	}

	/**
	 * Create a bare attachment post.
	 *
	 * @return int Attachment ID.
	 */
	private function make_attachment(): int {
		return (int) wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Hand-off fixture',
			)
		);
	}

	/**
	 * Run the filter the way bundled AP does.
	 *
	 * @param array<string, mixed> $attachment Outbound attachment array.
	 * @param int                  $id         Attachment ID.
	 * @return mixed Filtered attachment.
	 */
	private function apply( array $attachment, int $id ) {
		return apply_filters( 'activitypub_attachment', $attachment, $id );
	}

	/**
	 * Deferral reflects the presence of AP's native class — true here
	 * because the suite loads the stub (or the real class once the
	 * bundled AP carries it).
	 */
	public function test_should_defer_when_ap_class_exists(): void {
		$this->assertTrue( Blurhash_Handoff::should_defer() );
	}

	/**
	 * A FOSSE-era hash is copied into AP's store and injected into the
	 * in-flight payload when AP's injector left the attachment bare.
	 */
	public function test_copies_fosse_hash_to_ap_store_and_injects(): void {
		$id = $this->make_attachment();
		update_post_meta( $id, Blurhash_Handoff::LEGACY_META_KEY, self::HASH );

		$result = $this->apply(
			array(
				'type' => 'Image',
				'url'  => 'https://example.com/a.jpg',
			),
			$id
		);

		$this->assertSame( self::HASH, $result['blurhash'] );
		$this->assertSame( self::HASH, \Activitypub\Blurhash::get( $id ) );
	}

	/**
	 * Once the hash lives in AP's store, AP's own injector provides it
	 * and the bridge must not touch the payload again — pinned by
	 * pre-filling the `blurhash` member the way AP's injector would.
	 */
	public function test_respects_blurhash_already_provided(): void {
		$id = $this->make_attachment();
		update_post_meta( $id, Blurhash_Handoff::LEGACY_META_KEY, self::HASH );

		$result = $this->apply(
			array(
				'type'     => 'Image',
				'blurhash' => 'LKO2?U%2Tw=w]~RBVZRi};RPxuwH',
			),
			$id
		);

		// The pre-existing value wins and AP's store is left alone.
		$this->assertSame( 'LKO2?U%2Tw=w]~RBVZRi};RPxuwH', $result['blurhash'] );
		$this->assertNull( \Activitypub\Blurhash::get( $id ) );
	}

	/**
	 * No FOSSE-era hash means no injection and no AP meta write.
	 */
	public function test_no_fosse_hash_is_a_noop(): void {
		$id = $this->make_attachment();

		$result = $this->apply( array( 'type' => 'Image' ), $id );

		$this->assertArrayNotHasKey( 'blurhash', $result );
		$this->assertNull( \Activitypub\Blurhash::get( $id ) );
	}

	/**
	 * Malformed stored values read as absent through the bridge's
	 * validating read, so poison never crosses into AP's store.
	 */
	public function test_malformed_fosse_hash_is_not_handed_off(): void {
		$id = $this->make_attachment();
		update_post_meta( $id, Blurhash_Handoff::LEGACY_META_KEY, "LEHV6nWB\x00<script>" );

		$result = $this->apply( array( 'type' => 'Image' ), $id );

		$this->assertArrayNotHasKey( 'blurhash', $result );
		$this->assertNull( \Activitypub\Blurhash::get( $id ) );
	}

	/**
	 * Non-Image attachments and non-array payloads pass through verbatim.
	 */
	public function test_ignores_non_image_and_non_array_payloads(): void {
		$id = $this->make_attachment();
		update_post_meta( $id, Blurhash_Handoff::LEGACY_META_KEY, self::HASH );

		$document = array( 'type' => 'Document' );
		$this->assertSame( $document, $this->apply( $document, $id ) );
		$this->assertSame( 'scalar', apply_filters( 'activitypub_attachment', 'scalar', $id ) );
		$this->assertNull( \Activitypub\Blurhash::get( $id ) );
	}

	/**
	 * The FOSSE meta row is intentionally preserved after a hand-off —
	 * cleanup is a future bulk concern, not a hot-path write.
	 */
	public function test_fosse_meta_survives_handoff(): void {
		$id = $this->make_attachment();
		update_post_meta( $id, Blurhash_Handoff::LEGACY_META_KEY, self::HASH );

		$this->apply( array( 'type' => 'Image' ), $id );

		$this->assertSame( self::HASH, get_post_meta( $id, Blurhash_Handoff::LEGACY_META_KEY, true ) );
	}
}
