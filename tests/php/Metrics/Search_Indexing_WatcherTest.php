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
 * `fosse_metrics_is_active_for_site` filter, and `register()` idempotence.
 */
class Search_Indexing_WatcherTest extends BaseTestCase {

	use Asserts_Metrics;

	/**
	 * Reset filter state, in-memory channels, the registration guard, and
	 * the option baseline.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		$this->reset_metrics_channels();
		\remove_all_filters( 'fosse_metrics_is_active_for_site' );
		\remove_all_actions( 'update_option_blog_public' );
		// register() is idempotent — reset the guard so each case wires
		// the action freshly after the remove_all_actions() above.
		( new \ReflectionClass( Search_Indexing_Watcher::class ) )
			->getProperty( 'registered' )
			->setValue( null, false );
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
	 * Identical re-saves never reach the watcher: `update_option()`
	 * short-circuits when the value is unchanged, so the
	 * `update_option_blog_public` action does not fire. The watcher
	 * therefore needs no debounce to suppress duplicate writes — they
	 * cannot arrive.
	 */
	public function test_identical_resave_does_not_emit(): void {
		\add_filter( 'fosse_metrics_is_active_for_site', '__return_true' );

		// Baseline is already '1'. Re-saving '1' is a no-op write.
		\update_option( 'blog_public', '1' );

		$this->assertNoEventRecorded( 'fosse_search_indexing_disabled_post_active' );
	}

	/**
	 * Each genuine 1 → 0 flip is recorded. With the debounce removed, a
	 * user who toggles off, back on, and off again within seconds produces
	 * two distinct anti-pattern signals — both are real and both count.
	 */
	public function test_each_genuine_flip_emits(): void {
		\add_filter( 'fosse_metrics_is_active_for_site', '__return_true' );

		\update_option( 'blog_public', '0' );
		\update_option( 'blog_public', '1' );
		\update_option( 'blog_public', '0' );

		$captured = $this->tracks_channel()->events_for( 'fosse_search_indexing_disabled_post_active' );
		$this->assertCount(
			2,
			$captured,
			'Each genuine 1->0 flip should emit; only the no-op middle 1->? write is skipped by the transition guard.'
		);
	}

	/**
	 * Repeated `register()` calls must not double-attach the listener.
	 * `add_action()` does not dedupe identical callbacks, so without the
	 * static guard a second call would emit the event twice per flip.
	 */
	public function test_register_is_idempotent(): void {
		// set_up_state() already called register() once.
		Search_Indexing_Watcher::register();
		Search_Indexing_Watcher::register();

		\add_filter( 'fosse_metrics_is_active_for_site', '__return_true' );
		\update_option( 'blog_public', '0' );

		$captured = $this->tracks_channel()->events_for( 'fosse_search_indexing_disabled_post_active' );
		$this->assertCount( 1, $captured, 'Repeated register() must not double-attach the listener.' );
	}
}
