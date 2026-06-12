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
 * - `activitypub_sent_to_inbox` — accumulates per-inbox results in an
 *   in-memory aggregator (one update per inbox, no DB write per send).
 * - `activitypub_outbox_processing_batch_complete` — flushes the
 *   in-memory aggregate to post meta on the outbox item once per
 *   intermediate batch, so multi-batch dispatches that span cron
 *   requests stay coherent. AP fires this for every batch except the
 *   final one.
 * - `activitypub_outbox_processing_complete` — merges any final-batch
 *   in-memory state with the persisted aggregate, emits one
 *   `fosse_publish_result` event with `network: 'mastodon'`, and
 *   clears both. AP fires this only on the final batch — and only when
 *   the outbox item is a post `Create`. Updates, Deletes, comment
 *   activities, actor-profile Updates, and the dual-actor `Announce`
 *   are filtered out so each post counts at most once on the fediverse
 *   path (no double-count in `ACTIVITYPUB_ACTOR_AND_BLOG_MODE`).
 * - `atmosphere_publish_post_result` — emits one `fosse_post_published`
 *   plus one `fosse_publish_result` event with `network: 'bluesky'`. The
 *   upstream hook lands in wordpress-atmosphere PR 56; this subscriber
 *   pre-lands assuming it will land. If the hook never lands, the
 *   callback is dormant and emits nothing.
 *
 * The in-memory + per-batch-flush split keeps multi-batch correctness
 * (post meta survives cron-request boundaries) without paying the
 * ~`ACTIVITYPUB_OUTBOX_PROCESSING_BATCH_SIZE` extra DB writes per batch
 * a per-inbox flush would impose.
 *
 * For v1, `fosse_post_published` is only emitted from the Atmosphere
 * path, where the originating `WP_Post` is available directly. AP's
 * outbox dispatch only exposes the outbox-item id; mapping that back to
 * the originating post is a follow-up. Until that lands, AP-only sites
 * undercount the entry-step of the publish funnel — accept and document.
 */
class Publish_Events {

	/**
	 * Outbox-item post meta key for the cross-request dispatch aggregate.
	 *
	 * Stores `array{successes:int, failures:int, first_failure_code:?string}`
	 * keyed to the outbox item id. Flushed from `$ap_dispatch_state` on
	 * `activitypub_outbox_processing_batch_complete`, then read and
	 * deleted on `activitypub_outbox_processing_complete`.
	 */
	private const AP_DISPATCH_STATE_META_KEY = '_fosse_metrics_ap_dispatch_state';

	/**
	 * Per-request in-memory aggregator for AP outbox sends.
	 *
	 * Keyed by outbox item id. Updated on every `activitypub_sent_to_inbox`
	 * to avoid a DB write per inbox; flushed into post meta on each
	 * `activitypub_outbox_processing_batch_complete`, and merged with the
	 * persisted aggregate on `activitypub_outbox_processing_complete`.
	 *
	 * Stores only counts plus the first failure code (rather than every
	 * per-inbox failure entry) so the persisted post-meta payload stays
	 * bounded even on sites with very large follower sets and high
	 * failure rates. The emit only uses the first failure code anyway.
	 *
	 * @var array<int, array{successes:int, failures:int, first_failure_code:?string}>
	 */
	private static array $ap_dispatch_state = array();

