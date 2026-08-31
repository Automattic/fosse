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
		static $ran_in_request = array();

		if ( isset( $ran_in_request[ $option_key ] ) && $ran_in_request[ $option_key ] === $version ) {
			return;
		}

		$stored = get_option( $option_key, false );

		if ( $stored === $version ) {
			$ran_in_request[ $option_key ] = $version;
			return;
		}

		if ( false === $stored ) {
			/*
			 * First load: claim the flag atomically *before* running the
			 * activate routine. add_option() issues an INSERT that the DB
			 * rejects (duplicate key) if a concurrent first-load request
			 * already inserted the row, so exactly one request wins and runs
			 * the expensive activation (flush_rewrite_rules + comment-count
			 * migration). Losers bail and let the winner finish. Autoload is
			 * 'no' so the flag stays off the bulk-loaded options cache.
			 */
			if ( ! add_option( $option_key, $version, '', false ) ) {
				// Another request claimed the flag first; mark this request
				// done so later hook firings here don't keep probing.
				$ran_in_request[ $option_key ] = $version;
				return;
			}

			$ran_in_request[ $option_key ] = $version;

			try {
				$activate();
			} catch ( \Throwable $e ) {
				// Roll the lock back so a subsequent request can retry the
				// activation. Without this, a thrown activation would leave
				// the flag set forever and the activate routine would never
				// run again (rewrites unflushed, options unseeded, etc.).
				// Note: a PHP fatal (memory/time exhaustion) still
				// terminates the request without firing this catch, so the
				// flag would persist. That tail case is out of scope here.
				delete_option( $option_key );
				throw $e;
			}
			return;
		}

		/*
		 * Stored value present but stale (the bundled version changed since
		 * the last bootstrap). This is a deploy-time transition, not the
		 * concurrent first-load race add_option() guards against, so a plain
		 * value update is sufficient. Snapshot the prior version so a
		 * throwing activate routine can be rolled back to the value it
		 * already had — without that, a half-applied version bump would
		 * convince the next request the new activation completed and the
		 * version-changed activate would never re-run.
		 */
		$prior = $stored;
		update_option( $option_key, $version, false );
		$ran_in_request[ $option_key ] = $version;
		try {
			$activate();
		} catch ( \Throwable $e ) {
			update_option( $option_key, $prior, false );
			throw $e;
		}
	}
}
