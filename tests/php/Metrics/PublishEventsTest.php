<?php
/**
 * Tests for Publish_Events.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Metrics;

use Automattic\Fosse\Metrics\Publish_Events;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the AP outbox-aggregate path and the Atmosphere result-hook
 * path: each emits the documented events with correct status, strategy,
 * and error_category enums. Locks down per-inbox failure aggregation so a
 * single bad inbox doesn't flip a multi-inbox publish to `failure`.
 */
class PublishEventsTest extends BaseTestCase {

	use Asserts_Metrics;

	/**
	 * Reset metrics channels and re-register the subscriber.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		$this->reset_metrics_channels();
		\remove_all_filters( 'atmosphere_is_short_form_post' );
		\remove_all_filters( 'atmosphere_long_form_composition' );
		\remove_all_actions( 'activitypub_sent_to_inbox' );
		\remove_all_actions( 'activitypub_outbox_processing_batch_complete' );
		\remove_all_actions( 'activitypub_outbox_processing_complete' );
		\remove_all_actions( 'atmosphere_publish_post_result' );
		// register() is idempotent — guarded by a static flag so duplicate
		// listeners can't be attached in production. Tests reset both
		// statics so each case starts with a fully clean wiring.
		$this->reset_publish_events_statics();
		Publish_Events::register();
	}

	/**
	 * Wipe both static properties on Publish_Events so each test starts
	 * with no in-memory dispatch state AND with the idempotency guard
	 * cleared (otherwise the second test's `register()` would early-return
	 * after `set_up_state()` has removed all the actions).
	 */
	private function reset_publish_events_statics(): void {
		$reflection = new \ReflectionClass( Publish_Events::class );
		$reflection->getProperty( 'ap_dispatch_state' )->setValue( null, array() );
		$reflection->getProperty( 'registered' )->setValue( null, false );
	}

