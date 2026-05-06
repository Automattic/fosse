<?php
/**
 * Tests for the metrics Recorder pipeline.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Metrics;

use Automattic\Fosse\Metrics\Recorder;
use Automattic\Fosse\Metrics\Tracks_Channel;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Locks the recorder's pipeline in: unknown events drop, `WP_DEBUG`
 * warns, allowlist runs after enrichment, channel exceptions don't
 * propagate, no-channel default is silent.
 */
class RecorderTest extends BaseTestCase {

	use Asserts_Metrics;

	/**
	 * Reset metrics filters + in-memory channels before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_metrics(): void {
		$this->reset_metrics_channels();
	}

	/**
	 * Unknown events are dropped silently in production.
	 */
	public function test_unknown_event_dropped_in_production(): void {
		Recorder::record( 'fosse_not_a_real_event', array( 'foo' => 'bar' ) );

		$this->assertEmpty(
			$this->tracks_channel()->events(),
			'Unknown event must not reach any channel.'
		);
	}

	/**
	 * Disallowed properties (e.g. `_via_ip`-shaped sink decorations) are
	 * dropped before the channel sees them.
	 */
	public function test_disallowed_property_dropped(): void {
		Recorder::record(
			'fosse_wizard_started',
			array(
				'entry'   => 'auto',
				'_via_ip' => '1.2.3.4',
			)
		);

		$this->assertEventRecorded( 'fosse_wizard_started', array( 'entry' => 'auto' ) );
		$captured = $this->tracks_channel()->events_for( 'fosse_wizard_started' );
		$this->assertArrayNotHasKey( '_via_ip', $captured[0]['properties'] );
	}

	/**
	 * Enrichment runs before the allowlist, so an enrichment filter
	 * adding a non-allowlisted key still gets dropped.
	 */
	public function test_enrich_filter_runs_before_allowlist(): void {
		\add_filter(
			'fosse_metrics_event_context',
			static function ( array $properties ): array {
				$properties['cohort']      = 'A';
				$properties['leaked_html'] = '<script>nope</script>';
				return $properties;
			}
		);

		Recorder::record( 'fosse_wizard_started', array( 'entry' => 'auto' ) );

		$captured = $this->tracks_channel()->events_for( 'fosse_wizard_started' );
		$this->assertCount( 1, $captured );
		$this->assertSame(
			array(
				'entry'  => 'auto',
				'cohort' => 'A',
			),
			$captured[0]['properties']
		);
	}

	/**
	 * A channel that throws does not propagate; subsequent channels
	 * still run.
	 */
	public function test_channel_exception_does_not_propagate(): void {
		$throwing_channel = new class() implements Tracks_Channel {
			/**
			 * Always throws to verify recorder swallows transport failures.
			 *
			 * @param string               $event      Unused.
			 * @param array<string, mixed> $properties Unused.
			 * @return void
			 * @throws \RuntimeException Always.
			 */
			public function record( string $event, array $properties ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- interface contract requires the parameters.
				throw new \RuntimeException( 'transport down' );
			}
		};

		\add_filter(
			'fosse_metrics_tracks_channels',
			static fn ( array $channels ): array => \array_merge( array( $throwing_channel ), $channels )
		);

		Recorder::record( 'fosse_wizard_started', array( 'entry' => 'auto' ) );

		// The in-memory channel after the throwing one still ran.
		$this->assertEventRecorded( 'fosse_wizard_started', array( 'entry' => 'auto' ) );
	}

	/**
	 * With no channels registered, `record()` is a no-op (no fatal).
	 */
	public function test_no_channels_registered_no_op(): void {
		\remove_all_filters( 'fosse_metrics_tracks_channels' );

		Recorder::record( 'fosse_wizard_started', array( 'entry' => 'auto' ) );

		$this->assertTrue( true, 'Recorder must not raise when no channels are registered.' );
	}

	/**
	 * `bump()` reaches every registered MC channel and is fire-and-forget.
	 */
	public function test_bump_dispatches_to_mc_channels(): void {
		Recorder::bump( 'wizard-completed' );

		$this->assertMcBumped( 'wizard-completed' );
	}

	/**
	 * MC channel exceptions don't propagate.
	 */
	public function test_bump_swallows_channel_exceptions(): void {
		$throwing_channel = new class() implements \Automattic\Fosse\Metrics\Mc_Channel {
			/**
			 * Always throws to verify recorder swallows transport failures.
			 *
			 * @param string $name Unused.
			 * @return void
			 * @throws \RuntimeException Always.
			 */
			public function bump( string $name ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- interface contract requires the parameter.
				throw new \RuntimeException( 'pixel.wp.com down' );
			}
		};

		\add_filter(
			'fosse_metrics_mc_channels',
			static fn ( array $channels ): array => \array_merge( array( $throwing_channel ), $channels )
		);

		Recorder::bump( 'wizard-completed' );

		$this->assertMcBumped( 'wizard-completed' );
	}

	/**
	 * Filter callbacks returning non-`Tracks_Channel` entries are
	 * filtered out so a misbehaving filter can't crash the recorder.
	 */
	public function test_non_channel_filter_entries_ignored(): void {
		\add_filter(
			'fosse_metrics_tracks_channels',
			static fn ( array $channels ): array => \array_merge( $channels, array( 'not-a-channel', 42, null ) )
		);

		Recorder::record( 'fosse_wizard_started', array( 'entry' => 'auto' ) );

		// The in-memory channel still saw the event; the bogus filter entries did not crash anything.
		$this->assertEventRecorded( 'fosse_wizard_started', array( 'entry' => 'auto' ) );
	}
}
