<?php
/**
 * Cross-network long-form strategy projector for FOSSE.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Translates the single `fosse_long_form_strategy` option into the
 * Atmosphere `atmosphere_long_form_composition` filter answer. A
 * FOSSE site picks one long-form strategy; this projector makes that
 * choice apply whether the caller is Publisher's internal default or
 * any downstream code querying the filter.
 *
 * Unlike `Object_Type` (which passes through when the option is
 * unset), this projector **coerces** unset / empty / unrecognized
 * option values to a FOSSE-opinionated default (`'teaser-thread'`).
 * Upstream Atmosphere's own default stays `'link-card'` — sites
 * running Atmosphere standalone keep today's behavior; installing
 * FOSSE opts into the thread strategy without further configuration.
 *
 * Current option values:
 * - `teaser-thread` (default) — 2-post thread: hook + CTA-with-link.
 * - `truncate-link`           — single post: body text + inline permalink, no embed card.
 * - `link-card`               — single post: today's title + excerpt + permalink + external card.
 * - `document-card`           — v2; single post with `app.bsky.embed.record` pointing at the
 *                               site.standard.document. Passed through to Atmosphere, which
 *                               falls back to `'link-card'` on unknown strategies until the
 *                               v2 renderer lands.
 * - anything else / unset     — coerces to `'teaser-thread'`.
 */
class Long_Form_Strategy {

	/**
	 * Site option name holding the projected strategy.
	 *
	 * @var string
	 */
	private const OPTION = 'fosse_long_form_strategy';

	/**
	 * FOSSE's opinionated default when the option is unset or unrecognized.
	 *
	 * @var string
	 */
	private const DEFAULT_STRATEGY = 'teaser-thread';

	/**
	 * Strategy values the projector returns as-is.
	 *
	 * Deliberately permissive about `'document-card'`: the upstream
	 * Atmosphere filter falls back to `'link-card'` for unknown values
	 * on its side, so passing `'document-card'` through today is
	 * forward-compatible with the v2 renderer without requiring an
	 * upstream change when it lands.
	 *
	 * @var string[]
	 */
	private const KNOWN_STRATEGIES = array(
		'teaser-thread',
		'truncate-link',
		'link-card',
		'document-card',
	);

	/**
	 * Register the Atmosphere long-form composition filter. Safe to call
	 * more than once per request — WordPress dedupes identical
	 * callable-as-array registrations.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_filter( 'atmosphere_long_form_composition', array( self::class, 'filter' ), 10, 2 );
	}

	/**
	 * Project the option onto Atmosphere's long-form composition filter.
	 *
	 * The upstream-computed default is intentionally discarded — FOSSE
	 * is opinionated about the site-wide strategy. When the option is
	 * unset, empty, or not one of `KNOWN_STRATEGIES`, the projector
	 * coerces to `self::DEFAULT_STRATEGY`.
	 *
	 * $post type is loose on purpose — upstream callers always pass a
	 * WP_Post in normal filter contexts, but loosening the hint keeps
	 * the projector defensive if the upstream filter contract ever
	 * drifts.
	 *
	 * @param string $strategy Upstream-computed default strategy (ignored).
	 * @param mixed  $post     The post being transformed (unused).
	 * @return string The FOSSE-projected strategy, always one of KNOWN_STRATEGIES.
	 */
	public static function filter( string $strategy, $post ): string {
		unset( $strategy, $post );

		$stored = \get_option( self::OPTION );

		if ( \is_string( $stored ) && \in_array( $stored, self::KNOWN_STRATEGIES, true ) ) {
			return $stored;
		}

		return self::DEFAULT_STRATEGY;
	}
}
