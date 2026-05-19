<?php
/**
 * Tests for the photo-post detector + AP federation-shape projector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Photo_Post;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use WorDBless\BaseTestCase;
use WP_Post;

/**
 * Covers `Photo_Post::is_photo_post()` detection rules, the
 * `fosse_pre_is_photo_post` short-circuit, the `fosse_is_photo_post`
 * final filter, and the three AP transformer hooks
 * (`activitypub_post_object_type`, `activitypub_the_content`,
 * `activitypub_attachment`).
 *
 * Detection is exercised through `apply_filters` round-trips wherever
 * possible — keeps the contract that "FOSSE projects onto AP filters"
 * load-bearing instead of poking the helpers in isolation.
 */
class Photo_PostTest extends BaseTestCase {

	/**
	 * Reset filter / cache state before each test and register the
	 * projector.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		remove_all_filters( 'activitypub_post_object_type' );
		remove_all_filters( 'activitypub_the_content' );
		remove_all_filters( 'activitypub_attachment' );
		remove_all_filters( 'fosse_pre_is_photo_post' );
		remove_all_filters( 'fosse_is_photo_post' );
		remove_all_filters( 'get_the_terms' );
		remove_all_filters( 'wp_insert_post_empty_content' );

		Photo_Post::reset_cache_for_testing();
		Photo_Post::register();

		// Empty bodies are valid photo posts (Featured Image + no
		// caption, or "image format with zero text"). Override WP's
		// default empty-content rejection so `wp_insert_post` returns
		// an id for those cases too.
		add_filter( 'wp_insert_post_empty_content', '__return_false' );

		// WorDBless installs the kses `content_save_pre` filter
		// regardless of user context, so every `wp_insert_post`
		// would slash block-attribute JSON — but in production
		// admin users (with `unfiltered_html`) skip that filter
		// entirely. Strip it here so test inserts represent the
		// admin-authored path. The `test_slashed_block_attributes_*`
		// case re-introduces slashes explicitly via `addslashes()`
		// when it needs to exercise the non-admin storage shape.
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
		remove_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
	}

	/**
	 * Build a real WP post with optional title, post format, and a
	 * featured-image thumbnail.
	 *
	 * Post format is injected via a `get_the_terms` filter rather than
	 * `set_post_format()` — WorDBless's database-less storage layer
	 * accepts term inserts but does not round-trip the
	 * object/term relationships back through `wp_get_object_terms()`,
	 * so `get_post_format()` reads empty even after a successful
	 * `set_post_format()` call. The filter approach exercises the
	 * same code path `get_post_format()` actually uses (the
	 * `get_the_terms` filter chain) and works under WorDBless.
	 *
	 * When `$thumbnail_id` is non-zero, we also need
	 * `wp_attachment_is_image()` to return true — that check runs an
	 * `attachment.mime_type` lookup, so we insert a real attachment
	 * post with an image MIME type. Pass a negative `$thumbnail_id`
	 * to simulate a deleted thumbnail (postmeta survives, attachment
	 * does not).
	 *
	 * Each call generates a fresh post id so the per-post memoization
	 * cache doesn't leak between cases.
	 *
	 * @param string $content      Raw post content.
	 * @param string $title        Optional post title.
	 * @param string $post_format  Optional post format slug (image, gallery, status, …).
	 * @param int    $thumbnail_id 0 = no thumbnail; >0 = create attachment + meta; <0 = postmeta only (simulates deleted attachment).
	 * @return WP_Post
	 */
	private function make_post(
		string $content,
		string $title = '',
		string $post_format = '',
		int $thumbnail_id = 0
	): WP_Post {
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);

		if ( '' !== $post_format ) {
			$term = (object) array(
				'slug'     => 'post-format-' . $post_format,
				'name'     => $post_format,
				'taxonomy' => 'post_format',
				'term_id'  => 999,
			);

			add_filter(
				'get_the_terms',
				static function ( $terms, $object_id, $taxonomy ) use ( $post_id, $term ) {
					if ( (int) $object_id === (int) $post_id && 'post_format' === $taxonomy ) {
						return array( $term );
					}
					return $terms;
				},
				10,
				3
			);
		}

