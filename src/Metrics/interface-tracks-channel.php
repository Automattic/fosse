<?php
/**
 * Tracks channel interface — funnel-event transport.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Metrics;

/**
 * Contract for a transport that delivers identified, rich-property funnel
 * events. Channels are registered via the `fosse_metrics_tracks_channels`
 * filter; the recorder iterates registered channels for every `record()`
 * call. Multiple channels may be registered simultaneously (e.g. an
 * in-memory test channel alongside a production transport).
 *
 * Implementations MUST be fire-and-forget — the recorder catches any
 * `\Throwable` raised by `record()` so user paths never see a transport
 * failure. Implementations SHOULD NOT decorate properties with values
 * that violate the privacy contract (no IPs, no UAs, no URLs); see
 * `sdd/fosse-metrics-strategy/implementation.md` § Privacy contract
 * enforcement for the full list.
 */
interface Tracks_Channel {

	/**
	 * Deliver an event with allowlisted, enriched properties.
	 *
	 * Called by `Recorder::record()` after schema validation and the
	 * `fosse_metrics_event_context` enrichment filter have run. The
	 * `$properties` array contains only keys present in
	 * `Schema::ALLOWED[ $event ]`.
	 *
	 * @param string               $event      Allowlisted event name (e.g. `fosse_wizard_started`).
	 * @param array<string, mixed> $properties Allowlist-filtered property bag.
	 * @return void
	 */
	public function record( string $event, array $properties ): void;
}
