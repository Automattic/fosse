<?php
/**
 * Publish-event metrics subscriber.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Metrics;

/**
 * Translates bundled ActivityPub + bundled Atmosphere publish hooks into
 * the `fosse_post_published` and `fosse_publish_result` events documented
 * in `sdd/fosse-metrics-strategy/implementation.md` § Publishing.
 *
 * Listens to:
 *
 * - `activitypub_sent_to_inbox` — accumulates per-inbox results indexed by
 *   the outbox item id.
 * - `activitypub_outbox_processing_complete` — emits one aggregated
 *   `fosse_publish_result` event with `network: 'activitypub'`.
 * - `atmosphere_publish_post_result` — emits one `fosse_post_published`
 *   plus one `fosse_publish_result` event with `network: 'bluesky'`. The
 *   upstream hook is being added in wordpress-atmosphere PR 56; this
 *   subscriber pre-lands assuming it will land. If the hook never lands,
 *   the callback is dormant and emits nothing.
 *
 * For v1, `fosse_post_published` is only emitted from the Atmosphere
 * path, where the originating `WP_Post` is available directly. AP's
 * outbox dispatch only exposes the outbox-item id; mapping that back to
 * the originating post is a follow-up. Until that lands, AP-only sites
 * undercount the entry-step of the publish funnel — accept and document.
 */
class Publish_Events {

	/**
	 * Per-request state of in-flight AP outbox dispatches.
	 *
	 * Keyed by outbox item id. Each entry tracks success count and
	 * the WP_Error codes collected from per-inbox failures, so the
	 * `activitypub_outbox_processing_complete` handler can emit a
	 * single aggregated `fosse_publish_result` event.
	 *
	 * @var array<int, array{successes:int, failures:list<string>}>
	 */
	private static array $ap_dispatch_state = array();

	/**
	 * Wire the subscriber to the publish hooks.
	 *
	 * Idempotent — repeated calls re-add the same listeners but
	 * WordPress dedupes them by callback identity.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'activitypub_sent_to_inbox', array( self::class, 'on_ap_sent_to_inbox' ), 10, 5 );
		\add_action( 'activitypub_outbox_processing_complete', array( self::class, 'on_ap_outbox_processing_complete' ), 10, 6 );
		\add_action( 'atmosphere_publish_post_result', array( self::class, 'on_atmosphere_publish_post_result' ), 10, 2 );
	}

	/**
	 * Accumulate per-inbox AP send results for later aggregation.
	 *
	 * @param array|\WP_Error $result         Result of the inbox send.
	 * @param string          $inbox          Inbox URL (unused here).
	 * @param string          $json           Activity JSON (unused here).
	 * @param int             $actor_id       Sending actor (unused here).
	 * @param int             $outbox_item_id Outbox item id — keys the per-dispatch aggregate.
	 * @return void
	 */
	public static function on_ap_sent_to_inbox( $result, $inbox, $json, $actor_id, $outbox_item_id ) {
		unset( $inbox, $json, $actor_id );

		$outbox_item_id = (int) $outbox_item_id;
		if ( ! isset( self::$ap_dispatch_state[ $outbox_item_id ] ) ) {
			self::$ap_dispatch_state[ $outbox_item_id ] = array(
				'successes' => 0,
				'failures'  => array(),
			);
		}

		if ( \is_wp_error( $result ) ) {
			self::$ap_dispatch_state[ $outbox_item_id ]['failures'][] = $result->get_error_code();
		} else {
			++self::$ap_dispatch_state[ $outbox_item_id ]['successes'];
		}
	}

	/**
	 * Emit the aggregated `fosse_publish_result` for an AP outbox item.
	 *
	 * Status policy: success if at least one inbox accepted the
	 * activity; otherwise failure. Matches "did this post reach the
	 * fediverse at all?" rather than "did every inbox succeed" — a
	 * single follower's malformed inbox shouldn't downgrade the
	 * post-level publish status.
	 *
	 * @param array  $inboxes        Inbox list (unused).
	 * @param string $json           Activity JSON (unused).
	 * @param int    $actor_id       Sending actor (unused).
	 * @param int    $outbox_item_id Outbox item id — looked up in the per-dispatch aggregate.
	 * @param int    $batch_size     Batch size (unused).
	 * @param int    $offset         Batch offset (unused).
	 * @return void
	 */
	public static function on_ap_outbox_processing_complete( $inboxes, $json, $actor_id, $outbox_item_id, $batch_size, $offset ) {
		unset( $inboxes, $json, $actor_id, $batch_size, $offset );

		$outbox_item_id = (int) $outbox_item_id;
		$state          = self::$ap_dispatch_state[ $outbox_item_id ] ?? null;
		unset( self::$ap_dispatch_state[ $outbox_item_id ] );

		if ( null === $state ) {
			// No `activitypub_sent_to_inbox` ever fired for this id (zero-inbox publish).
			// Don't emit — there is no signal to report.
			return;
		}

		$status = $state['successes'] > 0 ? 'success' : 'failure';

		$properties = array(
			'network' => 'activitypub',
			'status'  => $status,
		);

		if ( 'failure' === $status && ! empty( $state['failures'] ) ) {
			$properties['error_category'] = self::classify_error( $state['failures'][0] );
		}

		Recorder::record( 'fosse_publish_result', $properties );

		if ( 'success' === $status ) {
			Recorder::bump( 'fosse-publish-success-activitypub' );
		}
	}