	/**
	 * Cross-call guard against duplicate hook registration.
	 *
	 * `add_action()` does NOT dedupe identical callbacks — calling
	 * `register()` twice without this guard would attach each listener
	 * twice and double every event / bump.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Wire the subscriber to the publish hooks.
	 *
	 * Idempotent: the static `$registered` flag short-circuits repeat
	 * calls so duplicate listeners can't be attached. `add_action()`
	 * itself does not dedupe identical callbacks.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		\add_action( 'activitypub_sent_to_inbox', array( self::class, 'on_ap_sent_to_inbox' ), 10, 5 );
		\add_action( 'activitypub_outbox_processing_batch_complete', array( self::class, 'on_ap_outbox_processing_batch_complete' ), 10, 6 );
		\add_action( 'activitypub_outbox_processing_complete', array( self::class, 'on_ap_outbox_processing_complete' ), 10, 6 );
		\add_action( 'atmosphere_publish_post_result', array( self::class, 'on_atmosphere_publish_post_result' ), 10, 2 );
	}

	/**
	 * Accumulate per-inbox AP send results in the in-memory aggregator.
	 *
	 * No DB write here — flushed to post meta on
	 * `activitypub_outbox_processing_batch_complete` (intermediate
	 * batches) and merged into the emit on
	 * `activitypub_outbox_processing_complete` (final batch).
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
		if ( $outbox_item_id <= 0 ) {
			return;
		}

		if ( ! isset( self::$ap_dispatch_state[ $outbox_item_id ] ) ) {
			self::$ap_dispatch_state[ $outbox_item_id ] = self::empty_state();
		}

		if ( \is_wp_error( $result ) ) {
			++self::$ap_dispatch_state[ $outbox_item_id ]['failures'];
			if ( null === self::$ap_dispatch_state[ $outbox_item_id ]['first_failure_code'] ) {
				self::$ap_dispatch_state[ $outbox_item_id ]['first_failure_code'] = (string) $result->get_error_code();
			}
		} else {
			++self::$ap_dispatch_state[ $outbox_item_id ]['successes'];
		}
	}

	/**
	 * Empty dispatch-state record used as the initialization seed.
	 *
	 * @return array{successes:int, failures:int, first_failure_code:?string}
	 */
	private static function empty_state(): array {
		return array(
			'successes'          => 0,
			'failures'           => 0,
			'first_failure_code' => null,
		);
	}

	/**
	 * Flush the in-memory aggregator into post meta at the end of an
	 * intermediate batch.
	 *
	 * Fires for every batch except the final one (AP's
	 * `processing_batch_complete` and `processing_complete` are
	 * mutually exclusive — see
	 * `bundled/activitypub/includes/class-dispatcher.php`). Merges with
	 * whatever is already persisted so multi-batch dispatches that
	 * span cron requests accumulate correctly. Clears the in-memory
	 * slot after the flush so a re-fired batch in the same request
	 * doesn't double-count.
	 *
	 * @param array  $inboxes        Inbox list (unused).
	 * @param string $json           Activity JSON (unused).
	 * @param int    $actor_id       Sending actor (unused).
	 * @param int    $outbox_item_id Outbox item id — keys the per-dispatch aggregate.
	 * @param int    $batch_size     Batch size (unused).
	 * @param int    $offset         Batch offset (unused).
	 * @return void
	 */
	public static function on_ap_outbox_processing_batch_complete( $inboxes, $json, $actor_id, $outbox_item_id, $batch_size, $offset ) {
		unset( $inboxes, $json, $actor_id, $batch_size, $offset );

		$outbox_item_id = (int) $outbox_item_id;
		if ( $outbox_item_id <= 0 ) {
			return;
		}

		$in_memory = self::$ap_dispatch_state[ $outbox_item_id ] ?? null;
		unset( self::$ap_dispatch_state[ $outbox_item_id ] );

		if ( null === $in_memory ) {
			return;
		}

		$persisted = \get_post_meta( $outbox_item_id, self::AP_DISPATCH_STATE_META_KEY, true );
		if ( ! \is_array( $persisted ) ) {
			$persisted = self::empty_state();
		}

		\update_post_meta(
			$outbox_item_id,
			self::AP_DISPATCH_STATE_META_KEY,
			self::merge_states( $persisted, $in_memory )
		);
	}

