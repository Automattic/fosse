<?php
/**
 * PHPUnit trait for asserting on FOSSE metrics events and bumps.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Metrics;

use Automattic\Fosse\Metrics\Channels\In_Memory_Mc_Channel;
use Automattic\Fosse\Metrics\Channels\In_Memory_Tracks_Channel;

/**
 * Test convenience for in-memory metrics capture and assertion.
 *
 * Mix into a `BaseTestCase` subclass and call `reset_metrics_channels()`
 * from `setUp()` (or via a `#[Before]` hook). The trait wires fresh
 * `In_Memory_*` channel instances into the metrics filters and exposes
 * helpers for asserting captured events / bumps.
 *
 * Each `reset_metrics_channels()` call removes any previously-registered
 * test channels so leakage across cases can't manifest as duplicate
 * captures.
 */
// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors PHPUnit's native camelCase assertion convention.
trait Asserts_Metrics {

	/**
	 * Currently-registered tracks channel (one per test).
	 *
	 * @var In_Memory_Tracks_Channel|null
	 */
	private ?In_Memory_Tracks_Channel $tracks_channel = null;

	/**
	 * Currently-registered MC channel (one per test).
	 *
	 * @var In_Memory_Mc_Channel|null
	 */
	private ?In_Memory_Mc_Channel $mc_channel = null;

	/**
	 * Wipe channel registrations + previously-captured state and
	 * register fresh in-memory channels via the metrics filters.
	 *
	 * @return void
	 */
	protected function reset_metrics_channels(): void {
		\remove_all_filters( 'fosse_metrics_tracks_channels' );
		\remove_all_filters( 'fosse_metrics_mc_channels' );
		\remove_all_filters( 'fosse_metrics_event_context' );

		$this->tracks_channel = new In_Memory_Tracks_Channel();
		$this->mc_channel     = new In_Memory_Mc_Channel();

		\add_filter(
			'fosse_metrics_tracks_channels',
			fn ( array $channels ): array => array_merge( $channels, array( $this->tracks_channel ) )
		);
		\add_filter(
			'fosse_metrics_mc_channels',
			fn ( array $channels ): array => array_merge( $channels, array( $this->mc_channel ) )
		);
	}

	/**
	 * Get the registered tracks channel for assertions.
	 *
	 * @return In_Memory_Tracks_Channel
	 */
	protected function tracks_channel(): In_Memory_Tracks_Channel {
		if ( null === $this->tracks_channel ) {
			$this->fail( 'Asserts_Metrics: call reset_metrics_channels() before asserting.' );
		}
		return $this->tracks_channel;
	}

	/**
	 * Get the registered MC channel for assertions.
	 *
	 * @return In_Memory_Mc_Channel
	 */
	protected function mc_channel(): In_Memory_Mc_Channel {
		if ( null === $this->mc_channel ) {
			$this->fail( 'Asserts_Metrics: call reset_metrics_channels() before asserting.' );
		}
		return $this->mc_channel;
	}

	/**
	 * Assert a single event was recorded with the given name.
	 *
	 * If `$expected_subset` is non-empty, asserts the event's properties
	 * are a superset of those keys/values. Properties not listed in
	 * `$expected_subset` are ignored — partial assertion is intentional
	 * so tests don't have to know about cohort enrichment, etc.
	 *
	 * @param string               $event           Expected event name.
	 * @param array<string, mixed> $expected_subset Optional property subset to require.
	 * @return void
	 */
	protected function assertEventRecorded( string $event, array $expected_subset = array() ): void {
		$matches = $this->tracks_channel()->events_for( $event );

		$this->assertNotEmpty(
			$matches,
			\sprintf( 'Expected event "%s" to be recorded; nothing captured.', $event )
		);

		if ( empty( $expected_subset ) ) {
			return;
		}

		foreach ( $matches as $captured ) {
			$properties = $captured['properties'];
			$missing    = false;
			foreach ( $expected_subset as $key => $value ) {
				if ( ! \array_key_exists( $key, $properties ) || $properties[ $key ] !== $value ) {
					$missing = true;
					break;
				}
			}
			if ( ! $missing ) {
				return;
			}
		}

		$this->fail(
			\sprintf(
				'No "%s" event matched expected property subset %s. Captured: %s',
				$event,
				\wp_json_encode( $expected_subset, \JSON_UNESCAPED_SLASHES ),
				\wp_json_encode( $matches, \JSON_UNESCAPED_SLASHES )
			)
		);
	}

	/**
	 * Assert the given event was never recorded.
	 *
	 * @param string $event Event name.
	 * @return void
	 */
	protected function assertNoEventRecorded( string $event ): void {
		$matches = $this->tracks_channel()->events_for( $event );
		$this->assertEmpty(
			$matches,
			\sprintf( 'Expected no "%s" events; captured %d.', $event, \count( $matches ) )
		);
	}

	/**
	 * Assert a counter was bumped at least once with the given name.
	 *
	 * @param string $name Counter name.
	 * @return void
	 */
	protected function assertMcBumped( string $name ): void {
		$this->assertContains(
			$name,
			$this->mc_channel()->bumps(),
			\sprintf( 'Expected MC bump "%s"; captured: %s', $name, \wp_json_encode( $this->mc_channel()->bumps(), \JSON_UNESCAPED_SLASHES ) )
		);
	}

	/**
	 * Assert a counter was never bumped with the given name.
	 *
	 * @param string $name Counter name.
	 * @return void
	 */
	protected function assertNoMcBumped( string $name ): void {
		$this->assertNotContains(
			$name,
			$this->mc_channel()->bumps(),
			\sprintf( 'Expected no MC bump "%s"; captured: %s', $name, \wp_json_encode( $this->mc_channel()->bumps(), \JSON_UNESCAPED_SLASHES ) )
		);
	}
}
// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