		if ( $thumbnail_id > 0 ) {
			$attachment_id = wp_insert_post(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image/jpeg',
					'post_title'     => 'thumb',
				)
			);
			// `wp_attachment_is_image()` calls `get_attached_file()`,
			// which reads `_wp_attached_file`. Without it the helper
			// returns false even on a properly-MIMEd attachment row.
			update_post_meta( $attachment_id, '_wp_attached_file', 'thumb.jpg' );
			update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
		} elseif ( $thumbnail_id < 0 ) {
			update_post_meta( $post_id, '_thumbnail_id', 999999 );
		}

		return get_post( $post_id );
	}

	// ---------------------------------------------------------------
	// Discriminator: guards.
	// ---------------------------------------------------------------

	/**
	 * Non-`WP_Post` input must short-circuit to false. Defends against
	 * stray filter contract drift — a stranger calling `is_photo_post`
	 * with garbage shouldn't be able to flip the AP envelope.
	 */
	public function test_discriminator_returns_false_for_non_post_input(): void {
		$this->assertFalse( Photo_Post::is_photo_post( null ) );
		$this->assertFalse( Photo_Post::is_photo_post( 'not-a-post' ) );
		$this->assertFalse( Photo_Post::is_photo_post( 999999 ) );
	}

	// ---------------------------------------------------------------
	// Discriminator: pre-filter short-circuit.
	// ---------------------------------------------------------------

	/**
	 * `fosse_pre_is_photo_post` returning true wins outright — built-in
	 * detection must not run. This is the documented escape hatch for
	 * sites with a custom photo CPT that want to hardwire the answer.
	 */
	public function test_pre_filter_short_circuits_to_true(): void {
		add_filter( 'fosse_pre_is_photo_post', '__return_true' );

		// Body is a plain paragraph, which the built-in detector would
		// classify as not a photo. The pre-filter wins anyway.
		$post = $this->make_post( '<!-- wp:paragraph --><p>Nothing photographic.</p><!-- /wp:paragraph -->' );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * `fosse_pre_is_photo_post` returning false also short-circuits —
	 * a site can suppress photo treatment for posts the built-in
	 * detector would otherwise catch.
	 */
	public function test_pre_filter_short_circuits_to_false(): void {
		add_filter( 'fosse_pre_is_photo_post', '__return_false' );

		$post = $this->make_post( '', '', 'image' );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Returning null from the pre-filter is the documented "no opinion"
	 * sentinel — detection must run as if the pre-filter weren't there.
	 */
	public function test_pre_filter_null_falls_through_to_detection(): void {
		add_filter(
			'fosse_pre_is_photo_post',
			static function () {
				return null;
			}
		);

		$post = $this->make_post( '', '', 'image' );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	// ---------------------------------------------------------------
	// Discriminator: final filter.
	// ---------------------------------------------------------------

	/**
	 * `fosse_is_photo_post` can downgrade a detected positive — e.g.
	 * "exclude posts in the announcements category from being treated
	 * as photo posts even when the body is image-only."
	 */
	public function test_final_filter_can_downgrade_detected_positive(): void {
		add_filter( 'fosse_is_photo_post', '__return_false' );

		$post = $this->make_post( '', '', 'image' );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * `fosse_is_photo_post` can upgrade a detected negative — useful for
	 * CPTs whose body uses a custom block FOSSE doesn't recognize as
	 * image-like.
	 */
	public function test_final_filter_can_upgrade_detected_negative(): void {
		add_filter( 'fosse_is_photo_post', '__return_true' );

		$post = $this->make_post( '<!-- wp:paragraph --><p>Just text.</p><!-- /wp:paragraph -->' );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	// ---------------------------------------------------------------
	// Detection: post format.
	// ---------------------------------------------------------------

	/**
	 * Post Format `image` is the canonical "this is a photo" signal in
	 * WordPress and maps 1:1 to Pixelfed's "this is a photo" gate.
	 *
	 * @param string $format Post format slug.
	 * @param bool   $expected Whether the detector should flag the post.
	 *
	 * @dataProvider post_format_cases
	 */
	#[DataProvider( 'post_format_cases' )]
	public function test_post_format_drives_detection( string $format, bool $expected ): void {
		$post = $this->make_post( '<!-- wp:paragraph --><p>caption</p><!-- /wp:paragraph -->', '', $format );

		$this->assertSame( $expected, Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Data provider for `test_post_format_drives_detection`.
	 *
	 * @return array<string, array{0:string, 1:bool}>
	 */
	public static function post_format_cases(): array {
		return array(
			'image format'    => array( 'image', true ),
			'gallery format'  => array( 'gallery', true ),
			'status format'   => array( 'status', false ),
			'standard format' => array( 'standard', false ),
			'video format'    => array( 'video', false ),
			'audio format'    => array( 'audio', false ),
		);
	}

	// ---------------------------------------------------------------
	// Detection: block-shape (Rule 2).
	// ---------------------------------------------------------------

	/**
	 * Body shape decides detection when post format is absent. The
	 * scenarios here mirror the rule set documented on the class:
	 *  - one image-like block (with or without a paragraph) → photo
	 *  - image + 2+ paragraphs → not a photo (it's an article)
	 *  - paragraph only → not a photo
	 *  - image + non-paragraph block (heading) → not a photo
	 *  - empty paragraphs / spacer / separator → ignored
	 *
	 * @param string $content  Raw block markup.
	 * @param bool   $expected Whether the detector should flag the post.
	 *
	 * @dataProvider block_shape_cases
	 */
	#[DataProvider( 'block_shape_cases' )]
	public function test_block_shape_drives_detection( string $content, bool $expected ): void {
		$post = $this->make_post( $content );

		$this->assertSame( $expected, Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Data provider for `test_block_shape_drives_detection`.
	 *
	 * @return array<string, array{0:string, 1:bool}>
	 */
	public static function block_shape_cases(): array {
		// Block fixtures use `id` attributes that signal a
		// local attachment to `Photo_Post::block_resolves_locally`.
		// Galleries use the WP 5.9+ block-nested shape (innerBlocks
		// containing `core/image` children) — legacy galleries with
		// only a top-level `attrs.ids` array are intentionally not
		// recognized because bundled AP's extractor doesn't read
		// that path either. External-URL image blocks (no `id`) and
		// post-featured-image blocks (which require a real
		// thumbnail) are exercised in dedicated test methods below —
		// the data provider is static and cannot wire up per-case
		// thumbnail state.
		$image     = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="https://example.test/a.jpg" alt=""/></figure><!-- /wp:image -->';
		$gallery   = '<!-- wp:gallery -->'
			. '<figure class="wp-block-gallery">'
			. '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->'
			. '<!-- wp:image {"id":43} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->'
			. '</figure>'
			. '<!-- /wp:gallery -->';
		$paragraph = '<!-- wp:paragraph --><p>caption text</p><!-- /wp:paragraph -->';
		$heading   = '<!-- wp:heading --><h2>headline</h2><!-- /wp:heading -->';
		$spacer    = '<!-- wp:spacer --><div class="wp-block-spacer"></div><!-- /wp:spacer -->';
		$separator = '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->';
		$empty_p   = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';

		return array(
			'image only'                  => array( $image, true ),
			'image plus single paragraph' => array( $image . $paragraph, true ),
			'image plus two paragraphs'   => array( $image . $paragraph . $paragraph, false ),
			'paragraph only'              => array( $paragraph, false ),
			'gallery only'                => array( $gallery, true ),
			'gallery plus paragraph'      => array( $gallery . $paragraph, true ),
			'image plus heading'          => array( $image . $heading, false ),
			'two images'                  => array( $image . $image, false ),
			'image plus empty paragraph'  => array( $image . $empty_p, true ),
			'image plus spacer plus para' => array( $image . $spacer . $paragraph, true ),
			'image plus separator'        => array( $image . $separator, true ),
			'empty content'               => array( '', false ),
		);
	}

	// ---------------------------------------------------------------
	// Detection: title presence is not a disqualifier.
	// ---------------------------------------------------------------

	/**
	 * A non-empty title must not suppress photo detection — the user
	 * explicitly asked on `DOTCOM-17143` for "Headline. Big photo.
	 * Caption." to still count.
	 */
	public function test_title_does_not_suppress_detection(): void {
		$image = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="https://example.test/a.jpg"/></figure><!-- /wp:image -->';
		$post  = $this->make_post( $image, 'My Photo Title' );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	// ---------------------------------------------------------------
	// Detection: featured-image-thumbnail flow (Rule 3).
	// ---------------------------------------------------------------

	/**
	 * Empty body + Featured Image set = "set thumbnail, hit publish"
	 * mobile-app flow. Detector must catch this case even though
	 * `parse_blocks('')` yields nothing.
	 */
	public function test_featured_thumbnail_with_empty_body_detects(): void {
		$post = $this->make_post( '', '', '', 1 );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Featured Image set + body = single caption paragraph should also
	 * detect — classic-editor flow where the body is the caption.
	 */
	public function test_featured_thumbnail_with_single_paragraph_detects(): void {
		$paragraph = '<!-- wp:paragraph --><p>Caption.</p><!-- /wp:paragraph -->';
		$post      = $this->make_post( $paragraph, '', '', 1 );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Featured Image + 2 paragraphs is an article that happens to have
	 * a hero image. Don't classify as a photo post — that's a
	 * blog-post-with-image, not a photo-with-caption.
	 */
	public function test_featured_thumbnail_with_two_paragraphs_does_not_detect(): void {
		$paragraph = '<!-- wp:paragraph --><p>A line.</p><!-- /wp:paragraph -->';
		$post      = $this->make_post( $paragraph . $paragraph, '', '', 1 );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Same body shape without a featured image must NOT detect. Guards
	 * against the rule-3 path firing on plain text posts just because
	 * they happen to fit "≤1 paragraph."
	 */
	public function test_single_paragraph_without_thumbnail_does_not_detect(): void {
		$paragraph = '<!-- wp:paragraph --><p>Caption.</p><!-- /wp:paragraph -->';
		$post      = $this->make_post( $paragraph );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	// ---------------------------------------------------------------
	// Detection: caching.
	// ---------------------------------------------------------------

	/**
	 * The decision is memoized per post id so the AP transformer
	 * hooks (which can each call `is_photo_post` independently in one
	 * pass) don't re-parse the body each time.
	 */
	public function test_decision_is_cached_per_post(): void {
		$image = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/a.jpg"/></figure><!-- /wp:image -->';
		$post  = $this->make_post( $image );

		$call_count = 0;
		add_filter(
			'fosse_pre_is_photo_post',
			static function ( $value ) use ( &$call_count ) {
				++$call_count;
				return $value;
			}
		);

		Photo_Post::is_photo_post( $post );
		Photo_Post::is_photo_post( $post );
		Photo_Post::is_photo_post( $post );

		$this->assertSame( 1, $call_count, 'Pre-filter must run exactly once per post id thanks to memoization.' );
	}

	// ---------------------------------------------------------------
	// AP hook: object type.
	// ---------------------------------------------------------------

	/**
	 * Photo posts get `type: "Note"` regardless of what bundled AP
	 * would have computed — the prerequisite for Pixelfed's photo
	 * timeline rendering.
	 */
	public function test_object_type_filter_forces_note_for_photo_post(): void {
		$post = $this->make_post( '', '', 'image' );

		$type = apply_filters( 'activitypub_post_object_type', 'Article', $post );

		$this->assertSame( 'Note', $type );
	}

	/**
	 * Non-photo posts pass through — bundled AP's own Note / Article /
	 * Page decision stays authoritative.
	 */
	public function test_object_type_filter_passes_through_for_non_photo_post(): void {
		$post = $this->make_post( '<!-- wp:paragraph --><p>essay</p><!-- /wp:paragraph -->' );

		$type = apply_filters( 'activitypub_post_object_type', 'Article', $post );

		$this->assertSame( 'Article', $type );
	}

	/**
	 * Defensive: a non-WP_Post second arg must not blow up the filter.
	 */
	public function test_object_type_filter_passes_through_on_non_wp_post(): void {
		$type = apply_filters( 'activitypub_post_object_type', 'Article', null );

		$this->assertSame( 'Article', $type );
	}

	// ---------------------------------------------------------------
	// AP hook: content stripping.
	// ---------------------------------------------------------------

	/**
	 * Image-block markup must be stripped so the AP `content` is the
	 * caption only — the photo lives in `attachment[]`.
	 */
	public function test_content_filter_strips_image_block(): void {
		$post     = $this->make_post( '', '', 'image' );
		$content  = '<figure class="wp-block-image"><img src="https://example.test/a.jpg" alt="Sunset"/></figure><p>Sunset over the bay.</p>';
		$filtered = apply_filters( 'activitypub_the_content', $content, $post );

		$this->assertStringNotContainsString( '<img', $filtered );
		$this->assertStringNotContainsString( 'wp-block-image', $filtered );
		$this->assertStringContainsString( 'Sunset over the bay.', $filtered );
	}

	/**
	 * Gallery wrappers (which contain nested figures) must also strip
	 * cleanly without leaving inner `<img>` orphans.
	 */
	public function test_content_filter_strips_gallery_block(): void {
		$post     = $this->make_post( '', '', 'gallery' );
		$content  = '<figure class="wp-block-gallery"><figure class="wp-block-image"><img src="https://example.test/a.jpg"/></figure></figure><p>Trip recap.</p>';
		$filtered = apply_filters( 'activitypub_the_content', $content, $post );

		$this->assertStringNotContainsString( '<img', $filtered );
		$this->assertStringNotContainsString( 'wp-block', $filtered );
		$this->assertStringContainsString( 'Trip recap.', $filtered );
	}

	/**
	 * The Featured Image block renders to its own wrapper class and
	 * must strip the same way.
	 */
	public function test_content_filter_strips_post_featured_image_block(): void {
		$post     = $this->make_post( '', '', 'image' );
		$content  = '<figure class="wp-block-post-featured-image"><img src="https://example.test/a.jpg"/></figure><p>Caption.</p>';
		$filtered = apply_filters( 'activitypub_the_content', $content, $post );

		$this->assertStringNotContainsString( '<img', $filtered );
		$this->assertStringContainsString( 'Caption.', $filtered );
	}

	/**
	 * Empty paragraph wrappers left behind by the editor after the
	 * image is stripped should clean up too — otherwise federated
	 * content gets a leading `<p></p>` shell.
	 */
	public function test_content_filter_cleans_empty_paragraph_wrappers(): void {
		$post     = $this->make_post( '', '', 'image' );
		$content  = '<p><img src="https://example.test/a.jpg"/></p><p>Caption.</p>';
		$filtered = apply_filters( 'activitypub_the_content', $content, $post );

		$this->assertStringNotContainsString( '<p></p>', $filtered );
		$this->assertStringContainsString( 'Caption.', $filtered );
	}

	/**
	 * Non-photo posts must NOT have their content stripped — long-form
	 * articles need their inline images preserved.
	 */
	public function test_content_filter_passes_through_for_non_photo_post(): void {
		$post     = $this->make_post( '<!-- wp:paragraph --><p>essay</p><!-- /wp:paragraph -->' );
		$content  = '<figure class="wp-block-image"><img src="https://example.test/a.jpg"/></figure><p>essay body</p>';
		$filtered = apply_filters( 'activitypub_the_content', $content, $post );

		$this->assertSame( $content, $filtered );
	}

	// ---------------------------------------------------------------
	// AP hook: attachment dimensions.
	// ---------------------------------------------------------------

	/**
	 * Images without `width`/`height` get them filled in from
	 * `wp_get_attachment_metadata()`. Pixelfed enforces the bounds
	 * server-side; Mastodon uses them for layout.
	 */
	public function test_attachment_filter_adds_width_height_from_metadata(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'test',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => 'a.jpg',
				'width'  => 2048,
				'height' => 1365,
			)
		);

		$input = array(
			'type'      => 'Image',
			'url'       => 'https://example.test/a.jpg',
			'mediaType' => 'image/jpeg',
		);

		$out = apply_filters( 'activitypub_attachment', $input, $attachment_id );

		$this->assertSame( 2048, $out['width'] );
		$this->assertSame( 1365, $out['height'] );
	}

	/**
	 * Non-image attachments (audio / video) must not be touched —
	 * bundled AP already adds width/height for those, and this filter
	 * is purely "close the gap on images."
	 */
	public function test_attachment_filter_skips_non_image(): void {
		$input = array(
			'type'      => 'Video',
			'url'       => 'https://example.test/a.mp4',
			'mediaType' => 'video/mp4',
			'width'     => 1920,
			'height'    => 1080,
		);

		$out = apply_filters( 'activitypub_attachment', $input, 0 );

		$this->assertSame( $input, $out );
	}

	/**
	 * Pre-existing dimensions must be preserved — never overwrite what
	 * an upstream callback (or future bundled-AP improvement) already
	 * computed.
	 */
	public function test_attachment_filter_preserves_existing_dimensions(): void {
		$input = array(
			'type'      => 'Image',
			'url'       => 'https://example.test/a.jpg',
			'mediaType' => 'image/jpeg',
			'width'     => 100,
			'height'    => 200,
		);

		$out = apply_filters( 'activitypub_attachment', $input, 0 );

		$this->assertSame( 100, $out['width'] );
		$this->assertSame( 200, $out['height'] );
	}

	/**
	 * Missing or non-array metadata is the common "external image with
	 * no local attachment" case — leave the attachment unchanged.
	 */
	public function test_attachment_filter_skips_when_metadata_missing(): void {
		$input = array(
			'type'      => 'Image',
			'url'       => 'https://example.test/a.jpg',
			'mediaType' => 'image/jpeg',
		);

		$out = apply_filters( 'activitypub_attachment', $input, 0 );

		$this->assertArrayNotHasKey( 'width', $out );
		$this->assertArrayNotHasKey( 'height', $out );
	}

	// ---------------------------------------------------------------
	// AP hook: attachment dimensions — URL/size matching.
	// ---------------------------------------------------------------

	/**
	 * Bundled AP emits image URLs at the `large` derivative
	 * (`wp_get_attachment_image_src( $id, 'large' )`). The dimensions
	 * we attach must describe THAT file, not the 4000-pixel original
	 * sitting on disk — Pixelfed enforces dimensions server-side and
	 * rejects mismatched values. Resized derivatives carry a
	 * `-WIDTHxHEIGHT.<ext>` suffix in the filename, which is the
	 * stable signal we key off.
	 */
	public function test_attachment_filter_uses_filename_suffix_dimensions(): void {
		$input = array(
			'type'      => 'Image',
			'url'       => 'https://example.test/wp-content/uploads/2026/05/sunset-1024x683.jpg',
			'mediaType' => 'image/jpeg',
		);

		$out = apply_filters( 'activitypub_attachment', $input, 0 );

		$this->assertSame( 1024, $out['width'] );
		$this->assertSame( 683, $out['height'] );
	}

	/**
	 * The suffix match must tolerate a query string (CDN cache-bust)
	 * or fragment (rare but valid). Real-world CDN rewrites tack
	 * `?ver=…` onto image URLs frequently.
	 */
	public function test_attachment_filter_filename_suffix_with_query_string(): void {
		$input = array(
			'type'      => 'Image',
			'url'       => 'https://cdn.example.test/sunset-800x600.webp?ver=42',
			'mediaType' => 'image/webp',
		);

		$out = apply_filters( 'activitypub_attachment', $input, 0 );

		$this->assertSame( 800, $out['width'] );
		$this->assertSame( 600, $out['height'] );
	}

	/**
	 * When the URL points at the original (no `-WIDTHxHEIGHT` suffix)
	 * and no intermediate size matches, fall back to full-size
	 * metadata. Mirrors the behavior `Image` consumers expect when
	 * bundled AP emits the original file as the URL.
	 */
	public function test_attachment_filter_falls_back_to_full_metadata(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'orig',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => '2026/05/original.jpg',
				'width'  => 4000,
				'height' => 3000,
			)
		);

		$input = array(
			'type'      => 'Image',
			'url'       => 'https://example.test/wp-content/uploads/2026/05/original.jpg',
			'mediaType' => 'image/jpeg',
		);

		$out = apply_filters( 'activitypub_attachment', $input, $attachment_id );

		$this->assertSame( 4000, $out['width'] );
		$this->assertSame( 3000, $out['height'] );
	}

	/**
	 * Metadata with non-positive `width` is malformed — Pixelfed
	 * rejects width-0 attachments in `verifyAttachments`, so omit
	 * the keys rather than emit an invalid pair.
	 */
	public function test_attachment_filter_omits_non_positive_dimensions(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 0,
				'height' => 0,
			)
		);

		$input = array(
			'type'      => 'Image',
			'url'       => 'https://example.test/orig.jpg',
			'mediaType' => 'image/jpeg',
		);

		$out = apply_filters( 'activitypub_attachment', $input, $attachment_id );

		$this->assertArrayNotHasKey( 'width', $out );
		$this->assertArrayNotHasKey( 'height', $out );
	}

	/**
	 * SVGs and other formats without dimensions stored in metadata
	 * land here. Without `width`/`height` keys present, the
	 * attachment passes through untouched.
	 */
	public function test_attachment_filter_skips_when_metadata_lacks_dimensions(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/svg+xml',
			)
		);
		wp_update_attachment_metadata( $attachment_id, array( 'file' => 'logo.svg' ) );

		$input = array(
			'type'      => 'Image',
			'url'       => 'https://example.test/logo.svg',
			'mediaType' => 'image/svg+xml',
		);

		$out = apply_filters( 'activitypub_attachment', $input, $attachment_id );

		$this->assertArrayNotHasKey( 'width', $out );
		$this->assertArrayNotHasKey( 'height', $out );
	}

	/**
	 * A non-array first arg must not crash and must not be coerced
	 * into something a downstream callback could mishandle. Returning
	 * an empty array is intentional: it signals "nothing to attach"
	 * to bundled AP's `array_filter` after the per-attachment filter
	 * runs, so the malformed value is dropped from the final
	 * attachment list.
	 */
	public function test_attachment_filter_handles_non_array_input(): void {
		$out = apply_filters( 'activitypub_attachment', 'not-an-array', 0 );

		$this->assertSame( array(), $out );
	}

	// ---------------------------------------------------------------
	// AP hook: content filter — guard tests + nested gallery.
	// ---------------------------------------------------------------

	/**
	 * Non-WP_Post second arg must pass content through unchanged.
	 * `activitypub_the_content` also fires from
	 * `bundled/activitypub/includes/transformer/class-comment.php` with
	 * a `WP_Comment`, so the guard prevents a stray photo-strip on
	 * comment content.
	 */
	public function test_content_filter_passes_through_on_non_wp_post(): void {
		$content  = '<p>caption text</p>';
		$filtered = apply_filters( 'activitypub_the_content', $content, null );

		$this->assertSame( $content, $filtered );
	}

	/**
	 * Nested gallery markup — `<figure class="wp-block-gallery">`
	 * wrapping multiple `<figure class="wp-block-image">` children —
	 * must strip cleanly without leaving orphan `</figure>` tags.
	 * This was the regression a naive non-greedy `.*?</figure>` regex
	 * would emit; the DOM-based stripper handles it because it
	 * removes the outermost matching figure as a node.
	 */
	public function test_content_filter_strips_nested_gallery_cleanly(): void {
		$post     = $this->make_post( '', '', 'gallery' );
		$content  = '<figure class="wp-block-gallery">'
			. '<figure class="wp-block-image"><img src="https://example.test/a.jpg"/></figure>'
			. '<figure class="wp-block-image"><img src="https://example.test/b.jpg"/></figure>'
			. '</figure>'
			. '<p>Trip recap.</p>';
		$filtered = apply_filters( 'activitypub_the_content', $content, $post );

		$this->assertStringNotContainsString( '<figure', $filtered );
		$this->assertStringNotContainsString( '</figure>', $filtered );
		$this->assertStringNotContainsString( '<img', $filtered );
		$this->assertStringContainsString( 'Trip recap.', $filtered );
	}

	/**
	 * Single-quoted class attributes — uncommon but valid HTML — must
	 * also strip. The DOM walker reads `@class` regardless of which
	 * quote style the input used.
	 */
	public function test_content_filter_handles_single_quoted_class(): void {
		$post     = $this->make_post( '', '', 'image' );
		$content  = "<figure class='wp-block-image'><img src='https://example.test/a.jpg'/></figure><p>Caption.</p>";
		$filtered = apply_filters( 'activitypub_the_content', $content, $post );

		$this->assertStringNotContainsString( '<img', $filtered );
		$this->assertStringContainsString( 'Caption.', $filtered );
	}

	// ---------------------------------------------------------------
	// Detection: classic / freeform content + deleted thumbnail guard.
	// ---------------------------------------------------------------

	/**
	 * Classic-editor / freeform content emits a single null-blockName
	 * block from `parse_blocks()` whose `innerHTML` is the raw body.
	 * If non-empty, the detector must classify the post as "other"
	 * content (not a photo post). Without coverage, the rule-3 path
	 * could silently misclassify classic blog posts with featured
	 * images as photo posts.
	 */
	public function test_classic_freeform_body_does_not_detect(): void {
		$post = $this->make_post( '<p>Plain classic body without a wp:block wrapper.</p>' );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * A featured image whose underlying attachment has been deleted
	 * (postmeta survives) must not classify the post as a photo post —
	 * bundled AP would silently drop the broken attachment, leaving a
	 * Note that promises a photo and delivers nothing. Falling back to
	 * article behavior is the less surprising degradation.
	 */
	public function test_deleted_thumbnail_does_not_detect(): void {
		// $thumbnail_id < 0 in make_post() sets the postmeta to a
		// non-existent attachment id, simulating deletion.
		$post = $this->make_post( '', '', '', -1 );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	// ---------------------------------------------------------------
	// Detection: image block must resolve to local attachment.
	// ---------------------------------------------------------------

	/**
	 * A `core/image` block carrying an external URL has no `id`
	 * attribute. Bundled AP's attachment extraction skips it, so
	 * forcing photo-post treatment would strip the markup from
	 * content AND ship no attachment — caption-only Note with no
	 * image. Treat as not-a-photo so the external URL stays in the
	 * body for receivers to render inline.
	 */
	public function test_external_url_image_block_does_not_detect(): void {
		$external_image = '<!-- wp:image --><figure class="wp-block-image"><img src="https://elsewhere.test/foo.jpg"/></figure><!-- /wp:image -->';
		$post           = $this->make_post( $external_image );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * A WP 5.9+ gallery wraps individual `core/image` blocks in its
	 * `innerBlocks` rather than listing ids on the gallery itself.
	 * The resolver must recurse into innerBlocks so block-nested
	 * galleries still detect.
	 */
	public function test_gallery_with_inner_image_blocks_detects(): void {
		$nested_gallery = '<!-- wp:gallery -->'
			. '<figure class="wp-block-gallery">'
			. '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->'
			. '<!-- wp:image {"id":43} --><figure class="wp-block-image"><img/></figure><!-- /wp:image -->'
			. '</figure>'
			. '<!-- /wp:gallery -->';
		$post           = $this->make_post( $nested_gallery );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * A gallery with neither `ids` attribute nor block-nested images
	 * has no federatable content. Same degradation as the external-URL
	 * image case: classify as not-a-photo.
	 */
	public function test_empty_gallery_does_not_detect(): void {
		$empty_gallery = '<!-- wp:gallery --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->';
		$post          = $this->make_post( $empty_gallery );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	// ---------------------------------------------------------------
	// Detection: post-featured-image block requires a real thumbnail.
	// ---------------------------------------------------------------

	/**
	 * `core/post-featured-image` block with a real Featured Image set
	 * federates the same way as Rule 3's empty-body case — count it
	 * as a photo post when there's a thumbnail to actually emit.
	 */
	public function test_featured_image_block_with_thumbnail_detects(): void {
		$featured = '<!-- wp:post-featured-image /-->';
		$post     = $this->make_post( $featured, '', '', 1 );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Same block plus a caption paragraph also detects. Matches the
	 * "headline + photo + caption" shape the discriminator targets.
	 */
	public function test_featured_image_block_plus_paragraph_with_thumbnail_detects(): void {
		$featured  = '<!-- wp:post-featured-image /-->';
		$paragraph = '<!-- wp:paragraph --><p>caption</p><!-- /wp:paragraph -->';
		$post      = $this->make_post( $featured . $paragraph, '', '', 1 );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * The block in the body without a real Featured Image set on the
	 * post resolves to nothing federation-side — classify as not a
	 * photo post so detection falls through to article behavior.
	 */
	public function test_featured_image_block_without_thumbnail_does_not_detect(): void {
		$featured = '<!-- wp:post-featured-image /-->';
		$post     = $this->make_post( $featured );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	// ---------------------------------------------------------------
	// AP hook: dimensions full-size fallback only fires on real path.
	// ---------------------------------------------------------------

	/**
	 * Pass 2 (registered intermediate sizes) was the dimension-
	 * resolution path the round-1 fix added but no test exercised.
	 * Custom-named derivatives (sizes registered without the standard
	 * `-WxH` naming convention) only resolve through `sizes[]` lookup.
	 * Set up metadata with a custom-named size, pass a URL ending in
	 * that filename, and assert the matching dimensions come back.
	 */
	public function test_attachment_filter_uses_metadata_sizes_match(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'  => '2026/05/photo.jpg',
				'sizes' => array(
					'hero' => array(
						'file'   => 'photo-hero.jpg',
						'width'  => 1200,
						'height' => 800,
					),
				),
			)
		);

		$input = array(
			'type'      => 'Image',
			'url'       => 'https://example.test/wp-content/uploads/2026/05/photo-hero.jpg',
			'mediaType' => 'image/jpeg',
		);

		$out = apply_filters( 'activitypub_attachment', $input, $attachment_id );

		$this->assertSame( 1200, $out['width'] );
		$this->assertSame( 800, $out['height'] );
	}

	/**
	 * The full-size fallback's `str_ends_with` check anchors on a
	 * leading `/` so a bare-basename `meta['file']` (sites that
	 * disable year/month upload subdirs in Settings → Media) doesn't
	 * collide with URLs that end in the basename as a suffix.
	 * Without the anchor, `/wp-content/uploads/some-photo.jpg` ends
	 * with `photo.jpg` and would inherit the wrong attachment's
	 * dimensions.
	 */
	public function test_attachment_filter_skips_full_size_on_basename_collision(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => 'photo.jpg',
				'width'  => 4000,
				'height' => 3000,
			)
		);

		$input = array(
			'type'      => 'Image',
			'url'       => 'https://example.test/wp-content/uploads/some-photo.jpg',
			'mediaType' => 'image/jpeg',
		);

		$out = apply_filters( 'activitypub_attachment', $input, $attachment_id );

		$this->assertArrayNotHasKey( 'width', $out );
		$this->assertArrayNotHasKey( 'height', $out );
	}

	/**
	 * HEIC, HEIF, and TIFF derivatives carry the same `-WIDTHxHEIGHT`
	 * filename suffix as JPEG / PNG / WebP / AVIF. Mastodon now
	 * accepts HEIC server-side, and Pixelfed enforces dimensions for
	 * all image types — the suffix regex must cover the modern format
	 * set or HEIC posts will ship without dimensions and (where the
	 * receiver enforces them) get rejected.
	 *
	 * @param string $url      Image URL with a HEIC/HEIF/TIFF derivative suffix.
	 * @param int    $expected_width Expected width parsed from the suffix.
	 * @param int    $expected_height Expected height parsed from the suffix.
	 *
	 * @dataProvider modern_image_format_cases
	 */
	#[DataProvider( 'modern_image_format_cases' )]
	public function test_attachment_filter_extracts_modern_format_dimensions(
		string $url,
		int $expected_width,
		int $expected_height
	): void {
		$input = array(
			'type'      => 'Image',
			'url'       => $url,
			'mediaType' => 'image/jpeg',
		);

		$out = apply_filters( 'activitypub_attachment', $input, 0 );

		$this->assertSame( $expected_width, $out['width'] );
		$this->assertSame( $expected_height, $out['height'] );
	}

	/**
	 * Data provider for `test_attachment_filter_extracts_modern_format_dimensions`.
	 *
	 * @return array<string, array{0:string, 1:int, 2:int}>
	 */
	public static function modern_image_format_cases(): array {
		return array(
			'heic' => array( 'https://example.test/photo-1024x683.heic', 1024, 683 ),
			'heif' => array( 'https://example.test/photo-800x600.heif', 800, 600 ),
			'tif'  => array( 'https://example.test/photo-2000x1500.tif', 2000, 1500 ),
			'tiff' => array( 'https://example.test/photo-4096x4096.tiff', 4096, 4096 ),
		);
	}

	/**
	 * The full-size fallback is now gated on the URL ending with
	 * `meta['file']`. A CDN URL that preserves the basename but
	 * doesn't include the upload subpath must NOT inherit the
	 * recorded original dimensions — Pixelfed would then enforce the
	 * mismatch and reject the post. Verify by setting up metadata for
	 * a "/2026/05/original.jpg" file but passing a URL that ends in
	 * just "/original.jpg".
	 */
	public function test_attachment_filter_skips_full_size_when_path_mismatches(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => '2026/05/original.jpg',
				'width'  => 4000,
				'height' => 3000,
			)
		);

		$input = array(
			'type'      => 'Image',
			'url'       => 'https://cdn.example.test/cached/original.jpg',
			'mediaType' => 'image/jpeg',
		);

		$out = apply_filters( 'activitypub_attachment', $input, $attachment_id );

		$this->assertArrayNotHasKey( 'width', $out );
		$this->assertArrayNotHasKey( 'height', $out );
	}

	// ---------------------------------------------------------------
	// Detection: wp_unslash regression + gallery innerBlocks edge case.
	// ---------------------------------------------------------------

	/**
	 * `wp_filter_post_kses` slashes block-attribute JSON in
	 * post_content for non-admin authors (Contributors, multisite
	 * Authors lacking `unfiltered_html`). `parse_blocks()` then
	 * returns `null` attrs for the slashed JSON, so both FOSSE's
	 * detector and bundled AP's `get_block_attachments()` extractor
	 * fail to read `attrs.id` — and that's the contract. The
	 * detector deliberately uses the same raw `post_content` view
	 * AP's extractor uses: when AP can't surface an image attachment,
	 * the post must NOT be classified as a photo post, otherwise the
	 * federated Note would be caption-only with no image. Slashed
	 * content falls through to article shape; the image markup stays
	 * in the body for receivers to render inline.
	 */
	public function test_slashed_block_attributes_do_not_detect(): void {
		$slashed_body = \addslashes( '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="https://example.test/a.jpg"/></figure><!-- /wp:image -->' );
		$post         = $this->make_post( $slashed_body );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Legacy WP <5.9 galleries store image IDs in `attrs.ids` rather
	 * than nesting `core/image` blocks. Bundled AP's extractor
	 * doesn't read this path for `core/gallery` (only for
	 * jetpack/slideshow + jetpack/tiled-gallery), so classifying the
	 * post as a photo here would force `Note` while AP emits no
	 * attachment — caption-only Note with no image. Detection falls
	 * through to article behavior, keeping the gallery markup intact
	 * in the body where receivers can still render the images.
	 */
	public function test_legacy_gallery_ids_attribute_does_not_detect(): void {
		$legacy_gallery = '<!-- wp:gallery {"ids":[42,43]} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->';
		$post           = $this->make_post( $legacy_gallery );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * A WP 5.9+ gallery whose `innerBlocks` are all external-URL
	 * image blocks (no `id` attribute) has no federatable content —
	 * `block_resolves_locally` recurses through innerBlocks but every
	 * sub-block fails the `id > 0` check. Result: gallery is treated
	 * as "other" content, detection falls through to article
	 * behavior, and the external URLs stay in the body.
	 */
	public function test_gallery_with_only_external_inner_image_blocks_does_not_detect(): void {
		$external_gallery = '<!-- wp:gallery -->'
			. '<figure class="wp-block-gallery">'
			. '<!-- wp:image --><figure class="wp-block-image"><img src="https://elsewhere.test/a.jpg"/></figure><!-- /wp:image -->'
			. '<!-- wp:image --><figure class="wp-block-image"><img src="https://elsewhere.test/b.jpg"/></figure><!-- /wp:image -->'
			. '</figure>'
			. '<!-- /wp:gallery -->';
		$post             = $this->make_post( $external_gallery );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}
}
