<?php
/**
 * MC channel interface — aggregate-counter transport.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Metrics;

/**
 * Contract for a transport that bumps anonymous, low-cardinality counters
 * (e.g. `bump_stats_extras` on wpcom, the Jetpack stats bump path on
 * Jetpack-connected self-hosted). Channels are registered via the
 * `fosse_metrics_mc_channels` filter; the recorder iterates registered
 * channels for every `bump()` call.
 *
 * MC bumps are intentionally separate from `Tracks_Channel` — Tracks and
 * MC have different consent surfaces, different transport costs, and
 * different dashboard surfaces. A single composite sink would couple
 * those independent concerns.
 *
 * Implementations MUST be fire-and-forget; the recorder catches any
 * `\Throwable` raised by `bump()`.
 */
interface Mc_Channel {

	/**
	 * Bump an aggregate counter.
	 *
	 * `$name` is interpreted by the channel — typically appended to a
	 * group identifier (e.g. wpcom uses group `fosse`, surfacing on
	 * `mc.wordpress.com/?v=fosse`).
	 *
	 * @param string $name Counter name (e.g. `wizard-completed`).
	 * @return void
	 */
	public function bump( string $name ): void;
}