	/**
	 * Combine two dispatch-state records, preserving the earliest
	 * `first_failure_code` (the persisted one wins when both have a
	 * value, matching the "first failure across the dispatch" semantics
	 * of the emit).
	 *
	 * @param array{successes:int, failures:int, first_failure_code:?string} $base    Existing state (e.g. persisted).
	 * @param array{successes:int, failures:int, first_failure_code:?string} $overlay State to merge in (e.g. in-memory).
	 * @return array{successes:int, failures:int, first_failure_code:?string}
	 */
	private static function merge_states( array $base, array $overlay ): array {
		$base['successes']         += $overlay['successes'];
		$base['failures']          += $overlay['failures'];
		$base['first_failure_code'] = $base['first_failure_code'] ?? $overlay['first_failure_code'];
		return $base;
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
		if ( $outbox_item_id <= 0 ) {
			return;
		}

		// The final batch's per-inbox events have only landed in memory —
		// AP's `processing_complete` and `processing_batch_complete` are
		// mutually exclusive, so the final batch never flushed.
		$in_memory = self::$ap_dispatch_state[ $outbox_item_id ] ?? null;
		unset( self::$ap_dispatch_state[ $outbox_item_id ] );

		$persisted = \get_post_meta( $outbox_item_id, self::AP_DISPATCH_STATE_META_KEY, true );
		\delete_post_meta( $outbox_item_id, self::AP_DISPATCH_STATE_META_KEY );

		// `activitypub_sent_to_inbox` accumulates for EVERY outbox dispatch
		// (post Creates, Updates, Deletes, comment activities, actor-profile
		// Updates, and the dual-actor `Announce`), so the in-memory and
		// persisted aggregate state is always cleared above — even for the
		// activities we do not emit on — to avoid leaking `_fosse_metrics_*`
		// post meta. The emit itself is gated below to a post `Create` only.
		if ( ! self::is_post_create_outbox_item( $outbox_item_id ) ) {
			return;
		}

		// Per-post idempotency: skip the emit when this source post has
		// already counted as a `fosse_publish_result`. Without the guard,
		// AP's resurrection path (a previously-deleted post becomes
		// publicly queryable again, scheduler enqueues another `Create`)
		// would double-count an already-recorded publish on every
		// resurrection.
		$source_post_id = self::source_post_id_for_outbox_item( $outbox_item_id );
		if ( $source_post_id > 0 && \get_post_meta( $source_post_id, self::AP_PUBLISH_RECORDED_META_KEY, true ) ) {
			return;
		}

		if ( null === $in_memory && ! \is_array( $persisted ) ) {
			// No `activitypub_sent_to_inbox` ever fired (zero-inbox publish).
			return;
		}

		$state = \is_array( $persisted ) ? $persisted : self::empty_state();
		if ( null !== $in_memory ) {
			$state = self::merge_states( $state, $in_memory );
		}

		$status = $state['successes'] > 0 ? 'success' : 'failure';

		$properties = array(
			'network' => 'mastodon',
			'status'  => $status,
		);

		if ( 'failure' === $status && null !== $state['first_failure_code'] ) {
			$properties['error_category'] = self::classify_error( $state['first_failure_code'] );
		}

		Recorder::record( 'fosse_publish_result', $properties );

		if ( 'success' === $status ) {
			Recorder::bump( 'fosse-publish-success-mastodon' );
		}

		// Idempotency marker is set after the emit so a re-fire of the
		// hook (cron retry, queue replay) won't have an early-exit win
		// when the original emit never recorded.
		if ( $source_post_id > 0 ) {
			\update_post_meta( $source_post_id, self::AP_PUBLISH_RECORDED_META_KEY, 1 );
		}
	}

