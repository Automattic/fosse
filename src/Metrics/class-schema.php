<?php
/**
 * FOSSE metrics event schema and property allowlist.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Metrics;

/**
 * Static allowlist for the v1 FOSSE metrics event taxonomy.
 *
 * Every documented event in `sdd/fosse-metrics-strategy/implementation.md`
 * has an entry in `ALLOWED` listing the property names that may accompany
 * it. The recorder calls `filter_properties()` AFTER the
 * `fosse_metrics_event_context` enrichment filter runs, so cohort/population
 * (added by host-specific enrichment in Phase 2 / Phase 6) flow through the
 * same allowlist as call-site-supplied properties.
 *
 * Two enforcement modes:
 *
 * - In `WP_DEBUG`, schema violations (unknown event, disallowed property)
 *   trigger `E_USER_WARNING`. CI catches schema drift loudly.
 * - In production, violations are dropped silently. The event still emits
 *   with the surviving allowlisted properties; we'd rather lose a property
 *   than break a user-facing flow.
 *
 * The hardcoded `ALLOWED` map is the source of truth — updates must match
 * `implementation.md` § Event taxonomy. `SchemaTest::test_event_coverage`
 * iterates a parallel list to detect drift between docs and code.
 */
final class Schema {

	/**
	 * Property allowlist per event.
	 *
	 * Every value array universally permits `cohort` and `population`;
	 * those are added by enrichment (Phase 2 + Phase 6) and must pass
	 * the same allowlist check as call-site properties.
	 *
	 * @var array<string, list<string>>
	 */
	public const ALLOWED = array(
		'fosse_wizard_started'                       => array(
			'entry',
			'cohort',
			'population',
		),
		'fosse_wizard_completed'                     => array(
			'destination',
			'actor_mode',
			'post_types_count_bucket',
			'bluesky_state',
			'cohort',
			'population',
		),
		'fosse_connection_attempt'                   => array(
			'network',
			'source',
			'cohort',
			'population',
		),
		'fosse_connection_completed'                 => array(
			'network',
			'source',
			'cohort',
			'population',
		),
		'fosse_connection_failed'                    => array(
			'network',
			'source',
			'error_category',
			'cohort',
			'population',
		),
		'fosse_bluesky_handle_setup_started'         => array(
			'eligibility',
			'cohort',
			'population',
		),
		'fosse_bluesky_handle_active'                => array(
			'cohort',
			'population',
		),
		'fosse_post_published'                       => array(
			'post_format',
			'has_image',
			'cohort',
			'population',
		),
		'fosse_publish_result'                       => array(
			'network',
			'status',
			'strategy',
			'error_category',
			'cohort',
			'population',
		),
		'fosse_inbound_interaction'                  => array(
			'network',
			'kind',
			'days_since_publish_bucket',
			'cohort',
			'population',
		),
		'fosse_author_engaged'                       => array(
			'network',
			'kind',
			'days_since_interaction_bucket',
			'cohort',
			'population',
		),
		'fosse_search_indexing_disabled_post_active' => array(
			'cohort',
			'population',
		),
	);

	/**
	 * Whether the given event name appears in `ALLOWED`.
	 *
	 * @param string $event Event name to check.
	 * @return bool
	 */
	public static function is_known( string $event ): bool {
		return isset( self::ALLOWED[ $event ] );
	}

	/**
	 * Filter `$properties` against the allowlist for `$event`.
	 *
	 * Disallowed keys are dropped. In `WP_DEBUG`, each disallowed key
	 * triggers a separate `E_USER_WARNING` so the offending names are
	 * visible in CI logs. Unknown events return an empty array.
	 *
	 * `null`-valued properties are dropped — channels treat absence and
	 * null identically, and dropping at the schema layer keeps cohort
	 * enrichment "no cohort to attach" cases from emitting a literal
	 * `null` over the wire.
	 *
	 * @param string               $event      Event name.
	 * @param array<string, mixed> $properties Caller- and enrichment-supplied properties.
	 * @return array<string, mixed> Allowlist-filtered, null-stripped properties.
	 */
	public static function filter_properties( string $event, array $properties ): array {
		if ( ! self::is_known( $event ) ) {
			return array();
		}

		$allowed   = self::ALLOWED[ $event ];
		$filtered  = array();
		$debugging = self::is_debugging();

		foreach ( $properties as $key => $value ) {
			if ( ! \in_array( $key, $allowed, true ) ) {
				if ( $debugging ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- intentional WP_DEBUG-only schema-violation surface.
					\trigger_error(
						\sprintf(
							'FOSSE metrics: disallowed property %s on event %s — dropped.',
							\esc_html( (string) $key ),
							\esc_html( $event )
						),
						\E_USER_WARNING
					);
				}
				continue;
			}

			if ( null === $value ) {
				continue;
			}

			$filtered[ $key ] = $value;
		}

		return $filtered;
	}

	/**
	 * Whether `WP_DEBUG` is on.
	 *
	 * Wrapper exists so tests can stub strict-mode behavior without the
	 * test process having to be booted with WP_DEBUG=true.
	 *
	 * @return bool
	 */
	private static function is_debugging(): bool {
		return \defined( 'WP_DEBUG' ) && WP_DEBUG;
	}
}
