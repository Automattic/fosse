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

		// `register_post_format_support()` normally runs on
		// `after_setup_theme`, which has already fired by the time
		// PHPUnit gets here — call it directly so format-aware
		// detection sees `get_post_format()` consulting the term.
		Photo_Post::register_post_format_support();

		// Empty bodies are valid photo posts (Featured Image + no
		// caption, or "image format with zero text"). Override WP's
		// default empty-content rejection so `wp_insert_post` returns
		// an id for those cases too.
		add_filter( 'wp_insert_post_empty_content', '__return_false' );
	}

	/**
	 * Build a real WP post with optional title and post format.
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
	 * Each call generates a fresh post id so the per-post memoization
	 * cache doesn't leak between cases.
	 *
	 * @param string $content     Raw post content.
	 * @param string $title       Optional post title.
	 * @param string $post_format Optional post format slug (image, gallery, status, …).
	 * @return WP_Post
	 */
	private function make_post( string $content, string $title = '', string $post_format = '' ): WP_Post {
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

		return get_post( $post_id );
	}

	/**
	 * Insert a real post and (optionally) attach a thumbnail meta. Used
	 * for featured-image-aware detection cases where `has_post_thumbnail`
	 * needs to consult postmeta. We do not need an actual attachment
	 * post — `has_post_thumbnail` only checks for a non-zero
	 * `_thumbnail_id` meta value.
	 *
	 * @param string $content       Post content.
	 * @param bool   $with_thumbnail Whether to set `_thumbnail_id`.
	 * @return WP_Post
	 */
	private function insert_post_with_thumbnail( string $content, bool $with_thumbnail = true ): WP_Post {
		$post_id = wp_insert_post(
			array(
				'post_title'   => '',
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);

		if ( $with_thumbnail ) {
			update_post_meta( $post_id, '_thumbnail_id', 999 );
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
		$image     = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="https://example.test/a.jpg" alt=""/></figure><!-- /wp:image -->';
		$gallery   = '<!-- wp:gallery --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->';
		$featured  = '<!-- wp:post-featured-image /-->';
		$paragraph = '<!-- wp:paragraph --><p>caption text</p><!-- /wp:paragraph -->';
		$heading   = '<!-- wp:heading --><h2>headline</h2><!-- /wp:heading -->';
		$spacer    = '<!-- wp:spacer --><div class="wp-block-spacer"></div><!-- /wp:spacer -->';
		$separator = '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->';
		$empty_p   = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';

		return array(
			'image only'                     => array( $image, true ),
			'image plus single paragraph'    => array( $image . $paragraph, true ),
			'image plus two paragraphs'      => array( $image . $paragraph . $paragraph, false ),
			'paragraph only'                 => array( $paragraph, false ),
			'gallery only'                   => array( $gallery, true ),
			'gallery plus paragraph'         => array( $gallery . $paragraph, true ),
			'featured image block only'      => array( $featured, true ),
			'featured image block plus para' => array( $featured . $paragraph, true ),
			'image plus heading'             => array( $image . $heading, false ),
			'two images'                     => array( $image . $image, false ),
			'image plus empty paragraph'     => array( $image . $empty_p, true ),
			'image plus spacer plus para'    => array( $image . $spacer . $paragraph, true ),
			'image plus separator'           => array( $image . $separator, true ),
			'empty content'                  => array( '', false ),
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
		$image = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/a.jpg"/></figure><!-- /wp:image -->';
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
		$post = $this->insert_post_with_thumbnail( '' );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Featured Image set + body = single caption paragraph should also
	 * detect — classic-editor flow where the body is the caption.
	 */
	public function test_featured_thumbnail_with_single_paragraph_detects(): void {
		$paragraph = '<!-- wp:paragraph --><p>Caption.</p><!-- /wp:paragraph -->';
		$post      = $this->insert_post_with_thumbnail( $paragraph );

		$this->assertTrue( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Featured Image + 2 paragraphs is an article that happens to have
	 * a hero image. Don't classify as a photo post — that's a
	 * blog-post-with-image, not a photo-with-caption.
	 */
	public function test_featured_thumbnail_with_two_paragraphs_does_not_detect(): void {
		$paragraph = '<!-- wp:paragraph --><p>A line.</p><!-- /wp:paragraph -->';
		$post      = $this->insert_post_with_thumbnail( $paragraph . $paragraph );

		$this->assertFalse( Photo_Post::is_photo_post( $post ) );
	}

	/**
	 * Same body shape without a featured image must NOT detect. Guards
	 * against the rule-3 path firing on plain text posts just because
	 * they happen to fit "≤1 paragraph."
	 */
	public function test_single_paragraph_without_thumbnail_does_not_detect(): void {
		$paragraph = '<!-- wp:paragraph --><p>Caption.</p><!-- /wp:paragraph -->';
		$post      = $this->insert_post_with_thumbnail( $paragraph, false );

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
}