	/**
	 * Whether an AP outbox item represents a first-class post `Create`.
	 *
	 * The `activitypub_outbox_processing_complete` hook fires for every
	 * outbox dispatch with no activity-type filter. Only a post `Create`
	 * should bump the publish funnel: Updates and Deletes are edits, not
	 * publishes; comment activities are reactions, not posts;
	 * actor-profile Updates are not content; and in
	 * `ACTIVITYPUB_ACTOR_AND_BLOG_MODE` the bundled scheduler enqueues a
	 * second `Announce` outbox item per post (see
	 * `bundled/activitypub/includes/class-scheduler.php`
	 * `schedule_announce_activity()`) which would double-count.
	 *
	 * Discriminator (both signals read from the outbox item itself, set by
	 * `bundled/activitypub/includes/collection/class-outbox.php` `add()`):
	 *
	 * 1. `_activitypub_activity_type` meta is the literal
	 *    `$activity->get_type()` — `'Create'` only passes; `'Update'`,
	 *    `'Delete'`, `'Announce'`, `'Like'`, etc. are rejected.
	 * 2. `_activitypub_object_id` meta is the activity object's canonical
	 *    URL. For a post Create this resolves back to a real post via
	 *    `url_to_postid()`; a comment Create's object URL does not, which
	 *    separates post Creates from comment Creates (both are `Create`s
	 *    of a `Note`, so the activity type alone cannot tell them apart).
	 *
	 * Reading the persisted meta is reliable here: `add()` always writes
	 * both keys, and the outbox item still exists at dispatch time (it is
	 * only `wp_publish_post()`-ed, never deleted, by the dispatcher).
	 *
	 * @param int $outbox_item_id Outbox item post id.
	 * @return bool
	 */
	private static function is_post_create_outbox_item( int $outbox_item_id ): bool {
		$activity_type = \get_post_meta( $outbox_item_id, '_activitypub_activity_type', true );
		if ( 'Create' !== $activity_type ) {
			return false;
		}

		$object_id = \get_post_meta( $outbox_item_id, '_activitypub_object_id', true );
		if ( ! \is_string( $object_id ) || '' === $object_id ) {
			return false;
		}

		return \url_to_postid( $object_id ) > 0;
	}

	/**
	 * Per-post idempotency marker for `fosse_publish_result` (Mastodon
	 * network).
	 *
	 * The bundled scheduler emits another `Create` outbox item when a
	 * previously-deleted/federated post becomes publicly queryable again
	 * (the AP "resurrection" path). Without a per-post guard the same
	 * source post would tick `fosse-publish-success-mastodon` twice —
	 * once on the original publish, once on every resurrection.
	 *
	 * Marker lives on the SOURCE post (not the outbox item) so it survives
	 * outbox cleanup, and is cleared on a hard delete of the source post
	 * (WordPress drops postmeta with the post). A republish after a hard
	 * delete reuses a fresh post ID and rightly counts as a new publish.
	 *
	 * @var string
	 */
	private const AP_PUBLISH_RECORDED_META_KEY = '_fosse_ap_publish_recorded';

	/**
	 * Resolve the source-post ID an AP outbox item's object URL points at.
	 *
	 * @param int $outbox_item_id Outbox item post id.
	 * @return int Post ID, or 0 when the object URL doesn't resolve.
	 */
	private static function source_post_id_for_outbox_item( int $outbox_item_id ): int {
		$object_id = \get_post_meta( $outbox_item_id, '_activitypub_object_id', true );
		if ( ! \is_string( $object_id ) || '' === $object_id ) {
			return 0;
		}
		return (int) \url_to_postid( $object_id );
	}

	/**
	 * WP_Error code Atmosphere uses for the not-publishable early return.
	 *
	 * `Publisher::publish_post()` fires `atmosphere_publish_post_result`
	 * with this code before any AT Protocol write happens (see
	 * `bundled/atmosphere/includes/class-publisher.php`). No publish
	 * occurred, so neither publish event should fire.
	 */
	private const ATMOSPHERE_NOT_PUBLISHABLE_CODE = 'atmosphere_post_not_publishable';

