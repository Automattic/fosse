<?php
/**
 * In-memory Tracks channel for tests.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Metrics\Channels;

use Automattic\Fosse\Metrics\Tracks_Channel;

/**
 * Captures every `record()` invocation in memory for assertions.
 *
 * Lives under `src/` so the production classmap autoloads it, but
 * registration is opt-in via `fosse_metrics_tracks_channels`. Production
 * code never registers this — only tests do, via the `Asserts_Metrics`
 * trait's `setUp` helper.
 *
 * Each captured entry is `array{ event: string, properties: array }`,
 * preserving call order so tests can assert sequence.
 */
final class In_Memory_Tracks_Channel implements Tracks_Channel {

	/**
	 * Captured events in call order.
	 *
	 * @var list<array{event: string, properties: array<string, mixed>}>
	 */
	private array $captured = array();

	/**
	 * Record a single event.
	 *
	 * @param string               $event      Event name.
	 * @param array<string, mixed> $properties Allowlist-filtered properties.
	 * @return void
	 */
	public function record( string $event, array $properties ): void {
		$this->captured[] = array(
			'event'      => $event,
			'properties' => $properties,
		);
	}

	/**
	 * All captured events in call order.
	 *
	 * @return list<array{event: string, properties: array<string, mixed>}>
	 */
	public function events(): array {
		return $this->captured;
	}

	/**
	 * Captured events filtered by name.
	 *
	 * @param string $event Event name to filter on.
	 * @return list<array{event: string, properties: array<string, mixed>}>
	 */
	public function events_for( string $event ): array {
		return \array_values(
			\array_filter(
				$this->captured,
				static fn ( array $entry ) => $entry['event'] === $event
			)
		);
	}

	/**
	 * Drop all captured events.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->captured = array();
	}
}
