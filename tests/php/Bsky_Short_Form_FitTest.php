<?php
/**
 * Tests for the length-based Bluesky short-form bridge.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Bsky_Short_Form_Fit;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;
use WP_Post;

/**
 * Verifies the bridge promotes a long-form post to Atmosphere short-form
 * when the rendered body fits in a single Bluesky record (300 chars),
 * and that the `fosse_bsky_link_card_when_post_fits` filter is honored
 * as the per-post / per-site opt-out.
 */
class Bsky_Short_Form_FitTest extends BaseTestCase {

	/**
	 * Callbacks this test file added to `the_content`, paired with the
	 * priority they were registered at. Tracked so the after-hook can
	 * remove each one precisely.
	 *
	 * `remove_all_filters( 'the_content' )` is unsafe here because it
	 * also wipes WordPress core's default chain (wpautop, do_shortcode,
	 * etc.) for the remainder of the process, which leaks into sibling
	 * test classes that rely on the default chain.
	 *
	 * @var array<int, array{0:callable, 1:int}>
	 */
	private array $added_the_content_filters = array();

	/**
	 * Clean hook state before each test and register the bridge.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		remove_all_filters( 'atmosphere_is_short_form_post' );
		remove_all_filters( 'fosse_bsky_link_card_when_post_fits' );

		// Memoization cache is per-process; reset it so each test starts
		// fresh and doesn't see a cached decision from a sibling case.
		Bsky_Short_Form_Fit::reset_cache_for_testing();

		Bsky_Short_Form_Fit::register();
	}

	/**
	 * Remove every `the_content` filter this test file added, by exact
	 * callback identity + priority. Leaves WordPress core's default
	 * chain intact.
	 *
	 * @after
	 */
	#[After]
	public function remove_added_the_content_filters(): void {
		foreach ( $this->added_the_content_filters as [ $cb, $priority ] ) {
			remove_filter( 'the_content', $cb, $priority );
		}
		$this->added_the_content_filters = array();
	}

	/**
	 * Register a `the_content` callback and stash it so the after-hook
	 * can remove only what this test file added.
	 *
	 * @param callable $cb       Filter callback.
	 * @param int      $priority Filter priority. Default 10.
	 * @return void
	 */
	private function add_the_content_filter( callable $cb, int $priority = 10 ): void {
		add_filter( 'the_content', $cb, $priority );
		$this->added_the_content_filters[] = array( $cb, $priority );
	}

	/**
	 * Build a stub WP_Post with the given content. `wp_insert_post` would
	 * also work but the bridge only touches `post_content` / `post_title`
	 * and the filter's `WP_Post` type guard — a plain stub keeps tests
	 * fast and isolates the unit under test from WP's post storage
	 * machinery.
	 *
	 * @param string $content Raw post content.
	 * @param string $title   Optional post title; default empty (the
	 *                        common short-note shape this bridge targets).
	 * @return WP_Post
	 */
	private function make_post( string $content, string $title = '' ): WP_Post {
		return new WP_Post(
			(object) array(
				'ID'           => 1,
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	/**
	 * When upstream already decided the post is short-form (post_format
	 * set, empty title, etc.), the bridge must not re-evaluate — another
	 * callback in the chain may have set $is_short=true for reasons that
	 * length doesn't capture. Returning anything other than the input
	 * here would risk silently flipping the decision back to long-form
	 * for posts longer than 300 chars.
	 */
	public function test_passes_through_when_already_short_form(): void {
		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', true, $this->make_post( str_repeat( 'a', 5000 ) ) ),
			'A true input must stay true even when the body is way over the Bluesky limit.'
		);
	}

	/**
	 * A prior subscriber returning a non-bool (e.g. null) must not fatal.
	 * The callback's first parameter is loosely typed and cast to bool
	 * internally, matching WP filter convention — a scalar type hint would
	 * raise a TypeError even in coercive mode when fed null. A null
	 * upstream value coerces to false (not already-short), so a fitting
	 * body still gets forced to short-form.
	 */
	public function test_survives_null_upstream_value(): void {
		add_filter( 'atmosphere_is_short_form_post', fn() => null, 5 );

		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( 'Hello, Bluesky.' ) ),
			'A null upstream value must coerce to false and not fatal.'
		);
	}

