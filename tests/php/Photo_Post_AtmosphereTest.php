<?php
/**
 * Tests for the photo-post AT Protocol federation-shape projector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Photo_Post;
use Automattic\Fosse\Photo_Post_Atmosphere;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;
use WP_Post;

/**
 * Covers `Photo_Post_Atmosphere::filter_is_short_form_post()`,
 * `filter_post_embed()`, and `filter_transform_bsky_post()` through
 * `apply_filters` round-trips — keeps the contract "FOSSE projects onto
 * Atmosphere's filters" load-bearing in the tests, the same posture
 * {@see Photo_PostTest} uses for the AP-side hooks.
 *
 * The {@see self::apply_transform_filter()} helper simulates Atmosphere's
 * own composition order — run `atmosphere_post_embed` first, attach
 * the result onto the record, then run `atmosphere_transform_bsky_post`
 * — so the embed-attached → text-rewritten chain mirrors what shipped
 * Atmosphere actually does inside `Post::transform()`.
 *
 * Blob uploads are intercepted via the
 * `fosse_photo_post_atmosphere_upload_blob` extension filter so tests
 * don't reach Atmosphere's HTTP layer; the bundled Atmosphere
 * `Facet::extract()` runs for real against the rewritten text.
 */
class Photo_Post_AtmosphereTest extends BaseTestCase {

	/**
	 * Whether `wp_filter_post_kses` was hooked on `content_save_pre`
	 * before {@see self::reset_state()} ran. Tracked so the
	 * after-hook can restore the filter chain — sibling test classes
	 * depend on the default escaping.
	 *
	 * @var bool
	 */
	private bool $had_content_save_pre_kses = false;

	/**
	 * Mirror of {@see self::$had_content_save_pre_kses} for
	 * `content_filtered_save_pre`.
	 *
	 * @var bool
	 */
	private bool $had_content_filtered_save_pre_kses = false;

	/**
	 * Canonical primary image attachment id used by fixtures.
	 *
	 * @var int
	 */
	private int $image_id = 0;

	/**
	 * Secondary image attachment id for multi-image gallery fixtures.
	 *
	 * @var int
	 */
	private int $image_id_alt = 0;

	/**
	 * Per-test map: attachment id → fake blob ref returned by the
	 * `fosse_photo_post_atmosphere_upload_blob` interception. Tests
	 * call {@see self::stub_blob_for()} to seed this map; the
	 * `#[Before]` hook installs a single filter that reads it.
	 *
	 * @var array<int, array>
	 */
	private array $blob_stubs = array();

	/**
	 * Reset filters / cache / fixtures before each test and register
	 * the projector.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		remove_all_filters( 'atmosphere_is_short_form_post' );
		remove_all_filters( 'atmosphere_post_embed' );
		remove_all_filters( 'atmosphere_transform_bsky_post' );
		remove_all_filters( 'fosse_pre_is_photo_post' );
		remove_all_filters( 'fosse_is_photo_post' );
		remove_all_filters( 'fosse_photo_post_atmosphere_upload_blob' );
		remove_all_filters( 'activitypub_max_image_attachments' );
		remove_all_filters( 'get_the_terms' );
		remove_all_filters( 'wp_insert_post_empty_content' );
		remove_all_actions( 'fosse_photo_post_atmosphere_overflow' );

		Photo_Post::reset_cache_for_testing();
		Photo_Post_Atmosphere::register();

		// Empty bodies are valid photo posts (Featured Image + no
		// caption). Same pattern as Photo_PostTest::reset_state().
		add_filter( 'wp_insert_post_empty_content', '__return_false' );

		$this->had_content_save_pre_kses          = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		$this->had_content_filtered_save_pre_kses = false !== has_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
		if ( $this->had_content_save_pre_kses ) {
			remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
		}
		if ( $this->had_content_filtered_save_pre_kses ) {
			remove_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
		}

		$this->image_id     = $this->insert_image_attachment( 'fixture-primary.jpg', 1600, 1200 );
		$this->image_id_alt = $this->insert_image_attachment( 'fixture-alt.jpg', 800, 800 );

		// Reset stub state and install the interception filter.
		// Unstubbed attachments return `false` so the projector
		// treats them as failed uploads without falling through to
		// Atmosphere's real `upload_image_blob()` (which would try
		// to read a real file and warn).
		$this->blob_stubs = array();
		add_filter(
			'fosse_photo_post_atmosphere_upload_blob',
			function ( $pre, int $attachment_id ) {
				if ( null !== $pre ) {
					return $pre;
				}
				return $this->blob_stubs[ $attachment_id ] ?? false;
			},
			10,
			2
		);
	}

	/**
	 * Restore the kses filter chain and drop the empty-content
	 * override so sibling test classes see WP's default behavior.
	 *
	 * @after
	 */
	#[After]
	public function restore_kses_filters(): void {
		if ( $this->had_content_save_pre_kses ) {
			add_filter( 'content_save_pre', 'wp_filter_post_kses' );
		}
		if ( $this->had_content_filtered_save_pre_kses ) {
			add_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
		}
		remove_filter( 'wp_insert_post_empty_content', '__return_false' );
	}

