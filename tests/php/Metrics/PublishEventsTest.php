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
		\remove_all_actions( 'activitypub_outbox_processing_complete' );
		\remove_all_actions( 'atmosphere_publish_post_result' );
		Publish_Events::register();
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
	 * (zero-inbox dispatch).
	 */
	public function test_ap_outbox_complete_without_sends_is_silent(): void {
		\do_action( 'activitypub_outbox_processing_complete', array(), '{}', 1, 9999, 0, 0 );

		$this->assertNoEventRecorded( 'fosse_publish_result' );
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
