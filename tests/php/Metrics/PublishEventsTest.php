<?php
/**
 * Tests for Publish_Events.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Metrics;

use Automattic\Fosse\Metrics\Publish_Events;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
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
	 * Fire the Atmosphere result hook from inside the publish cron action.
	 *
	 * The funnel-entry gate allowlists `doing_action( 'atmosphere_publish_post' )`,
	 * so tests asserting `fosse_post_published` must deliver the result the
	 * way production does: from the publish cron callback.
	 *
	 * @param \WP_Post        $post   Post the result is for.
	 * @param array|\WP_Error $result Result payload.
	 * @return void
	 */
	private function fire_publish_result_from_publish_cron( \WP_Post $post, $result ): void {
		$relay = static function () use ( $post, $result ): void {
			\do_action( 'atmosphere_publish_post_result', $post, $result );
		};
		\add_action( 'atmosphere_publish_post', $relay );
		\do_action( 'atmosphere_publish_post' );
		\remove_action( 'atmosphere_publish_post', $relay );
	}

	/**
	 * Create an AP outbox item that looks like a post `Create` to the
	 * subscriber's discriminator.
	 *
	 * Mirrors what `Activitypub\Collection\Outbox::add()` persists for a
	 * post Create: `_activitypub_activity_type = 'Create'` and
	 * `_activitypub_object_id` set to the post's `?p=ID` permalink so
	 * `url_to_postid()` resolves it back to a real post.
	 *
	 * @param string $activity_type Optional activity type meta. Default `'Create'`.
	 * @param bool   $with_object   Optional. Whether to set a resolvable object id. Default true.
	 * @return int Outbox item post id.
	 */
	private function make_outbox_item( string $activity_type = 'Create', bool $with_object = true ): int {
		$source_post = \wp_insert_post(
			array(
				'post_title'  => 'Source post',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$outbox_id = \wp_insert_post(
			array(
				'post_title'  => '[' . $activity_type . '] outbox item',
				'post_status' => 'pending',
				'post_type'   => 'post',
			)
		);

		\update_post_meta( $outbox_id, '_activitypub_activity_type', $activity_type );
		if ( $with_object ) {
			\update_post_meta( $outbox_id, '_activitypub_object_id', \add_query_arg( 'p', $source_post, \home_url( '/' ) ) );
		}

		return $outbox_id;
	}

	/**
	 * AP dispatch with at least one successful inbox emits a
	 * `success` `fosse_publish_result` and bumps the MC counter.
	 */
	/**
	 * AP's scheduler emits another `Create` outbox item when a previously
	 * deleted/federated post becomes publicly queryable again. Without
	 * per-source-post idempotency, the same WP post would tick
	 * `fosse-publish-success-mastodon` on every resurrection — defeating
	 * the at-most-once metric goal. Regression guard pinning the
	 * first-publish-only semantic.
	 */
	public function test_ap_outbox_does_not_double_count_resurrection_of_same_post(): void {
		// First Create / publish.
		$first_outbox = $this->make_outbox_item();

		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://example.com/inbox', '{}', 1, $first_outbox );
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://example.com/inbox' ), '{}', 1, $first_outbox, 1, 0 );

		$this->reset_metrics_channels();

		// Resurrection: a SECOND `Create` outbox item for the SAME source
		// post (same `_activitypub_object_id` URL). Bundled scheduler
		// emits this on deleted-then-publishable-again transitions.
		$source_post_id      = (int) \url_to_postid( \get_post_meta( $first_outbox, '_activitypub_object_id', true ) );
		$resurrection_outbox = \wp_insert_post(
			array(
				'post_title'  => '[Create] resurrection outbox item',
				'post_status' => 'pending',
				'post_type'   => 'post',
			)
		);
		\update_post_meta( $resurrection_outbox, '_activitypub_activity_type', 'Create' );
		\update_post_meta( $resurrection_outbox, '_activitypub_object_id', \add_query_arg( 'p', $source_post_id, \home_url( '/' ) ) );

		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://example.com/inbox', '{}', 1, $resurrection_outbox );
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://example.com/inbox' ), '{}', 1, $resurrection_outbox, 1, 0 );

		// Resurrection must NOT emit another publish-result or bump the
		// success counter for this post.
		$this->assertNoEventRecorded( 'fosse_publish_result' );
		$this->assertNoMcBumped( 'fosse-publish-success-mastodon' );
	}

	public function test_ap_outbox_success_emits_success_event(): void {
		$outbox_id = $this->make_outbox_item();

		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://example.com/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://example.com/inbox' ), '{}', 1, $outbox_id, 1, 0 );

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'mastodon',
				'status'  => 'success',
			)
		);
		$this->assertMcBumped( 'fosse-publish-success-mastodon' );
	}

	/**
	 * AP dispatch where every inbox returned a WP_Error emits a
	 * `failure` event with the first failure code classified into the
	 * `error_category` enum, and does NOT bump the success counter.
	 */
	public function test_ap_outbox_all_failures_emits_failure_event(): void {
		$outbox_id = $this->make_outbox_item();

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
				'network'        => 'mastodon',
				'status'         => 'failure',
				'error_category' => 'auth_failed',
			)
		);
		$this->assertNoMcBumped( 'fosse-publish-success-mastodon' );
	}

	/**
	 * Mixed AP results count as success — a single bad inbox in a
	 * multi-inbox dispatch shouldn't downgrade the whole publish.
	 */
	public function test_ap_outbox_partial_success_emits_success_event(): void {
		$outbox_id = $this->make_outbox_item();

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
				'network' => 'mastodon',
				'status'  => 'success',
			)
		);
	}

	/**
	 * AP outbox-complete with no prior per-inbox events emits nothing
	 * (zero-inbox dispatch). Uses a real post id so the post-meta
	 * read path is exercised; the meta is just empty.
	 */
	public function test_ap_outbox_complete_without_sends_is_silent(): void {
		$outbox_id = $this->make_outbox_item();

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
		$outbox_id = $this->make_outbox_item();

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

		// And the in-memory slot for this outbox id is cleared, so a re-fired
		// batch in the same request can't double-count.
		$state_prop = ( new \ReflectionClass( Publish_Events::class ) )->getProperty( 'ap_dispatch_state' );
		$this->assertArrayNotHasKey( $outbox_id, $state_prop->getValue() );

		// Second batch — 1 success, 1 fail — then the FINAL batch fires processing_complete.
		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://d.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_sent_to_inbox', new \WP_Error( '429', 'slow down' ), 'https://e.example/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_outbox_processing_complete', array(), '{}', 1, $outbox_id, 2, 3 );

		// Cumulative: 3 successes total → status = success.
		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'mastodon',
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
		$outbox_id = $this->make_outbox_item();

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
				'network' => 'mastodon',
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
		$this->fire_publish_result_from_publish_cron( $post, array( 'commit' => array( 'cid' => 'baf' ) ) );

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

			$outbox_id = $this->make_outbox_item();

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

		$this->fire_publish_result_from_publish_cron( $post, array( 'commit' => array( 'cid' => 'baf' ) ) );

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

	/**
	 * A non-`Create` AP outbox dispatch (Update / Delete / actor-profile
	 * Update) emits nothing on the fediverse path — only a post Create
	 * counts toward the publish funnel.
	 *
	 * @param string $activity_type Activity type meta on the outbox item.
	 *
	 * @dataProvider provide_non_create_activity_types
	 */
	#[DataProvider( 'provide_non_create_activity_types' )]
	public function test_ap_non_create_activity_is_silent( string $activity_type ): void {
		$outbox_id = $this->make_outbox_item( $activity_type );

		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://example.com/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://example.com/inbox' ), '{}', 1, $outbox_id, 1, 0 );

		$this->assertNoEventRecorded( 'fosse_publish_result' );
		$this->assertNoMcBumped( 'fosse-publish-success-mastodon' );

		// The aggregate post meta is still cleaned up so non-Create
		// dispatches don't leak `_fosse_metrics_*` meta.
		$this->assertSame( '', \get_post_meta( $outbox_id, '_fosse_metrics_ap_dispatch_state', true ) );
	}

	/**
	 * Activity types that must NOT bump the publish funnel.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_non_create_activity_types(): array {
		return array(
			'post/actor update' => array( 'Update' ),
			'delete'            => array( 'Delete' ),
			'announce'          => array( 'Announce' ),
			'like'              => array( 'Like' ),
		);
	}

	/**
	 * In `ACTIVITYPUB_ACTOR_AND_BLOG_MODE` the bundled scheduler enqueues
	 * a second `Announce` outbox item per post. The subscriber must count
	 * the post once: the `Create` emits, the `Announce` does not — so a
	 * post federated in dual-actor mode yields exactly one
	 * `fosse_publish_result` / one MC bump, not two.
	 */
	public function test_ap_dual_actor_mode_does_not_double_count(): void {
		$create_item   = $this->make_outbox_item( 'Create' );
		$announce_item = $this->make_outbox_item( 'Announce' );

		// The user-actor post Create.
		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://example.com/inbox', '{}', 1, $create_item );
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://example.com/inbox' ), '{}', 1, $create_item, 1, 0 );

		// The blog-actor Announce of that same post.
		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://example.com/inbox', '{}', 1, $announce_item );
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://example.com/inbox' ), '{}', 1, $announce_item, 1, 0 );

		$this->assertCount( 1, $this->tracks_channel()->events_for( 'fosse_publish_result' ) );

		$bumps = \array_filter(
			$this->mc_channel()->bumps(),
			static fn ( $name ) => 'fosse-publish-success-mastodon' === $name
		);
		$this->assertCount( 1, $bumps );
	}

	/**
	 * A `Create` whose object URL does not resolve to a post (a comment
	 * Create — both are `Create`s of a `Note`) emits nothing on the
	 * fediverse path.
	 */
	public function test_ap_comment_create_is_silent(): void {
		// Create activity type, but the object id is a non-resolvable URL
		// (a comment's AP id), so url_to_postid() returns 0.
		$outbox_id = \wp_insert_post(
			array(
				'post_title'  => '[Create] comment',
				'post_status' => 'pending',
				'post_type'   => 'post',
			)
		);
		\update_post_meta( $outbox_id, '_activitypub_activity_type', 'Create' );
		\update_post_meta( $outbox_id, '_activitypub_object_id', 'https://remote.example/users/alice/statuses/42#comment' );

		\do_action( 'activitypub_sent_to_inbox', array( 'response' => array( 'code' => 202 ) ), 'https://example.com/inbox', '{}', 1, $outbox_id );
		\do_action( 'activitypub_outbox_processing_complete', array( 'https://example.com/inbox' ), '{}', 1, $outbox_id, 1, 0 );

		$this->assertNoEventRecorded( 'fosse_publish_result' );
	}

	/**
	 * Fix 2: the `atmosphere_is_short_form_post` filter is seeded with
	 * Atmosphere's own shape predicate, not a hardcoded `false`. A
	 * titleless post (short-form upstream) is recorded as
	 * `short-form-note` even when no filter overrides the seed — it was
	 * previously misrecorded as a long-form `link-card-fallback`.
	 */
	public function test_strategy_seed_matches_atmosphere_shape_for_titleless_post(): void {
		// No `atmosphere_is_short_form_post` filter is added — the seed
		// is what must classify this.
		$post_id = \wp_insert_post(
			array(
				'post_title'   => '',
				'post_content' => 'A quick note with no title.',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network'  => 'bluesky',
				'strategy' => 'short-form-note',
			)
		);
	}

	/**
	 * Fix 2: a titled post carrying a post format is short-form upstream
	 * (the post-format branch of Atmosphere's predicate), so the seed
	 * must classify it as `short-form-note` too.
	 */
	public function test_strategy_seed_treats_post_format_as_short_form(): void {
		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Has a title',
				'post_content' => 'But also a post format.',
				'post_status'  => 'publish',
			)
		);

		// WorDBless's database-less storage doesn't round-trip
		// `set_post_format()`, so feed the format through the same
		// `get_the_terms` path `get_post_format()` reads from.
		\add_filter(
			'get_the_terms',
			static function ( $terms, $object_id, $taxonomy ) use ( $post_id ) {
				if ( (int) $object_id === (int) $post_id && 'post_format' === $taxonomy ) {
					return array(
						(object) array(
							'slug'     => 'post-format-aside',
							'name'     => 'aside',
							'taxonomy' => 'post_format',
							'term_id'  => 999,
						),
					);
				}
				return $terms;
			},
			10,
			3
		);

		$post = \get_post( $post_id );

		\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );

		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network'  => 'bluesky',
				'strategy' => 'short-form-note',
			)
		);
	}

	/**
	 * Fix 2: a titled post with no post format is long-form upstream, so
	 * the seed leaves it on the long-form path (link-card fallback by
	 * default composition).
	 */
	public function test_strategy_seed_keeps_titled_plain_post_long_form(): void {
		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'A normal titled article',
				'post_content' => 'Long-form body.',
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
	 * Fix 3: the not-publishable early return fires
	 * `atmosphere_publish_post_result` with the
	 * `atmosphere_post_not_publishable` WP_Error before any AT Protocol
	 * write — neither publish event should fire.
	 */
	public function test_atmosphere_not_publishable_emits_nothing(): void {
		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Ineligible',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\do_action(
			'atmosphere_publish_post_result',
			$post,
			new \WP_Error( 'atmosphere_post_not_publishable', 'nope' )
		);

		$this->assertNoEventRecorded( 'fosse_post_published' );
		$this->assertNoEventRecorded( 'fosse_publish_result' );
		$this->assertNoMcBumped( 'fosse-publish-success-bluesky' );
	}

	/**
	 * Fix 3: a backfill run (the `wp_ajax_atmosphere_backfill_batch`
	 * action) re-syncs pre-existing posts. The funnel-entry
	 * `fosse_post_published` is suppressed, but `fosse_publish_result`
	 * still fires because a real AT Protocol write happened.
	 */
	public function test_atmosphere_backfill_suppresses_post_published_only(): void {
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Backfilled post',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		// Run the publish-result hook from inside the backfill AJAX action,
		// the way Backfill::handle_batch() → Publisher::publish_post() does.
		\add_action(
			'wp_ajax_atmosphere_backfill_batch',
			function () use ( $post ) {
				\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );
			}
		);
		\do_action( 'wp_ajax_atmosphere_backfill_batch' );

		$this->assertNoEventRecorded( 'fosse_post_published' );
		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'bluesky',
				'status'  => 'success',
			)
		);
	}

	/**
	 * Fix 3: every `Publisher::publish_post()` reached from inside the
	 * `atmosphere_update_post` / `atmosphere_delete_post` cron callbacks
	 * is a re-publish — the retry of an already-counted attempt or the
	 * `rewrite_thread()` delete-and-republish of live records on a
	 * shape-changing edit. The funnel-entry `fosse_post_published` is
	 * suppressed; `fosse_publish_result` still fires because a real AT
	 * Protocol write happened.
	 *
	 * @param string $cron_action Atmosphere cron action wrapping the publish.
	 *
	 * @dataProvider provide_republish_cron_actions
	 */
	#[DataProvider( 'provide_republish_cron_actions' )]
	public function test_atmosphere_republish_cron_suppresses_post_published_only( string $cron_action ): void {
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Edited post republished via ' . $cron_action,
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		// Fire the publish-result hook from inside the cron action, the way
		// the bundled Atmosphere cron callbacks → Publisher::update_post()
		// → publish_post() / rewrite_thread() do.
		\add_action(
			$cron_action,
			function () use ( $post ) {
				\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );
			}
		);
		\do_action( $cron_action );

		$this->assertNoEventRecorded( 'fosse_post_published' );
		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'bluesky',
				'status'  => 'success',
			)
		);
	}

	/**
	 * Cron actions whose `publish_post()` invocations are always re-publishes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_republish_cron_actions(): array {
		return array(
			'update cron (retry / rewrite_thread)' => array( 'atmosphere_update_post' ),
			'delete cron (publishable reconcile)'  => array( 'atmosphere_delete_post' ),
		);
	}

	/**
	 * Upstream trunk's WP-CLI backfill calls `Publisher::publish_post()`
	 * directly, with no surrounding action at all. The first-publish gate
	 * is an allowlist on the `atmosphere_publish_post` cron precisely so
	 * this action-less context stays out of the funnel entry — while the
	 * result event (a real AT Protocol write) still records.
	 */
	public function test_atmosphere_actionless_backfill_suppresses_post_published_only(): void {
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Historical post',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		// Bare invocation — exactly what the CLI backfill produces.
		\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );

		$this->assertNoEventRecorded( 'fosse_post_published' );
		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'bluesky',
				'status'  => 'success',
			)
		);
	}

	/**
	 * The genuine first-publish cron (`atmosphere_publish_post`) is NOT
	 * suppressed — the funnel entry must still be recorded there.
	 */
	public function test_atmosphere_first_publish_cron_records_post_published(): void {
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Fresh post',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\add_action(
			'atmosphere_publish_post',
			function () use ( $post ) {
				\do_action( 'atmosphere_publish_post_result', $post, array( 'commit' => array( 'cid' => 'baf' ) ) );
			}
		);
		\do_action( 'atmosphere_publish_post' );

		$this->assertEventRecorded( 'fosse_post_published', array( 'has_image' => false ) );
		$this->assertEventRecorded(
			'fosse_publish_result',
			array(
				'network' => 'bluesky',
				'status'  => 'success',
			)
		);
	}

	/**
	 * Fix 2 parity detail: the short-form filter result is coerced with
	 * `wp_validate_boolean()`, mirroring Atmosphere's `is_short_form_post()`
	 * wrapper. A filter returning the string `'false'` is falsy upstream
	 * (long-form publish), so the recorded strategy must be long-form too —
	 * a plain `(bool)` cast would misrecord it as `short-form-note`.
	 */
	public function test_strategy_filter_string_false_is_long_form(): void {
		\add_filter( 'atmosphere_is_short_form_post', static fn () => 'false' );

		$post_id = \wp_insert_post(
			array(
				'post_title'   => '',
				'post_content' => 'Titleless, but the filter says long-form.',
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
}
