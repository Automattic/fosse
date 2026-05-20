<?php
/**
 * Tests for Search_Indexing_Watcher.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Metrics;

use Automattic\Fosse\Metrics\Search_Indexing_Watcher;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the search-indexing-disabled emit gates: transition direction,
 * `fosse_metrics_is_active_for_site` filter, transient-based debounce.
 */
class Search_Indexing_WatcherTest extends BaseTestCase {

	use Asserts_Metrics;

	/**
	 * Reset filter state, in-memory channels, and the debounce transient.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		$this->reset_metrics_channels();
		\remove_all_filters( 'fosse_metrics_is_active_for_site' );
		\delete_transient( 'fosse_search_indexing_flip_debounce' );
		\update_option( 'blog_public', '1' );
		Search_Indexing_Watcher::register();
	}

	/**
	 * 1 → 0 with FOSSE active emits the event.
	 */
	public function test_emits_on_off_transition_when_active(): void {
		\add_filter( 'fosse_metrics_is_active_for_site', '__return_true' );

		\update_option( 'blog_public', '0' );

		$this->assertEventRecorded( 'fosse_search_indexing_disabled_post_active' );
	}

	/**
	 * 0 → 1 (re-enable) does not emit.
	 */
	public function test_no_emit_on_on_transition(): void {
		\update_option( 'blog_public', '0' );
		// Reset capture so the implicit setup-time write is forgotten.
		$this->reset_metrics_channels();
		\add_filter( 'fosse_metrics_is_active_for_site', '__return_true' );

		\update_option( 'blog_public', '1' );

		$this->assertNoEventRecorded( 'fosse_search_indexing_disabled_post_active' );
	}

	/**
	 * 1 → 0 with FOSSE inactive does not emit.
	 */
	public function test_no_emit_when_inactive(): void {
		\add_filter( 'fosse_metrics_is_active_for_site', '__return_false' );

		\update_option( 'blog_public', '0' );

		$this->assertNoEventRecorded( 'fosse_search_indexing_disabled_post_active' );
	}

	/**
	 * Default (no host filter wired) drops the event silently.
	 */
	public function test_no_emit_with_default_filter(): void {
		\update_option( 'blog_public', '0' );

		$this->assertNoEventRecorded( 'fosse_search_indexing_disabled_post_active' );
	}

	/**
	 * Two consecutive 1 → 0 transitions inside the debounce window emit once.
	 */
	public function test_debounces_repeat_emits(): void {
		\add_filter( 'fosse_metrics_is_active_for_site', '__return_true' );

		\update_option( 'blog_public', '0' );
		\update_option( 'blog_public', '1' );
		\update_option( 'blog_public', '0' );

		$captured = $this->tracks_channel()->events_for( 'fosse_search_indexing_disabled_post_active' );
		$this->assertCount( 1, $captured, 'Debounce should collapse repeat 1->0 transitions into a single event.' );
	}

	/**
	 * Once the debounce window expires (transient cleared), a fresh 1 → 0
	 * transition emits again.
	 */
	public function test_re_emits_after_debounce_expires(): void {
		\add_filter( 'fosse_metrics_is_active_for_site', '__return_true' );

		\update_option( 'blog_public', '0' );

		// Simulate the 30-second window elapsing.
		\delete_transient( 'fosse_search_indexing_flip_debounce' );

		\update_option( 'blog_public', '1' );
		\update_option( 'blog_public', '0' );

		$captured = $this->tracks_channel()->events_for( 'fosse_search_indexing_disabled_post_active' );
		$this->assertCount(
			2,
			$captured,
			'Once the debounce window expires, a subsequent 1->0 transition should emit again.'
		);
	}
}
