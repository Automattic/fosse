<?php
/**
 * FOSSE metrics recorder — central event entry point.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Metrics;

/**
 * Funnel-event and aggregate-counter entry point.
 *
 * Two transport channels are registered independently:
 *
 * - `Tracks_Channel` instances for funnel events (`record()`).
 * - `Mc_Channel` instances for aggregate counters (`bump()`).
 *
 * Either, both, or neither may be present. A pure self-host install with
 * neither channel registered turns every recorder call into a no-op,
 * which is the FOSSE-on-no-Jetpack posture.
 *
 * Three properties hold for every call:
 *
 * 1. **Fire-and-forget.** Channel exceptions never propagate; the
 *    user-facing flow can't be broken by a transport failure.
 * 2. **Default is silence.** No registered channel → no events emit.
 * 3. **Channels are independent.** Tracks and MC have different consent
 *    surfaces and dashboards; coupling them at the recorder would force
 *    every host to opt into both or neither.
 *
 * See `sdd/fosse-metrics-strategy/implementation.md` for the strategy
 * background.
 */
final class Recorder {

	/**
	 * Re-entrancy guard. A channel or enrichment callback that calls back
	 * into `record()` / `bump()` would otherwise recurse without bound.
	 *
	 * @var bool
	 */
	private static bool $in_flight = false;

	/**
	 * Record a Tracks-style funnel event.
	 *
	 * Pipeline:
	 *
	 * 1. If `$event` is unknown to `Schema`, drop silently in production
	 *    or `E_USER_WARNING` in `WP_DEBUG`.
	 * 2. Run the `fosse_metrics_event_context` filter to let host code
	 *    attach cohort / population (Phases 2 / 6).
	 * 3. Filter `$properties` against `Schema::ALLOWED[ $event ]`. This
	 *    drops both call-site mistakes and channel-decoration leaks
	 *    (e.g. `_via_ip`-shaped keys an enrichment filter accidentally
	 *    introduces).
	 * 4. Forward to every channel registered through
	 *    `fosse_metrics_tracks_channels`. Each channel runs inside a
	 *    `try` block; throwables are swallowed so user paths continue.
	 *
	 * @param string               $event      Event name (must appear in `Schema::ALLOWED`).
	 * @param array<string, mixed> $properties Call-site properties.
	 * @return void
	 */
	public static function record( string $event, array $properties = array() ): void {
		if ( self::$in_flight ) {
			return;
		}

		if ( ! Schema::is_known( $event ) ) {
			if ( self::is_debugging() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- intentional WP_DEBUG-only schema-drift surface.
				\trigger_error(
					\sprintf(
						'FOSSE metrics: unknown event %s — dropped.',
						\esc_html( $event )
					),
					\E_USER_WARNING
				);
			}
			return;
		}

		self::$in_flight = true;
		try {
			/**
			 * Filters the property bag for a FOSSE metrics event before
			 * allowlist validation.
			 *
			 * Hosts use this to attach cohort / population — see the
			 * wpcom-loader's Phase 2 implementation. Filter callbacks MUST
			 * NOT add keys that violate the privacy contract (no IPs, UAs,
			 * URLs); the allowlist will drop them but `WP_DEBUG` will warn.
			 *
			 * A throwing callback is contained: the recorder logs and
			 * proceeds with the un-enriched property bag rather than
			 * letting a misbehaving filter break the user-facing flow.
			 *
			 * @param array<string, mixed> $properties Properties bag.
			 * @param string               $event      Event name.
			 */
			try {
				$properties = (array) \apply_filters( 'fosse_metrics_event_context', $properties, $event );
			} catch ( \Throwable $e ) {
				self::log_throwable( $e, $event );
			}

			$properties = Schema::filter_properties( $event, $properties );

			foreach ( self::tracks_channels() as $channel ) {
				try {
					$channel->record( $event, $properties );
				} catch ( \Throwable $e ) {
					self::log_throwable( $e, $event );
				}
			}
		} finally {
			self::$in_flight = false;
		}
	}

	/**
	 * Bump an aggregate counter on every registered MC channel.
	 *
	 * No enrichment, no allowlist — `$name` is opaque. Channels handle
	 * their own grouping and namespacing.
	 *
	 * @param string $name Counter name.
	 * @return void
	 */
	public static function bump( string $name ): void {
		if ( self::$in_flight ) {
			return;
		}

		self::$in_flight = true;
		try {
			foreach ( self::mc_channels() as $channel ) {
				try {
					$channel->bump( $name );
				} catch ( \Throwable $e ) {
					self::log_throwable( $e, $name );
				}
			}
		} finally {
			self::$in_flight = false;
		}
	}

	/**
	 * Resolve the registered Tracks channels.
	 *
	 * Filter callbacks must return an array of `Tracks_Channel` instances.
	 * Non-conforming entries are filtered out so a misbehaving filter
	 * can't crash the recorder.
	 *
	 * @return list<Tracks_Channel>
	 */
	private static function tracks_channels(): array {
		/**
		 * Filters the registered Tracks channels.
		 *
		 * @param list<Tracks_Channel> $channels Channels to deliver every recorded event.
		 */
		try {
			$channels = (array) \apply_filters( 'fosse_metrics_tracks_channels', array() );
		} catch ( \Throwable $e ) {
			self::log_throwable( $e, 'fosse_metrics_tracks_channels' );
			return array();
		}

		return \array_values(
			\array_filter(
				$channels,
				static fn ( $channel ) => $channel instanceof Tracks_Channel
			)
		);
	}

	/**
	 * Resolve the registered MC channels.
	 *
	 * Non-conforming entries are filtered out so a misbehaving filter
	 * can't crash the recorder.
	 *
	 * @return list<Mc_Channel>
	 */
	private static function mc_channels(): array {
		/**
		 * Filters the registered MC bump channels.
		 *
		 * @param list<Mc_Channel> $channels Channels to deliver every recorded bump.
		 */
		try {
			$channels = (array) \apply_filters( 'fosse_metrics_mc_channels', array() );
		} catch ( \Throwable $e ) {
			self::log_throwable( $e, 'fosse_metrics_mc_channels' );
			return array();
		}

		return \array_values(
			\array_filter(
				$channels,
				static fn ( $channel ) => $channel instanceof Mc_Channel
			)
		);
	}

	/**
	 * Log a swallowed channel exception when `WP_DEBUG` is on.
	 *
	 * Uses `error_log` rather than `trigger_error` because a transport
	 * failure is operational noise, not a contract violation.
	 *
	 * @param \Throwable $error   The thrown error.
	 * @param string     $context Event name or counter name for context.
	 * @return void
	 */
	private static function log_throwable( \Throwable $error, string $context ): void {
		if ( ! self::is_debugging() ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional WP_DEBUG-only diagnostic for swallowed channel exceptions.
		\error_log(
			\sprintf(
				'FOSSE metrics channel threw on %s: %s',
				$context,
				$error->getMessage()
			)
		);
	}

	/**
	 * Whether `WP_DEBUG` is on.
	 *
	 * @return bool
	 */
	private static function is_debugging(): bool {
		return \defined( 'WP_DEBUG' ) && WP_DEBUG;
	}
}
