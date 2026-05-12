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
 * not length. A standard post with a title and no format therefore always
 * takes the long-form path, which by default builds an
 * `app.bsky.embed.external` link card — even when the title, excerpt, and
 * permalink already fit comfortably inside 300 chars. The result on
 * microblog-length posts is a redundant link-card preview attached to a
 * post whose URL is already in the visible text.
 *
 * This bridge adds a length-based discriminator on top of upstream's
 * shape-based one. When the rendered post content fits in 300 chars, we
 * force `atmosphere_is_short_form_post` to true so Atmosphere takes its
 * existing short-form path: the post body becomes the Bluesky text (no
 * title prefix, no permalink, no card). Sibling to `Object_Type`, which
 * uses the same filter to project ActivityPub's "note" object type.
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
	 * The "rendered" length uses the same pipeline Atmosphere's
	 * `render_post_content_plain()` uses (`the_content` filter chain plus
	 * tag stripping). That means shortcodes and oEmbeds are expanded
	 * before measurement, so a post whose raw `post_content` is short but
	 * whose expansion is long doesn't get misclassified. The cost is
	 * running `the_content` once per evaluation; Atmosphere already runs
	 * it during composition, so the overhead is one extra pass per
	 * publish for posts that take this code path.
	 *
	 * `$post`'s type is loosened from `WP_Post` to `mixed` for the same
	 * reason as in `Object_Type::filter_atmosphere()` — defense in depth
	 * if the upstream filter contract ever ships an unexpected value.
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

		$plain  = \wp_strip_all_tags( \apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
		$length = \mb_strlen( \trim( $plain ) );

		if ( 0 === $length || $length > self::BSKY_RECORD_LIMIT ) {
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
			return $is_short;
		}

		return true;
	}
}