	/**
	 * Handle the Atmosphere publish-result action.
	 *
	 * Emits `fosse_post_published` once (network-agnostic entry-step
	 * signal) plus `fosse_publish_result` once with `network: 'bluesky'`.
	 *
	 * @param \WP_Post        $post   Post that was published.
	 * @param array|\WP_Error $result `applyWrites` response on success, `WP_Error` on failure.
	 * @return void
	 */
	public static function on_atmosphere_publish_post_result( $post, $result ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		Recorder::record(
			'fosse_post_published',
			array(
				'post_format' => self::resolve_post_format( $post ),
				'has_image'   => self::resolve_has_image( $post ),
			)
		);

		$status = \is_wp_error( $result ) ? 'failure' : 'success';

		$properties = array(
			'network'  => 'bluesky',
			'status'   => $status,
			'strategy' => self::resolve_strategy( $post ),
		);

		if ( 'failure' === $status && $result instanceof \WP_Error ) {
			$properties['error_category'] = self::classify_error( (string) $result->get_error_code() );
		}

		Recorder::record( 'fosse_publish_result', $properties );

		if ( 'success' === $status ) {
			Recorder::bump( 'fosse-publish-success-bluesky' );
		}
	}

	/**
	 * Resolve the post format property for `fosse_post_published`.
	 *
	 * Normalizes a missing format to `'standard'` so the dimension
	 * is always populated rather than dropping into a null bucket.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function resolve_post_format( \WP_Post $post ): string {
		$format = \get_post_format( $post->ID );
		return false === $format || '' === $format ? 'standard' : (string) $format;
	}

	/**
	 * Resolve the `has_image` boolean for `fosse_post_published`.
	 *
	 * Featured-image-only for v1 — keeps the signal cheap and avoids
	 * parsing post content. Inline-image detection is a follow-up.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function resolve_has_image( \WP_Post $post ): bool {
		return \has_post_thumbnail( $post->ID );
	}

	/**
	 * Resolve the `strategy` enum for `fosse_publish_result` (Bluesky network).
	 *
	 * Maps Atmosphere's classification onto the three spec-pinned
	 * values: `'short-form-note'`, `'long-form-teaser-thread'`,
	 * `'link-card-fallback'`. Atmosphere's `truncate-link` long-form
	 * strategy collapses to `'link-card-fallback'` for v1 — both
	 * render as a single record with a link to the source post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string One of the documented strategy enum values.
	 */
	private static function resolve_strategy( \WP_Post $post ): string {
		if ( (bool) \apply_filters( 'atmosphere_is_short_form_post', false, $post ) ) {
			return 'short-form-note';
		}

		$composition = (string) \apply_filters( 'atmosphere_long_form_composition', 'link-card' );
		return 'teaser-thread' === $composition ? 'long-form-teaser-thread' : 'link-card-fallback';
	}

	/**
	 * Map a raw WP_Error code into one of the documented `error_category` values.
	 *
	 * The documented enum is `'auth_failed' | 'rate_limited' |
	 * 'network_timeout' | 'other'`. Unknown codes fall to `'other'`
	 * rather than leaking raw codes into the Tracks payload (privacy
	 * contract).
	 *
	 * @param string $code WP_Error code.
	 * @return string
	 */
	private static function classify_error( string $code ): string {
		$auth_codes = array(
			'unauthorized',
			'forbidden',
			'oauth_failed',
			'auth_failed',
			'token_expired',
			'atmosphere_no_credentials',
		);
		if ( \in_array( $code, $auth_codes, true ) ) {
			return 'auth_failed';
		}

		$rate_codes = array(
			'rate_limited',
			'too_many_requests',
		);
		if ( \in_array( $code, $rate_codes, true ) ) {
			return 'rate_limited';
		}

		$timeout_codes = array(
			'http_request_failed',
			'http_request_not_executed',
			'curl_error',
			'network_timeout',
			'connection_timeout',
		);
		if ( \in_array( $code, $timeout_codes, true ) ) {
			return 'network_timeout';
		}

		return 'other';
	}
}
