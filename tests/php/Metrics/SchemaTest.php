<?php
/**
 * Tests for the metrics event schema allowlist.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Metrics;

use Automattic\Fosse\Metrics\Schema;
use WorDBless\BaseTestCase;

/**
 * Locks the v1 event taxonomy in: every documented event is in the
 * allowlist, and `cohort` + `population` are universally permitted so
 * Phase 2 / Phase 6 enrichment can attach them to any event.
 */
class SchemaTest extends BaseTestCase {

	/**
	 * Hardcoded list of every event documented in
	 * `sdd/fosse-metrics-strategy/implementation.md` § Event taxonomy.
	 *
	 * Drift between docs and code surfaces as a test failure — adding
	 * an event to `Schema::ALLOWED` without updating this list, or
	 * vice versa, fails CI.
	 *
	 * @var list<string>
	 */
	private const DOCUMENTED_EVENTS = array(
		'fosse_wizard_started',
		'fosse_wizard_completed',
		'fosse_connection_attempt',
		'fosse_connection_completed',
		'fosse_connection_failed',
		'fosse_bluesky_handle_setup_started',
		'fosse_bluesky_handle_active',
		'fosse_post_published',
		'fosse_publish_result',
		'fosse_inbound_interaction',
		'fosse_author_engaged',
		'fosse_search_indexing_disabled_post_active',
	);

	/**
	 * Every documented event has an allowlist entry.
	 */
	public function test_event_coverage(): void {
		foreach ( self::DOCUMENTED_EVENTS as $event ) {
			$this->assertTrue(
				Schema::is_known( $event ),
				\sprintf( 'Event "%s" missing from Schema::ALLOWED.', $event )
			);
		}
	}

	/**
	 * The allowlist contains no events that aren't documented (drift in
	 * the other direction).
	 */
	public function test_no_undocumented_events(): void {
		$allowed = \array_keys( Schema::ALLOWED );
		\sort( $allowed );

		$documented = self::DOCUMENTED_EVENTS;
		\sort( $documented );

		$this->assertSame(
			$documented,
			$allowed,
			'Schema::ALLOWED contains events not in DOCUMENTED_EVENTS.'
		);
	}

	/**
	 * Every event allows `cohort` and `population` so enrichment can
	 * attach them universally.
	 */
	public function test_cohort_and_population_universally_allowed(): void {
		foreach ( Schema::ALLOWED as $event => $allowed_keys ) {
			$this->assertContains(
				'cohort',
				$allowed_keys,
				\sprintf( 'Event "%s" must permit "cohort".', $event )
			);
			$this->assertContains(
				'population',
				$allowed_keys,
				\sprintf( 'Event "%s" must permit "population".', $event )
			);
		}
	}

	/**
	 * Filter drops disallowed keys silently when `WP_DEBUG` is off.
	 */
	public function test_filter_properties_drops_disallowed(): void {
		$filtered = Schema::filter_properties(
			'fosse_wizard_started',
			array(
				'entry'      => 'auto',
				'_via_ip'    => '1.2.3.4',
				'random_key' => 'should-disappear',
			)
		);

		$this->assertSame( array( 'entry' => 'auto' ), $filtered );
	}

	/**
	 * Filter drops null-valued allowlisted keys (channels treat absence
	 * and null identically).
	 */
	public function test_filter_properties_drops_null_values(): void {
		$filtered = Schema::filter_properties(
			'fosse_wizard_started',
			array(
				'entry'  => 'auto',
				'cohort' => null,
			)
		);

		$this->assertSame( array( 'entry' => 'auto' ), $filtered );
	}

	/**
	 * Unknown events return an empty array (recorder uses `is_known`
	 * separately to decide whether to even reach this method).
	 */
	public function test_filter_properties_unknown_event_returns_empty(): void {
		$filtered = Schema::filter_properties(
			'fosse_not_a_real_event',
			array( 'entry' => 'auto' )
		);

		$this->assertSame( array(), $filtered );
	}
}