	/**
	 * The one Atmosphere context that is always a genuine first publish.
	 *
	 * Atmosphere's normal publish flow schedules an `atmosphere_publish_post`
	 * single event on publish (`bundled/atmosphere/includes/class-atmosphere.php`)
	 * whose cron callback runs `Publisher::publish_post()`. Every other
	 * context that reaches the result hook is a re-sync or re-publish of
	 * already-counted content:
	 *
	 * - The Backfill of pre-existing posts. Older Atmosphere runs it via
	 *   the `wp_ajax_atmosphere_backfill_batch` action; upstream trunk
	 *   replaced that with a WP-CLI command (`includes/cli/`) that calls
	 *   `Publisher::publish_post()` directly with no marker action at
	 *   all — which is why this gate is an allowlist on the publish cron
	 *   rather than a deny-list of known backfill contexts.
	 * - `atmosphere_update_post`, which only falls through to
	 *   `publish_post()` for the retry of an attempt whose result hook
	 *   already fired, or for `rewrite_thread()`'s delete-and-republish
	 *   of live records. Pristine posts take the
	 *   `atmosphere_update_skipped_unsynced_post` early return.
	 * - `atmosphere_delete_post`, which only reaches `publish_post()` on
	 *   its became-publishable-again reconcile branch.
	 *
	 * The funnel-entry `fosse_post_published` is therefore emitted only
	 * inside this action; `fosse_publish_result` still fires from every
	 * context (a real AT Protocol write occurred).
	 */
	private const ATMOSPHERE_PUBLISH_ACTION = 'atmosphere_publish_post';

	/**
	 * Handle the Atmosphere publish-result action.
	 *
	 * `atmosphere_publish_post_result` fires from several
	 * `Publisher::publish_post()` entry points
	 * (`bundled/atmosphere/includes/class-publisher.php`): the genuine
	 * first-publish cron path, the update-falls-through-to-publish retry,
	 * the `rewrite_thread()` delete-and-republish on shape-changing edits,
	 * the not-publishable early return, and the Backfill of pre-existing
	 * posts (AJAX in older Atmosphere, WP-CLI on upstream trunk). The raw
	 * hook does not discriminate between them.
	 *
	 * This subscriber discriminates two ways:
	 *
	 * - **Not publishable.** When `$result` is the
	 *   `atmosphere_post_not_publishable` WP_Error, no publish happened —
	 *   skip both events entirely.
	 * - **First-publish allowlist.** The funnel-entry `fosse_post_published`
	 *   is emitted only while the `atmosphere_publish_post` cron callback
	 *   runs — the one context that is always a genuine first publish (see
	 *   `ATMOSPHERE_PUBLISH_ACTION` for why backfills and update/delete
	 *   re-publishes, including upstream's action-less WP-CLI backfill,
	 *   are excluded by construction). `fosse_publish_result` still fires
	 *   from every context — it measures the render-quality outcome of an
	 *   actual AT Protocol write, which those contexts genuinely perform.
	 *
	 * Known gap: a previously-synced post that re-enters publication via
	 * the `atmosphere_publish_post` cron (unpublish → republish before
	 * the cleanup delete ran) is indistinguishable from a first publish
	 * at this hook and is counted again. That population is small and
	 * bounded, and the alternative — probing Atmosphere's private post
	 * meta — couples this subscriber to bundled internals.
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

		// Not-publishable early return: no AT Protocol write happened, so
		// nothing to record on either event.
		if ( $result instanceof \WP_Error && self::ATMOSPHERE_NOT_PUBLISHABLE_CODE === $result->get_error_code() ) {
			return;
		}

		// Only the publish cron is a genuine first publish — backfills
		// (AJAX or the action-less WP-CLI command) and update/delete-cron
		// re-publishes stay out of the funnel's entry step.
		if ( self::is_first_publish_context() ) {
			Recorder::record(
				'fosse_post_published',
				array(
					'post_format' => self::resolve_post_format( $post ),
					'has_image'   => self::resolve_has_image( $post ),
				)
			);
		}

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
	 * Whether the current Atmosphere result hook represents a first publish.
	 *
	 * True only while the `atmosphere_publish_post` cron callback runs.
	 * An allowlist, not a deny-list: upstream's WP-CLI backfill calls
	 * `Publisher::publish_post()` with no surrounding action at all, so
	 * enumerating re-publish contexts can never be complete (see
	 * `ATMOSPHERE_PUBLISH_ACTION`).
	 *
	 * @return bool
	 */
	private static function is_first_publish_context(): bool {
		return \doing_action( self::ATMOSPHERE_PUBLISH_ACTION );
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
		// `wp_validate_boolean()` (not a `(bool)` cast) mirrors Atmosphere's
		// own `is_short_form_post()` wrapper, so a filter returning the
		// string `'false'` classifies the same way upstream publishes.
		if ( \wp_validate_boolean( \apply_filters( 'atmosphere_is_short_form_post', self::is_short_form_shape( $post ), $post ) ) ) {
			return 'short-form-note';
		}

		$composition = (string) \apply_filters( 'atmosphere_long_form_composition', 'link-card', $post );
		return 'teaser-thread' === $composition ? 'long-form-teaser-thread' : 'link-card-fallback';
	}