	/**
	 * Insert a real image attachment, seeding both `_wp_attached_file`
	 * (required for `wp_attachment_is_image()`) and
	 * `_wp_attachment_metadata` (required for `aspectRatio` on the
	 * emitted `app.bsky.embed.images` entry).
	 *
	 * @param string $filename Filename (no path).
	 * @param int    $width    Optional intrinsic width (0 = no metadata).
	 * @param int    $height   Optional intrinsic height (0 = no metadata).
	 * @return int Attachment post id.
	 */
	private function insert_image_attachment( string $filename, int $width = 0, int $height = 0 ): int {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'fixture',
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', $filename );

		if ( $width > 0 && $height > 0 ) {
			update_post_meta(
				$attachment_id,
				'_wp_attachment_metadata',
				array(
					'width'  => $width,
					'height' => $height,
					'file'   => $filename,
				)
			);
		}

		return $attachment_id;
	}

	/**
	 * Build a real WP post with optional Featured Image and body.
	 *
	 * @param string $content      Post content.
	 * @param int    $thumbnail_id Attachment id to set as featured image (0 = none).
	 * @param string $title        Optional post title.
	 * @return WP_Post
	 */
	private function make_post( string $content, int $thumbnail_id = 0, string $title = '' ): WP_Post {
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);

		if ( $thumbnail_id > 0 ) {
			update_post_meta( $post_id, '_thumbnail_id', $thumbnail_id );
		}

