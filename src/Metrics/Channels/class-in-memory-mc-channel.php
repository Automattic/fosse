<?php
/**
 * In-memory MC channel for tests.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Metrics\Channels;

use Automattic\Fosse\Metrics\Mc_Channel;

/**
 * Captures every `bump()` invocation in memory for assertions.
 *
 * Same registration pattern as `In_Memory_Tracks_Channel` — autoloaded
 * via classmap but only registered by tests through
 * `fosse_metrics_mc_channels`.
 */
final class In_Memory_Mc_Channel implements Mc_Channel {

	/**
	 * Captured bump names in call order.
	 *
	 * @var list<string>
	 */
	private array $bumps = array();

	/**
	 * Bump a counter.
	 *
	 * @param string $name Counter name.
	 * @return void
	 */
	public function bump( string $name ): void {
		$this->bumps[] = $name;
	}

	/**
	 * All bumped counter names in call order.
	 *
	 * @return list<string>
	 */
	public function bumps(): array {
		return $this->bumps;
	}

	/**
	 * Drop all captured bumps.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->bumps = array();
	}
}
