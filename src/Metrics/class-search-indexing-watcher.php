<?php
/**
 * Watches `blog_public` for the FOSSE federation-gate flip.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Metrics;

/**
 * Emits `fosse_search_indexing_disabled_post_active` when a site flips
 * `blog_public` from `1` to `0` while FOSSE is active. The strategy spec
 * (`sdd/fosse-metrics-strategy/spec.md` § Reviewer Concern 3) calls this
 * out as the negative-signal anti-pattern: a user "escaping FOSSE via
 * the search-indexing gate" instead of using a deactivate UI we don't
 * yet provide.
 *
 * Hooked at `update_option_blog_public`. Debounced via a short
 * site-scoped transient so option-write storms during admin saves
 * (Settings → Reading commits the same value repeatedly when a user
 * tabs through fields) collapse to a single emit.
 */
final class Search_Indexing_Watcher {

	/**
	 * Transient name used to debounce repeat emits.
	 *
	 * @var string
	 */
	private const DEBOUNCE_TRANSIENT = 'fosse_search_indexing_flip_debounce';

	/**
	 * Debounce window in seconds.
	 *
	 * @var int
	 */
	private const DEBOUNCE_SECONDS = 30;

	/**
	 * Wire the option-update hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'update_option_blog_public', array( self::class, 'on_blog_public_change' ), 10, 2 );
	}

	/**
	 * Callback for `update_option_blog_public`.
	 *
	 * Fires only when the value transitions `'1' → '0'` AND FOSSE is
	 * active for the current site. Active is determined by the same
	 * gate the host loader uses (e.g. wpcom-loader's `enable-fosse`
	 * sticker check), exposed here via the
	 * `fosse_metrics_is_active_for_site` filter so each host wires
	 * its own truth source. Default `false` means pure-self-host
	 * checkouts never emit this event — they have no
	 * "FOSSE active" definition.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public static function on_blog_public_change( $old_value, $new_value ): void {
		if ( '1' !== (string) $old_value || '0' !== (string) $new_value ) {
			return;
		}

		/**
		 * Filters whether FOSSE is currently active for the site whose
		 * search-indexing flag just flipped.
		 *
		 * Hosts wire this to their own active-determination logic
		 * (e.g. wpcom-loader uses the `enable-fosse` blog sticker).
		 * Default `false` so checkouts with no host integration emit
		 * nothing.
		 *
		 * @param bool $is_active Whether FOSSE is active for the site.
		 */
		if ( ! \apply_filters( 'fosse_metrics_is_active_for_site', false ) ) {
			return;
		}

		if ( false !== \get_transient( self::DEBOUNCE_TRANSIENT ) ) {
			return;
		}

		\set_transient( self::DEBOUNCE_TRANSIENT, 1, self::DEBOUNCE_SECONDS );

		Recorder::record( 'fosse_search_indexing_disabled_post_active' );
	}
}