	/**
	 * AP dispatch with at least one successful inbox emits a
	 * `success` `fosse_publish_result` and bumps the MC counter.
	 */
	public function test_ap_outbox_success_emits_success_event(): void {
		$outbox_id = 4242;

		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://example.com/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://example.com/inbox' ), '{}', 1, $outbox_id, 1, 0 );

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'activitypub',
				'status'  => 'success',
			)
		);
		$this->assertMcBumped( 'fosse-publish-success-activitypub' );
	}

	/**
	 * AP dispatch where every inbox returned a WP_Error emits a
	 * `failure` event with the first failure code classified into the
	 * `error_category` enum, and does NOT bump the success counter.
	 */
	public function test_ap_outbox_all_failures_emits_failure_event(): void {
		$outbox_id = 4243;

		\do_action(
			'activitypub_sent_to_inbox',
			new \WP_Error( 'unauthorized', 'nope' ),
			'https://example.com/inbox',
			'{}',
			1,
			$outbox_id
		);
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://example.com/inbox' ), '{}', 1, $outbox_id, 1, 0 );

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network'        => 'activitypub',
				'status'         => 'failure',
				'error_category' => 'auth_failed',
			)
		);
		$this->assertNoMcBumped( 'fosse-publish-success-activitypub' );
	}

	/**
	 * Mixed AP results count as success — a single bad inbox in a
	 * multi-inbox dispatch shouldn't downgrade the whole publish.
	 */
	public function test_ap_outbox_partial_success_emits_success_event(): void {
		$outbox_id = 4244;

		\do_action(
			'activitypub_sent_to_inbox',
			new \WP_Error( 'http_request_failed', 'transient' ),
			'https://bad.example/inbox',
			'{}',
			1,
			$outbox_id
		);
		\do_action(
			'activitypub_sent_to_inbox',
			array( 'response' => array( 'code' => 202 ) ),
			'https://good.example/inbox',
			'{}',
			1,
			$outbox_id
		);
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://bad.example/inbox', 'https://good.example/inbox' ), '{}', 1, $outbox_id, 2, 0 );

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'activitypub',
				'status'  => 'success',
			)
		);
	}

	/**
	 * AP outbox-complete with no prior per-inbox events emits nothing
	 * (zero-inbox dispatch). Uses a real outbox post id so the post-meta
	 * read path is exercised; the meta is just empty.
	 */
	public function test_ap_outbox_complete_without_sends_is_silent(): void {
		$outbox_id = \wp_insert_post(
			array(
				'post_title'  => 'outbox item',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		\do_action( 'activitypub_outbox_processing_complete', array(), '{}', 1, $outbox_id, 0, 0 );

		$this->assertNoEventRecorded( 'fosse_publish_result' );
	}

	/**
	 * Multi-batch AP dispatch — `activitypub_outbox_processing_batch_complete`
	 * fires per intermediate batch (flushing in-memory state to post
	 * meta), and `activitypub_outbox_processing_complete` fires for the
	 * final batch. Aggregation merges everything correctly.
	 *
	 * Verifies that after each intermediate `batch_complete`:
	 *   - persisted post meta carries the cumulative counts
	 *   - the in-memory slot for the outbox id is cleared
	 * so a re-fired batch in the same request can't double-count.
	 */
	public function test_ap_outbox_multi_batch_aggregation(): void {
		$outbox_id = \wp_insert_post(
			array(
				'post_title'  => 'outbox item',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// First batch — 1 fail, 2 successes — then batch_complete (intermediate).
		\do_action( 'activitypub_sent_to_inbox', new \WP_Error( '503', 'gateway' ), 'https://a.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://b.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://c.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_outbox_processing_batch_complete', array(), '{}', 1, $outbox_id, 3, 0 );

		// After the intermediate batch_complete, persisted meta carries the cumulative state.
		$persisted = \get_post_meta( $outbox_id, '_fosse_metrics_ap_dispatch_state', true );
		$this->assertIsArray( $persisted );
		$this->assertSame( 2, $persisted['successes'] );
		$this->assertSame( 1, $persisted['failures'] );
		$this->assertSame( '503', $persisted['first_failure_code'] );

		// Second batch — 1 success, 1 fail — then the FINAL batch fires processing_complete.
		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://d.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_sent_to_inbox', new \WP_Error( '429', 'slow down' ), 'https://e.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_outbox_processing_complete', array(), '{}', 1, $outbox_id, 2, 3 );

		// Cumulative: 3 successes total → status = success.
		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'activitypub',
				'status'  => 'success',
			)
		);

		// Post meta cleaned up after the final emit.
		$this->assertSame( '', \get_post_meta( $outbox_id, '_fosse_metrics_ap_dispatch_state', true ) );
	}

	/**
	 * Cross-request persistence — between intermediate batches the
	 * persisted state survives an in-memory reset (simulating the
	 * second cron request starting fresh). Locks in the property the
	 * post-meta flush is there to provide.
	 */
	public function test_ap_outbox_persisted_state_survives_request_boundary(): void {
		$outbox_id = \wp_insert_post(
			array(
				'post_title'  => 'outbox item',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Cron request 1: batch fires + batch_complete (state flushes to post meta).
		\do_action( 'activitypub_sent_to_inbox', new \WP_Error( '401', 'unauthorized' ), 'https://x.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://y.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_outbox_processing_batch_complete', array(), '{}', 1, $outbox_id, 2, 0 );

		// Simulate request boundary: a fresh PHP process has no in-memory state.
		// (The subscriber's flush already clears the slot, but be explicit.)
		$reflection = new \ReflectionClass( Publish_Events::class );
		$prop       = $reflection->getProperty( 'ap_dispatch_state' );
		$prop->setValue( null, array() );

		// Cron request 2: more inbox events + final processing_complete.
		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://z.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_outbox_processing_complete', array(), '{}', 1, $outbox_id, 1, 2 );

		// 2 cumulative successes (one from each request) + 1 failure across the boundary.
		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'activitypub',
				'status'  => 'success',
			)
		);
	}

	/**
	 * Repeated `register()` calls must not double-attach listeners.
	 * `add_action()` does not dedupe identical callbacks, so without
	 * the static guard a second call would cause every event to be
	 * recorded twice and every MC bump to fire twice.
	 */
	public function test_register_is_idempotent(): void {
		// setUp already called register() once. Call it twice more.
		Publish_Events::register();
		Publish_Events::register();

		$post_id = \wp_insert_post(
			array(
				'post_title'  => 'Idempotency post',
				'post_status' => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );
		\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );

		// Exactly one event each — not three.
		$this->assertCount( 1, $this->tracks_channel()->events_for( 'fosse_post_published' ) );
		$this->assertCount( 1, $this->tracks_channel()->events_for( 'fosse_publish_result' ) );

		$bumps = array_filter(
			$this->mc_channel()->bumps(),
			static fn ( $name ) => 'fosse-publish-success-bluesky' === $name
		);
		$this->assertCount( 1, $bumps, 'success bump fires exactly once even after repeated register() calls' );
	}

	/**
	 * Numeric HTTP status codes from AP's remote-POST WP_Error path
	 * classify into the documented `error_category` enum:
	 *   401/403 → auth_failed, 429 → rate_limited,
	 *   408/502/503/504 → network_timeout, others → other.
	 *
	 * AP wraps any HTTP `>= 400` response into a WP_Error keyed by the
	 * status code (see `bundled/activitypub/includes/class-http.php`),
	 * so these are the codes the subscriber actually sees in practice.
	 * Code `0` is intentionally not tested here — `empty( '0' )` is
	 * true in PHP and `WP_Error::__construct` drops it before
	 * `get_error_code()` could ever return `'0'`. The numeric `0`
	 * handling in `classify_error()` is defense-in-depth.
	 */
	public function test_ap_numeric_http_codes_classify_correctly(): void {
		$cases = array(
			array( '401', 'auth_failed' ),
			array( '403', 'auth_failed' ),
			array( '429', 'rate_limited' ),
			array( '408', 'network_timeout' ),
			array( '504', 'network_timeout' ),
			array( '502', 'network_timeout' ),
			array( '500', 'other' ),
			array( '404', 'other' ),
		);

		foreach ( $cases as $i => $case ) {
			[ $code, $expected ] = $case;

			$outbox_id = \wp_insert_post(
				array(
					'post_title'  => 'outbox-' . $i,
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			\do_action( 'activitypub_sent_to_inbox', new \WP_Error( $code, $code ), 'https://x.example/inbox', '{}', 1, $outbox_id );
			\do_action( 'activitypub_outbox_processing_complete', array( 'https://x.example/inbox' ), '{}', 1, $outbox_id, 1, 0 );
		}

		$publish_events = $this->tracks_channel()->events_for( 'fosse_publish_result' );
		$this->assertCount( \count( $cases ), $publish_events );

		foreach ( $cases as $i => $case ) {
			[ $code, $expected ] = $case;
			$this->assertSame(
				$expected,
				$publish_events[ $i ]['properties']['error_category'] ?? null,
				\sprintf( 'HTTP code %s should classify as %s', $code, $expected )
			);
		}
	}

	/**
	 * Atmosphere success path emits both events with strategy resolved
	 * to `short-form-note` when the post is short-form.
	 */
	public function test_atmosphere_success_short_form_emits_both_events(): void {
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Test post',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );

		$this->assertEventRecorded( 'fosse_post_published', array( 'has_image' => false ) );
		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network'  => 'bluesky',
				'status'   => 'success',
				'strategy' => 'short-form-note',
			)
		);
		$this->assertMcBumped( 'fosse-publish-success-bluesky' );
	}

	/**
	 * Long-form posts with `teaser-thread` composition map to
	 * `long-form-teaser-thread`.
	 */
	public function test_atmosphere_long_form_teaser_thread_strategy(): void {
		\add_filter( 'atmosphere_is_short_form_post', '__return_false' );
		\add_filter( 'atmosphere_long_form_composition', static fn () => 'teaser-thread' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Test post',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network'  => 'bluesky',
				'strategy' => 'long-form-teaser-thread',
			)
		);
	}

	/**
	 * Long-form posts with `link-card` composition collapse to
	 * `link-card-fallback`.
	 */
	public function test_atmosphere_long_form_link_card_strategy(): void {
		\add_filter( 'atmosphere_is_short_form_post', '__return_false' );
		\add_filter( 'atmosphere_long_form_composition', static fn () => 'link-card' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Test post',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network'  => 'bluesky',
				'strategy' => 'link-card-fallback',
			)
		);
	}

	/**
	 * Atmosphere failure with a WP_Error result emits a `failure`
	 * `fosse_publish_result` and skips the MC success bump.
	 */
	public function test_atmosphere_failure_emits_failure_event(): void {
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Test post',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\do_action(
			'atmosphere_publish_post_result',
			$post,
			new \WP_Error( 'rate_limited', 'slow down' )
		);

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network'        => 'bluesky',
				'status'         => 'failure',
				'error_category' => 'rate_limited',
			)
		);
		$this->assertNoMcBumped( 'fosse-publish-success-bluesky' );
	}

	/**
	 * Unknown `WP_Error` codes fall to `error_category: 'other'`
	 * rather than leaking the raw code into the payload.
	 */
	public function test_unknown_error_classifies_as_other(): void {
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Test post',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\do_action(
			'atmosphere_publish_post_result',
			$post,
			new \WP_Error( 'something_weird_we_dont_recognize', 'mystery' )
		);

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'error_category' => 'other',
			)
		);
	}

	/**
	 * Non-post argument is ignored — Atmosphere only ever fires this
	 * hook with a `WP_Post`, but the subscriber guards defensively so
	 * a misbehaving fork can't poison the recorder.
	 */
	public function test_non_post_argument_is_ignored(): void {
		\do_action( 'atmosphere_publish_post_result', new \stdClass(), array() );

		$this->assertNoEventRecorded( 'fosse_post_published' );
		$this->assertNoEventRecorded( 'fosse_publish_result' );
	}
}