	/**
	 * Replicate Atmosphere's shape-based short-form predicate as the
	 * `atmosphere_is_short_form_post` filter seed.
	 *
	 * The bundled Atmosphere transformer seeds the same filter with this
	 * exact predicate (see
	 * `bundled/atmosphere/includes/transformer/class-post.php`
	 * `is_short_form()`), so seeding with a hardcoded `false` here made
	 * the recorded `strategy` disagree with what Atmosphere actually
	 * published: titled posts carrying a post format, and titleless posts,
	 * are published short-form upstream but were being recorded as
	 * long-form. Mirroring the predicate keeps the metric honest when no
	 * other code has overridden the filter.
	 *
	 * Short-form when:
	 * - the post type does not support titles, OR
	 * - the post has an empty title, OR
	 * - the post has any non-empty post format.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function is_short_form_shape( \WP_Post $post ): bool {
		if ( ! \post_type_supports( $post->post_type, 'title' ) || empty( $post->post_title ) ) {
			return true;
		}

		return (bool) \get_post_format( $post );
	}

	/**
	 * Map a raw WP_Error code into one of the documented `error_category` values.
	 *
	 * The documented enum is `'auth_failed' | 'rate_limited' |
	 * 'network_timeout' | 'other'`. Unknown codes fall to `'other'`
	 * rather than leaking raw codes into the Tracks payload (privacy
	 * contract).
	 *
	 * AP's outbox dispatch surfaces remote-POST failures as `WP_Error`
	 * codes carrying the raw HTTP status as an integer-shaped string
	 * (e.g. `'401'`, `'429'`, `'504'`) — those are mapped into the
	 * appropriate bucket alongside the named string codes Atmosphere
	 * and the WP HTTP API emit. `0` covers a connection that never
	 * opened.
	 *
	 * @param string $code WP_Error code.
	 * @return string
	 */
	private static function classify_error( string $code ): string {
		$numeric_code = \ctype_digit( $code ) ? (int) $code : null;

		$auth_codes  = array(
			'unauthorized',
			'forbidden',
			'oauth_failed',
			'auth_failed',
			'token_expired',
			'atmosphere_no_credentials',
		);
		$auth_status = array( 401, 403, 407 );
		if ( \in_array( $code, $auth_codes, true ) || ( null !== $numeric_code && \in_array( $numeric_code, $auth_status, true ) ) ) {
			return 'auth_failed';
		}

		$rate_codes = array(
			'rate_limited',
			'too_many_requests',
		);
		if ( \in_array( $code, $rate_codes, true ) || 429 === $numeric_code ) {
			return 'rate_limited';
		}

		$timeout_codes  = array(
			'http_request_failed',
			'http_request_not_executed',
			'curl_error',
			'network_timeout',
			'connection_timeout',
		);
		$timeout_status = array( 0, 408, 502, 503, 504 );
		if ( \in_array( $code, $timeout_codes, true ) || ( null !== $numeric_code && \in_array( $numeric_code, $timeout_status, true ) ) ) {
			return 'network_timeout';
		}

		return 'other';
	}
}
