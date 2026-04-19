<?php
/**
 * First-load bootstrap for bundled federation plugins.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Bundled;

/**
 * Bridges the activation-hook gap for plugins FOSSE loads programmatically.
 *
 * WordPress activation hooks only fire when a plugin is activated via the
 * plugins screen. Bundled plugins are required from fosse.php, so their
 * activation side-effects (option seeding, rewrite flush, TID generation,
 * …) never run on their own. Bootstrap::maybe_run closes that gap by
 * invoking an activate callable on first load, keyed to the upstream
 * version so we re-run if the bundled version changes.
 */
class Bootstrap {

	/**
	 * Run $activate once per distinct $version per $option_key.
	 *
	 * @param string   $option_key WordPress option that records the
	 *                             last-bootstrapped version.
	 * @param string   $version    Upstream plugin version string
	 *                             (e.g. ACTIVITYPUB_PLUGIN_VERSION).
	 * @param callable $activate   Callable that performs the
	 *                             activation side-effects.
	 * @return void
	 */
	public static function maybe_run( string $option_key, string $version, callable $activate ): void {
		if ( get_option( $option_key ) === $version ) {
			return;
		}

		$activate();
		update_option( $option_key, $version, false );
	}
}