	/**
	 * Defensive guard: if the upstream filter contract ever drifts and
	 * passes a non-`WP_Post`, the bridge must not crash on
	 * `$post->post_content`. It should return the input unchanged.
	 */
	public function test_passes_through_on_non_wp_post(): void {
		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, null )
		);
		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, 42 )
		);
		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, 'not-a-post' )
		);
	}

	/**
	 * Empty post bodies aren't candidates for short-form: forcing
	 * Atmosphere onto the short-form path would publish a zero-length
	 * Bluesky post. The long-form path at least carries title + permalink.
	 */
	public function test_passes_through_on_empty_body(): void {
		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( '' ) )
		);
		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( "   \n   \t  " ) ),
			'Whitespace-only content must count as empty after trim().'
		);
	}

	/**
	 * Core case: a microblog-length post body (well under 300 chars)
	 * flips the filter to true so Atmosphere takes the short-form path.
	 */
	public function test_forces_short_form_when_body_fits(): void {
		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( 'Hello, Bluesky.' ) )
		);
	}

	/**
	 * Boundary: a body that is *exactly* 300 chars should still force
	 * short-form, because Atmosphere's `build_short_form_text()` will
	 * publish it verbatim without truncation. The bridge's check uses
	 * `<=` precisely so the boundary post is the microblog case, not the
	 * teaser-needs-truncation case.
	 */
	public function test_forces_short_form_at_exactly_300_chars(): void {
		$body = str_repeat( 'x', 300 );
		$this->assertSame( 300, mb_strlen( $body ) );

		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( $body ) )
		);
	}

	/**
	 * Boundary: 301 chars exceeds the limit, so the bridge must defer
	 * to upstream's long-form decision (the teaser-thread/truncate-link
	 * strategies exist precisely for the doesn't-fit case).
	 */
	public function test_passes_through_at_301_chars(): void {
		$body = str_repeat( 'x', 301 );

		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( $body ) )
		);
	}

	/**
	 * The length measurement uses the post-`the_content` rendered text,
	 * not the raw `post_content`. A raw body that's short but expands to
	 * something long (e.g. via a shortcode) must not be misclassified as
	 * microblog-length — Atmosphere will compose against the expanded
	 * version too, and we'd otherwise force short-form onto a body that
	 * Atmosphere then truncates.
	 */
	public function test_uses_the_content_filter_for_length(): void {
		$this->add_the_content_filter(
			static function ( $content ) {
				return str_replace( '[expand]', str_repeat( 'y', 400 ), $content );
			}
		);

		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( 'Intro [expand] outro.' ) ),
			'A 21-char raw body that expands to 400+ chars must not be classified as fits-in-300.'
		);
	}

	/**
	 * HTML tags don't count toward the 300-char Bluesky budget — they're
	 * stripped before publishing. The bridge mirrors that by stripping
	 * tags before measurement, so a post wrapped in markup that fits in
	 * 300 chars of visible text still flips to short-form.
	 */
	public function test_strips_html_before_measuring(): void {
		$body = '<p><strong>Short post.</strong></p>';

		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( $body ) ),
			'HTML tags surrounding short visible text must not push the post over the limit.'
		);
	}

	/**
	 * The opt-out filter must be honored when truthy: sites that want
	 * the long-form-with-card behavior preserved for a given post
	 * (per type / per author / per meta) can return true and the bridge
	 * yields. The default (no callbacks) keeps the new short-form path
	 * active — covered by every other test in this file.
	 */
	public function test_override_filter_keeps_long_form_when_truthy(): void {
		add_filter( 'fosse_bsky_link_card_when_post_fits', '__return_true' );

		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( 'Short body.' ) ),
			'A truthy override must keep Atmosphere on the long-form (link-card) path.'
		);
	}

	/**
	 * The override filter receives the `WP_Post` so callbacks can branch
	 * per post (post type, author, taxonomy, meta, …). Without this,
	 * site-wide is the only granularity, which defeats the purpose of an
	 * override at all.
	 */
	public function test_override_filter_receives_post(): void {
		$received = null;
		add_filter(
			'fosse_bsky_link_card_when_post_fits',
			static function ( $keep, $post ) use ( &$received ) {
				$received = $post;
				return $keep;
			},
			10,
			2
		);

		$post = $this->make_post( 'Short body.' );
		apply_filters( 'atmosphere_is_short_form_post', false, $post );

		$this->assertSame( $post, $received, 'Override filter must receive the WP_Post being evaluated.' );
	}

	/**
	 * A non-empty `post_title` is Atmosphere's long-form signal — the
	 * link-card composition surfaces the title inside the embed. The
	 * bridge therefore must NOT flip a titled-short post to short-form,
	 * or the title silently disappears from Bluesky even though the
	 * author wrote it. Locks the conflict pinned by the
	 * `long-form-link-card.spec.ts` e2e test.
	 */
	public function test_passes_through_when_post_has_title(): void {
		$post = $this->make_post( 'Body that easily fits in 300 chars.', 'A short article' );

		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $post ),
			'A titled post must stay on the long-form path even when the body fits — the title would be lost on the short-form path.'
		);
	}

	/**
	 * Whitespace-only titles count as no title. A post that has a stray
	 * spaces-only `post_title` (e.g. left over from the editor) should
	 * still take the short-form path — there's no title to drop.
	 */
	public function test_treats_whitespace_only_title_as_empty(): void {
		$post = $this->make_post( 'Body fits in 300 chars.', "  \n\t  " );

		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', false, $post )
		);
	}

	/**
	 * The decision is memoized per post id so the filter doesn't re-run
	 * `apply_filters( 'the_content', ... )` every time
	 * `atmosphere_is_short_form_post` fires (which can be several times
	 * per publish — Atmosphere's transformer + publisher + FOSSE's
	 * metrics resolver all evaluate it).
	 */
	public function test_memoizes_per_post_id(): void {
		$count = 0;
		$this->add_the_content_filter(
			static function ( $content ) use ( &$count ) {
				++$count;
				return $content;
			}
		);

		$post = $this->make_post( 'Hello, Bluesky.' );
		apply_filters( 'atmosphere_is_short_form_post', false, $post );
		apply_filters( 'atmosphere_is_short_form_post', false, $post );
		apply_filters( 'atmosphere_is_short_form_post', false, $post );

		$this->assertSame(
			1,
			$count,
			'the_content must be applied exactly once per post id across repeated atmosphere_is_short_form_post evaluations.'
		);
	}

	/**
	 * Falsy override returns from the filter (the default, `false`, or
	 * `null`/`0`) must not block the short-form promotion. Anything
	 * other than a truthy "keep the card" answer should fall through to
	 * the new behavior.
	 */
	public function test_override_filter_falsy_returns_do_not_block(): void {
		add_filter(
			'fosse_bsky_link_card_when_post_fits',
			static function () {
				return 0;
			}
		);

		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->make_post( 'Short body.' ) )
		);
	}
}
