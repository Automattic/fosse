<?php
/**
 * Length-based short-form discriminator for Bluesky.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Flips long-form posts onto Atmosphere's short-form path when the
 * rendered post body already fits inside a single Bluesky record.
 *
 * Atmosphere's built-in `is_short_form()` discriminator keys off post
 * shape (no title support, empty title, or any non-empty `post_format`),
 * not length. A titleless standard post with no format therefore always
 * takes the long-form path, which by default builds an
 * `app.bsky.embed.external` link card — even when the body already fits
 * comfortably inside 300 chars. The result on microblog-length posts is
 * a redundant link-card preview attached to a post whose URL is already
 * in the visible text.
 *
 * This bridge adds a length-based discriminator on top of upstream's
 * shape-based one. When the rendered post content fits in 300 chars
 * AND the post has no title, we force `atmosphere_is_short_form_post`
 * to true so Atmosphere takes its existing short-form path: the post
 * body becomes the Bluesky text (no title prefix, no permalink, no
 * card). Sibling to `Object_Type`, which uses the same filter to
 * project ActivityPub's "note" object type.
 *
 * Title-bearing posts are intentionally excluded: a non-empty title is
 * Atmosphere's own long-form signal (`build_text()` composes title +
 * excerpt + permalink + link-card embed for them), and dropping the
 * card on a titled-short post would silently strip the title from the
 * Bluesky surface even though the author meant to publish it.
 *
 * Opt-out hook: `fosse_bsky_link_card_when_post_fits` (default `false`).
 * Returning `true` from the filter keeps today's long-form-with-card
 * behavior for the post being evaluated.
 */
class Bsky_Short_Form_Fit {

	/**
	 * Maximum character count for a single Bluesky post record.
	 *
	 * Mirrors Atmosphere's `truncate_text()` default and the 300 char
	 * cap enforced by `build_short_form_text()` /
	 * `build_text()` in `transformer/class-post.php`. Lives here as a
	 * constant so the boundary the bridge checks against is the same
	 * one Atmosphere will measure later in the same publish pass.
	 *
	 * @var int
	 */
	public const BSKY_RECORD_LIMIT = 300;

	/**
	 * Per-request memo of `filter_atmosphere()` results.
	 *
	 * The `atmosphere_is_short_form_post` filter fires multiple times per
	 * publish — at minimum once inside Atmosphere's transformer, again
	 * inside the publisher's strategy selection, and a third time when
	 * FOSSE's metrics subscriber resolves `strategy` for the
	 * `fosse_publish_result` event. Each call would otherwise re-run
	 * `apply_filters( 'the_content', ... )`, which expands shortcodes /
	 * oEmbeds and can be expensive on rich posts. Cache the decision per
	 * post id so the work happens once.
	 *
	 * @var array<int, bool>
	 */
	private static array $decision_cache = array();