		return get_post( $post_id );
	}

	/**
	 * Seed the upload-blob stub so a given attachment id returns a
	 * fake blob ref through the interception filter.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $cid           Fake CID for the blob ref.
	 * @return array The blob ref that was registered.
	 */
	private function stub_blob_for( int $attachment_id, string $cid = 'bafyfake' ): array {
		$blob                               = array(
			'$type'    => 'blob',
			'ref'      => array( '$link' => $cid ),
			'mimeType' => 'image/jpeg',
			'size'     => 12345,
		);
		$this->blob_stubs[ $attachment_id ] = $blob;
		return $blob;
	}

	/**
	 * Simulate Atmosphere's short-form composition: run
	 * `atmosphere_post_embed` to build the embed, attach the result
	 * onto the record if non-null, then run
	 * `atmosphere_transform_bsky_post`. Mirrors the call order inside
	 * shipped `Post::transform()` so the test pipeline exercises both
	 * projector filters end-to-end the same way Atmosphere does.
	 *
	 * @param WP_Post $post      The post being transformed.
	 * @param array   $overrides Optional record overrides.
	 * @return array Final record after both filters.
	 */
	private function apply_transform_filter( WP_Post $post, array $overrides = array() ): array {
		$record = array_merge(
			array(
				'$type'     => 'app.bsky.feed.post',
				'text'      => 'baseline caption',
				'createdAt' => '2026-05-19T00:00:00Z',
				'langs'     => array( 'en' ),
			),
			$overrides
		);

		$embed = apply_filters( 'atmosphere_post_embed', null, $post, 'short-form' );
		if ( null !== $embed ) {
			$record['embed'] = $embed;
		}

		return apply_filters(
			'atmosphere_transform_bsky_post',
			$record,
			$post,
			array(
				'strategy'        => 'short-form',
				'thread_index'    => 0,
				'is_thread_reply' => false,
			)
		);
	}

	// ---------------------------------------------------------------
	// is_short_form filter.
	// ---------------------------------------------------------------

	/**
	 * Photo posts must federate as Bluesky short-form so the
	 * link-card / teaser-thread compositions never engage. Without
	 * this hook, a titled photo post would default to link-card and
	 * ship as `app.bsky.embed.external` — the exact failure mode the
	 * issue is fighting.
	 */
	public function test_is_short_form_filter_forces_true_for_photo_post(): void {
		$post = $this->make_post( '', $this->image_id );

		$this->assertTrue( apply_filters( 'atmosphere_is_short_form_post', false, $post ) );
	}

	/**
	 * Non-photo posts pass through — Atmosphere's own discriminator
	 * stays authoritative.
	 */
	public function test_is_short_form_filter_passes_through_for_non_photo_post(): void {
		$post = $this->make_post( '<!-- wp:paragraph --><p>essay body</p><!-- /wp:paragraph -->' );

		$this->assertFalse( apply_filters( 'atmosphere_is_short_form_post', false, $post ) );
		$this->assertTrue( apply_filters( 'atmosphere_is_short_form_post', true, $post ) );
	}

	/**
	 * Defensive: a non-WP_Post second arg must not blow up the
	 * filter.
	 */
	public function test_is_short_form_filter_passes_through_on_non_wp_post(): void {
		$this->assertTrue( apply_filters( 'atmosphere_is_short_form_post', true, null ) );
		$this->assertFalse( apply_filters( 'atmosphere_is_short_form_post', false, 'not-a-post' ) );
	}

	// ---------------------------------------------------------------
	// transform filter: photo-post embed rewrite.
	// ---------------------------------------------------------------

	/**
	 * Photo post with one resolvable image: record's embed becomes
	 * `app.bsky.embed.images` with one entry carrying blob + alt +
	 * aspectRatio.
	 */
	public function test_transform_filter_rewrites_embed_to_images_for_photo_post(): void {
		$this->stub_blob_for( $this->image_id, 'bafyone' );
		update_post_meta( $this->image_id, '_wp_attachment_image_alt', 'A sunset.' );

		$post = $this->make_post( '', $this->image_id );

		$record = $this->apply_transform_filter( $post );

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.images', $record['embed']['$type'] );
		$this->assertCount( 1, $record['embed']['images'] );

		$image = $record['embed']['images'][0];
		$this->assertSame( 'A sunset.', $image['alt'] );
		$this->assertSame( array( '$link' => 'bafyone' ), $image['image']['ref'] );
		$this->assertSame(
			array(
				'width'  => 1600,
				'height' => 1200,
			),
			$image['aspectRatio']
		);
	}

	/**
	 * Non-photo posts pass through — the record is returned
	 * unchanged.
	 */
	public function test_transform_filter_passes_through_for_non_photo_post(): void {
		$post = $this->make_post( '<!-- wp:paragraph --><p>not a photo</p><!-- /wp:paragraph -->' );

		$record = $this->apply_transform_filter( $post );

		$this->assertSame( 'baseline caption', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * The text rewrite uses caption-only content: image-block markup
	 * stripped, paragraph caption preserved.
	 */
	public function test_transform_filter_rewrites_text_to_caption_only(): void {
		$this->stub_blob_for( $this->image_id );

		$content = '<!-- wp:image {"id":' . $this->image_id . '} --><figure class="wp-block-image"><img src="https://example.test/a.jpg"/></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph --><p>Sunset over the bay.</p><!-- /wp:paragraph -->';

		$post = $this->make_post( $content, $this->image_id );

		$record = $this->apply_transform_filter( $post );

		$this->assertStringContainsString( 'Sunset over the bay', $record['text'] );
		$this->assertStringNotContainsString( '<img', $record['text'] );
		$this->assertStringNotContainsString( 'figure', $record['text'] );
	}

	/**
	 * Multi-image gallery: emit up to MAX_IMAGES entries in document
	 * order; the surplus fires the overflow action.
	 */
	public function test_transform_filter_caps_at_max_images_and_fires_overflow(): void {
		// Detection caps at `activitypub_max_image_attachments`
		// (default 4); bump it to 5 so a 5-image post still counts as
		// a photo post and reaches the AT-side cap.
		add_filter( 'activitypub_max_image_attachments', static fn() => 5 );

		// Five resolvable images: featured + four gallery children.
		$image_ids = array( $this->image_id, $this->image_id_alt );
		for ( $i = 0; $i < 3; $i++ ) {
			$image_ids[] = $this->insert_image_attachment( "extra-{$i}.jpg", 1000, 1000 );
		}
		foreach ( $image_ids as $i => $id ) {
			$this->stub_blob_for( $id, 'bafy' . $i );
		}

		$inner = '';
		foreach ( array_slice( $image_ids, 1 ) as $id ) {
			$inner .= '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->';
		}
		$content = '<!-- wp:gallery -->' . $inner . '<!-- /wp:gallery -->';

		$overflow_payload = null;
		add_action(
			'fosse_photo_post_atmosphere_overflow',
			static function ( $post, $overflow ) use ( &$overflow_payload ) {
				$overflow_payload = $overflow;
			},
			10,
			2
		);

		$post = $this->make_post( $content, $this->image_id );

		$record = $this->apply_transform_filter( $post );

		$this->assertCount( Photo_Post_Atmosphere::MAX_IMAGES, $record['embed']['images'] );
		$this->assertSame( array( $image_ids[4] ), $overflow_payload );
	}

	/**
	 * Aspect ratio is omitted when metadata is unavailable — the
	 * `images` entry must still carry `image` + `alt`.
	 */
	public function test_transform_filter_omits_aspect_ratio_when_metadata_missing(): void {
		$bare_id = $this->insert_image_attachment( 'no-meta.jpg', 0, 0 );
		$this->stub_blob_for( $bare_id );

		$content = '<!-- wp:image {"id":' . $bare_id . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->';
		$post    = $this->make_post( $content, $bare_id );

		$record = $this->apply_transform_filter( $post );

		$image = $record['embed']['images'][0];
		$this->assertArrayHasKey( 'image', $image );
		$this->assertArrayHasKey( 'alt', $image );
		$this->assertArrayNotHasKey( 'aspectRatio', $image );
	}

	/**
	 * Featured image already in `core/image` form is not duplicated —
	 * the projector reuses `Photo_Post::collect_image_attachment_ids()`
	 * which deduplicates.
	 */
	public function test_transform_filter_deduplicates_featured_image_in_body(): void {
		$this->stub_blob_for( $this->image_id );

		$content = '<!-- wp:image {"id":' . $this->image_id . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->';
		$post    = $this->make_post( $content, $this->image_id );

		$record = $this->apply_transform_filter( $post );

		$this->assertCount( 1, $record['embed']['images'] );
	}

	/**
	 * Every upload fails: record is returned unchanged. Better to
	 * ship the user's caption with no embed than to ship a broken
	 * `images` embed.
	 */
	public function test_transform_filter_passes_through_when_all_uploads_fail(): void {
		// Don't stub any blob: the interception filter returns `false`
		// for every attachment, which the upload_blob() contract
		// treats as "skip without falling through to Atmosphere."
		$post = $this->make_post( '', $this->image_id );

		$record = $this->apply_transform_filter( $post );

		$this->assertSame( 'baseline caption', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Featured image upload failing aborts the whole projection.
	 * Featured image is the hero shot; shipping a gallery missing it
	 * is worse than shipping no embed at all. Asserts the new
	 * fast-fail path on the first attachment when it matches the
	 * featured-image id.
	 */
	public function test_transform_filter_aborts_when_featured_image_upload_fails(): void {
		// Two-image post: featured image (no blob stub → upload fails)
		// + body image (blob stubbed → would upload). The projector
		// must NOT ship just the body image; it must return the
		// record unchanged.
		$this->stub_blob_for( $this->image_id_alt );

		$content = '<!-- wp:image {"id":' . $this->image_id_alt . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->';
		$post    = $this->make_post( $content, $this->image_id );

		$record = $this->apply_transform_filter( $post );

		$this->assertSame( 'baseline caption', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Non-featured upload failure drops just that attachment from the
	 * embed while the rest ship. The hero shot is preserved; a partial
	 * gallery is acceptable degradation.
	 */
	public function test_transform_filter_drops_non_featured_failed_upload_but_ships_rest(): void {
		// Featured stubbed (uploads cleanly), body image not stubbed
		// (upload fails). Expect the embed to ship with one image —
		// the featured one — and the body image silently dropped.
		$this->stub_blob_for( $this->image_id, 'bafyhero' );

		$content = '<!-- wp:image {"id":' . $this->image_id_alt . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->';
		$post    = $this->make_post( $content, $this->image_id );

		$record = $this->apply_transform_filter( $post );

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.images', $record['embed']['$type'] );
		$this->assertCount( 1, $record['embed']['images'] );
		$this->assertSame( array( '$link' => 'bafyhero' ), $record['embed']['images'][0]['image']['ref'] );
	}

	/**
	 * Filter returns a malformed blob-ref array (missing `$type`):
	 * projector treats as a failed upload and drops the attachment.
	 * Protects the federation envelope from a third-party filter that
	 * returns an arbitrary array.
	 */
	public function test_transform_filter_rejects_malformed_blob_ref_from_filter(): void {
		// Override the per-test stub with one that returns a malformed
		// array for the featured image.
		add_filter(
			'fosse_photo_post_atmosphere_upload_blob',
			static function () {
				return array(
					// Missing $type key — should be rejected.
					'ref'      => array( '$link' => 'bafyforged' ),
					'mimeType' => 'image/jpeg',
					'size'     => 12345,
				);
			},
			20
		);

		$post = $this->make_post( '', $this->image_id );

		$record = $this->apply_transform_filter( $post );

		// Malformed featured-image return = failed upload = featured
		// fast-fail path = unchanged record.
		$this->assertSame( 'baseline caption', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Filter throws: projector catches the exception per-attachment
	 * and treats it as a failed upload. Asserts a misbehaving filter
	 * listener can't crater the whole federation event.
	 */
	public function test_transform_filter_catches_filter_exception_per_attachment(): void {
		// Featured stubbed cleanly. Add a second filter (higher
		// priority so it runs after our stub) that throws for the
		// body image only.
		$this->stub_blob_for( $this->image_id, 'bafyhero' );

		add_filter(
			'fosse_photo_post_atmosphere_upload_blob',
			function ( $pre, int $attachment_id ) {
				if ( $attachment_id === $this->image_id_alt ) {
					throw new \RuntimeException( 'simulated filter crash' );
				}
				return $pre;
			},
			20,
			2
		);

		$content = '<!-- wp:image {"id":' . $this->image_id_alt . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->';
		$post    = $this->make_post( $content, $this->image_id );

		$record = $this->apply_transform_filter( $post );

		// Throwing on the body image only drops that attachment;
		// featured still ships.
		$this->assertArrayHasKey( 'embed', $record );
		$this->assertCount( 1, $record['embed']['images'] );
		$this->assertSame( array( '$link' => 'bafyhero' ), $record['embed']['images'][0]['image']['ref'] );
	}

	/**
	 * Defensive: a non-`short-form` strategy on the embed filter must
	 * leave the input embed alone. Photo posts force
	 * `is_short_form_post()` true so the link-card / teaser-thread
	 * paths never see a photo post in practice, but if a third-party
	 * filter forces a photo post off the short-form path we must not
	 * attach `app.bsky.embed.images` to a teaser thread (which still
	 * expects an external card on its terminal entry).
	 */
	public function test_post_embed_filter_passes_through_for_non_short_form_strategy(): void {
		$this->stub_blob_for( $this->image_id );
		$post = $this->make_post( '', $this->image_id );

		$default_external = array(
			'$type'    => 'app.bsky.embed.external',
			'external' => array(
				'uri'         => 'https://example.test/p/1',
				'title'       => 'fallback',
				'description' => '',
			),
		);

		$this->assertSame(
			$default_external,
			apply_filters( 'atmosphere_post_embed', $default_external, $post, 'teaser-thread' )
		);
		$this->assertSame(
			$default_external,
			apply_filters( 'atmosphere_post_embed', $default_external, $post, 'link-card' )
		);
	}

	/**
	 * Defensive: a non-WP_Post second arg to the embed filter must
	 * pass the input through unchanged. Same posture as the
	 * `is_short_form` filter — a stray hook call (third-party plugin
	 * that re-fires `atmosphere_post_embed` with the wrong shape) must
	 * not crash the federation event.
	 */
	public function test_post_embed_filter_passes_through_on_non_wp_post(): void {
		$this->assertNull( apply_filters( 'atmosphere_post_embed', null, null, 'short-form' ) );
		$this->assertSame(
			array( '$type' => 'app.bsky.embed.external' ),
			apply_filters(
				'atmosphere_post_embed',
				array( '$type' => 'app.bsky.embed.external' ),
				'not-a-post',
				'short-form'
			)
		);
	}

	/**
	 * Defensive: non-short-form context (e.g. a teaser-thread CTA)
	 * doesn't trigger the rewrite. Photo posts force
	 * `is_short_form_post()` true so this gate should never fire in
	 * practice, but a future Atmosphere change that reroutes
	 * short-form through a thread shape must not silently rewrite
	 * every entry.
	 */
	public function test_transform_filter_skips_non_short_form_strategy(): void {
		$this->stub_blob_for( $this->image_id );
		$post = $this->make_post( '', $this->image_id );

		$record = apply_filters(
			'atmosphere_transform_bsky_post',
			array(
				'$type'     => 'app.bsky.feed.post',
				'text'      => 'cta text',
				'createdAt' => '2026-05-19T00:00:00Z',
				'langs'     => array( 'en' ),
			),
			$post,
			array(
				'strategy'        => 'teaser-thread',
				'thread_index'    => 2,
				'is_thread_reply' => true,
			)
		);

		$this->assertSame( 'cta text', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Defensive: a non-WP_Post second arg passes through unchanged.
	 */
	public function test_transform_filter_passes_through_on_non_wp_post(): void {
		$record = apply_filters(
			'atmosphere_transform_bsky_post',
			array(
				'$type' => 'app.bsky.feed.post',
				'text'  => 'baseline',
				'langs' => array( 'en' ),
			),
			null,
			array( 'strategy' => 'short-form' )
		);

		$this->assertSame( 'baseline', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Text exceeding Bluesky's 300-grapheme cap is truncated. Asserts
	 * the truncation actually fired (text length is exactly 300, not
	 * just <= 300) so a regression that skips truncation entirely
	 * surfaces here instead of silently passing.
	 */
	public function test_transform_filter_truncates_caption_to_text_budget(): void {
		$this->stub_blob_for( $this->image_id );

		$long_caption = str_repeat( 'word ', 120 );
		$content      = '<!-- wp:image {"id":' . $this->image_id . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph --><p>' . $long_caption . '</p><!-- /wp:paragraph -->';

		$post = $this->make_post( $content, $this->image_id );

		$record = $this->apply_transform_filter( $post );

		$this->assertSame( 300, mb_strlen( $record['text'] ) );
	}

	/**
	 * URL straddling the 300-grapheme truncation boundary: the facet
	 * for it must be dropped from the record. A facet whose byteEnd
	 * exceeds the final text length would either fail PDS validation
	 * or render as a wrong-target link in Bluesky's UI.
	 */
	public function test_transform_filter_drops_facets_past_truncation_boundary(): void {
		$this->stub_blob_for( $this->image_id );

		// Pad text so a URL starts in-bounds (under 300 graphemes) but
		// extends past 300. The padding is just enough to push the
		// URL's tail past the truncation budget.
		$padding = str_repeat( 'word ', 58 ); // ~290 chars
		$url     = 'https://example.test/some/long/path/that/will/be/cut';
		$content = '<!-- wp:image {"id":' . $this->image_id . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph --><p>' . $padding . $url . '</p><!-- /wp:paragraph -->';

		$post = $this->make_post( $content, $this->image_id );

		$record = $this->apply_transform_filter( $post );

		$this->assertSame( 300, mb_strlen( $record['text'] ) );

		// Every surviving facet must have byteEnd within the final
		// text. A truncated URL would have byteEnd past the boundary,
		// which the projector drops.
		$final_byte_length = strlen( $record['text'] );
		foreach ( ( $record['facets'] ?? array() ) as $facet ) {
			$this->assertLessThanOrEqual( $final_byte_length, (int) $facet['index']['byteEnd'] );
		}
	}

	/**
	 * Facets are re-extracted against the rewritten caption text —
	 * a URL in the caption produces a `link` facet keyed against the
	 * new text, not against the pre-strip baseline.
	 */
	public function test_transform_filter_re_extracts_facets_against_new_text(): void {
		$this->stub_blob_for( $this->image_id );

		$content = '<!-- wp:image {"id":' . $this->image_id . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph --><p>See https://example.test/page for details.</p><!-- /wp:paragraph -->';

		$post = $this->make_post( $content, $this->image_id );

		$record = $this->apply_transform_filter( $post, array( 'facets' => array( array( 'stale' => true ) ) ) );

		$this->assertArrayHasKey( 'facets', $record );
		$serialized_uris = array_column(
			array_column( array_column( $record['facets'], 'features' ), 0 ),
			'uri'
		);
		$this->assertContains( 'https://example.test/page', $serialized_uris );
		$this->assertStringNotContainsString( 'stale', (string) wp_json_encode( $record['facets'], JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * When the re-extracted facets are empty, the field is dropped —
	 * leaving a stale facets array would lie about the new text's
	 * link positions.
	 */
	public function test_transform_filter_drops_stale_facets_when_re_extract_is_empty(): void {
		$this->stub_blob_for( $this->image_id );

		$post = $this->make_post( '', $this->image_id );

		$record = $this->apply_transform_filter( $post, array( 'facets' => array( array( 'stale' => true ) ) ) );

		$this->assertArrayNotHasKey( 'facets', $record );
	}

	// ---------------------------------------------------------------
	// collect_image_attachment_ids (helper on Photo_Post).
	// ---------------------------------------------------------------

	/**
	 * Featured image first, body images in document order.
	 */
	public function test_collect_image_ids_orders_featured_first(): void {
		$content = '<!-- wp:image {"id":' . $this->image_id_alt . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->';
		$post    = $this->make_post( $content, $this->image_id );

		$ids = Photo_Post::collect_image_attachment_ids( $post );

		$this->assertSame( array( $this->image_id, $this->image_id_alt ), $ids );
	}

	/**
	 * Featured image present in both postmeta and a `core/image` block
	 * appears once.
	 */
	public function test_collect_image_ids_deduplicates_featured_when_also_in_body(): void {
		$content = '<!-- wp:image {"id":' . $this->image_id . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->';
		$post    = $this->make_post( $content, $this->image_id );

		$ids = Photo_Post::collect_image_attachment_ids( $post );

		$this->assertSame( array( $this->image_id ), $ids );
	}

	/**
	 * Gallery walks `innerBlocks` in document order.
	 */
	public function test_collect_image_ids_walks_gallery_inner_blocks_in_order(): void {
		$inner   = '<!-- wp:image {"id":' . $this->image_id_alt . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->'
			. '<!-- wp:image {"id":' . $this->image_id . '} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->';
		$content = '<!-- wp:gallery -->' . $inner . '<!-- /wp:gallery -->';
		$post    = $this->make_post( $content );

		$ids = Photo_Post::collect_image_attachment_ids( $post );

		$this->assertSame( array( $this->image_id_alt, $this->image_id ), $ids );
	}

	/**
	 * `core/image` blocks without a resolvable id (external image)
	 * don't contribute — same gate as detection.
	 */
	public function test_collect_image_ids_skips_unresolvable_image_blocks(): void {
		$content = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/external.jpg"/></figure><!-- /wp:image -->';
		$post    = $this->make_post( $content );

		$this->assertSame( array(), Photo_Post::collect_image_attachment_ids( $post ) );
	}

	// ---------------------------------------------------------------
	// caption_text (helper on Photo_Post).
	// ---------------------------------------------------------------

	/**
	 * `caption_text()` returns plain-text caption with image markup
	 * stripped.
	 */
	public function test_caption_text_strips_image_markup_and_returns_plain(): void {
		$content = '<!-- wp:image {"id":' . $this->image_id . '} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph --><p>Caption text.</p><!-- /wp:paragraph -->';
		$post    = $this->make_post( $content, $this->image_id );

		$text = Photo_Post::caption_text( $post );

		$this->assertStringContainsString( 'Caption text.', $text );
		$this->assertStringNotContainsString( '<', $text );
		$this->assertStringNotContainsString( 'figure', $text );
	}

	/**
	 * Empty body returns an empty caption — Featured-Image-only photo
	 * posts have no caption to ship.
	 */
	public function test_caption_text_returns_empty_for_empty_body(): void {
		$post = $this->make_post( '', $this->image_id );

		$this->assertSame( '', Photo_Post::caption_text( $post ) );
	}
}
