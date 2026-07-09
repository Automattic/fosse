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
 * Hooked at `update_option_blog_public`, which WordPress fires only when
 * the stored value actually changes — `update_option()` short-circuits
 * and returns early when the new value equals the old one, so repeated
 * identical admin saves (e.g. tabbing through Settings → Reading without
 * touching this field) never reach this watcher. Combined with the
 * `'1' → '0'` transition guard in the callback, every invocation already
 * represents a genuine flip, so no debounce is needed: a true `1 → 0`
 * flip is exactly what we want to record, and identical re-saves can't
 * arrive in the first place.
 */
final class Search_Indexing_Watcher {

	/**
	 * Cross-call guard against duplicate hook registration.
	 *
	 * `add_action()` does NOT dedupe identical callbacks — calling
	 * `register()` twice without this guard would attach the listener
	 * twice and emit the event twice per flip. Mirrors
	 * `Publish_Events::$registered`.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Wire the option-update hook.
	 *
	 * Idempotent: the static `$registered` flag short-circuits repeat
	 * calls so duplicate listeners can't be attached. `add_action()`
	 * itself does not dedupe identical callbacks.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

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

		Recorder::record( 'fosse_search_indexing_disabled_post_active' );
	}
}