	/**
	 * Register the Atmosphere short-form bridge filter. Safe to call more
	 * than once per request — WordPress dedupes identical
	 * callable-as-array registrations.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_filter( 'atmosphere_is_short_form_post', array( self::class, 'filter_atmosphere' ), 10, 2 );
	}

	/**
	 * Force short-form when the rendered body fits inside one Bluesky record.
	 *
	 * Pass-through cases (keep upstream's decision):
	 *   - `$is_short` is already `true` — Atmosphere or a sibling bridge
	 *     (e.g. `Object_Type`) already decided this post is short-form.
	 *     Returning anything other than `$is_short` here would risk
	 *     trampling another callback in the chain.
	 *   - `$post` isn't a `WP_Post` — defensive guard for filter contract
	 *     drift; upstream always passes a post in normal contexts.
	 *   - `$post->post_title` is non-empty — Atmosphere treats title-
	 *     presence as a long-form signal, and the long-form composition
	 *     surfaces the title inside the link card. Flipping a titled
	 *     post to short-form silently drops the title, which the author
	 *     clearly intended to publish.
	 *   - Body renders to empty text — forcing short-form would publish a
	 *     zero-length Bluesky post. Defer to upstream long-form, which at
	 *     least carries the title + permalink.
	 *   - Body length exceeds `BSKY_RECORD_LIMIT` — doesn't fit, so the
	 *     short-form fallback wouldn't avoid truncation; the long-form
	 *     teaser strategies exist precisely for this case.
	 *   - `fosse_bsky_link_card_when_post_fits` returns truthy — the site
	 *     (or a per-post hook) explicitly wants the long-form behavior
	 *     preserved.
	 *
	 * The "rendered" length is measured through the same normalization
	 * pipeline Atmosphere applies before publishing (`the_content` filter
	 * chain, then `\Atmosphere\sanitize_text` — strip tags + decode HTML
	 * entities + collapse Unicode whitespace + trim). Without this
	 * alignment, posts that hover near the 300-char boundary can be
	 * misclassified: a body containing `&amp;` counts as 5 chars to a
	 * naive measurement and 1 to the sanitized form Atmosphere will
	 * eventually compare against. Falls back to `wp_strip_all_tags`
	 * + `trim` when Atmosphere isn't loaded (defense in depth).
	 *
	 * Memoized per request via `$decision_cache` — see that property's
	 * docblock for why it matters.
	 *
	 * @param bool  $is_short Upstream-computed short-form default.
	 * @param mixed $post     The post being transformed.
	 * @return bool True when we force short-form, otherwise pass-through.
	 */
	public static function filter_atmosphere( bool $is_short, $post ): bool {
		if ( $is_short ) {
			return $is_short;
		}

		if ( ! $post instanceof \WP_Post ) {
			return $is_short;
		}

		// Title-presence is Atmosphere's long-form signal — the long-form
		// path composes the title into the link card. Flipping a titled
		// post to short-form would silently drop the title from Bluesky.
		if ( '' !== \trim( (string) $post->post_title ) ) {
			return $is_short;
		}

		if ( isset( self::$decision_cache[ $post->ID ] ) ) {
			return self::$decision_cache[ $post->ID ];
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
		$rendered = (string) \apply_filters( 'the_content', $post->post_content );
		$plain    = \function_exists( '\Atmosphere\sanitize_text' )
			? \Atmosphere\sanitize_text( $rendered )
			: \trim( \wp_strip_all_tags( $rendered ) );
		$length   = \mb_strlen( $plain );

		if ( 0 === $length || $length > self::BSKY_RECORD_LIMIT ) {
			self::$decision_cache[ $post->ID ] = $is_short;
			return $is_short;
		}

		/**
		 * Filters whether to keep Atmosphere's long-form-with-link-card
		 * behavior for a post whose body already fits inside a single
		 * Bluesky record.
		 *
		 * Default `false` — FOSSE drops the card and publishes the body
		 * as a native short-form Bluesky post. Return `true` to opt back
		 * into today's long-form path (title + excerpt + permalink +
		 * link-card embed) for the given post.
		 *
		 * Fires only after the length and content guards above pass, so
		 * callbacks can assume the post has non-empty, fits-in-300 body
		 * text. Callbacks receive the `WP_Post` so they can branch per
		 * post type, author, taxonomy, meta, etc.
		 *
		 * @param bool     $keep_card Whether to keep the link card. Default false.
		 * @param \WP_Post $post      The post being evaluated.
		 */
		if ( \apply_filters( 'fosse_bsky_link_card_when_post_fits', false, $post ) ) {
			self::$decision_cache[ $post->ID ] = $is_short;
			return $is_short;
		}

		self::$decision_cache[ $post->ID ] = true;
		return true;
	}

	/**
	 * Clear the per-request decision cache.
	 *
	 * Test-only entry point — production never needs to invalidate
	 * mid-request because the cache key is the post id, and the
	 * `post_content` / `post_title` of an in-flight post is stable
	 * inside a single PHP process. Public so test setUp can call it.
	 *
	 * @return void
	 */
	public static function reset_cache_for_testing(): void {
		self::$decision_cache = array();
	}
}
