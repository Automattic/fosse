<?php
/**
 * Suppress our own teaser-thread chunks from being synced as comments.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

use Atmosphere\Transformer\Post as BskyPost;

/**
 * Registers a callback on `atmosphere_should_sync_reply` (upstream
 * Atmosphere) that returns false when an inbound reply is one of our
 * own teaser-thread chunks.
 *
 * Background: when Atmosphere's `Reaction_Sync` cron walks the connected
 * account's own records via `listRecords`, every teaser-thread chunk
 * appears with `value.reply` populated (each chunk replies to the prior
 * chunk / root). The chunk's URI is in the originating post's
 * `META_URI_INDEX`, which means `Reaction_Sync::find_post_by_bsky_uri()`
 * resolves it back to the post — and without this filter, the chunk
 * becomes a WordPress comment on the post (and on hosts that fire
 * downstream comment notifications, an out-of-band notification to the
 * author about replying to themself).
 *
 * The discriminator is tight: `META_URI_INDEX` is only populated by
 * Atmosphere's Publisher during a publish. A reply that targets one of
 * our posts but is NOT in the index is something the user wrote
 * deliberately (e.g. a manual reply on bsky.app), and we let it sync
 * through as a comment.
 */
class Self_Thread_Comment_Filter {

	/**
	 * Register the filter. Safe to call more than once per request —
	 * WordPress dedupes identical callable-as-array registrations.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_filter( 'atmosphere_should_sync_reply', array( self::class, 'suppress_own_thread_chunks' ), 10, 3 );
	}

	/**
	 * Return false when the inbound reply is one of our own teaser-thread
	 * chunks; otherwise pass through whatever value the upstream filter
	 * chain has produced.
	 *
	 * @param mixed $should       Default true (or whatever a prior callback returned).
	 *                            Loosely typed so a non-bool from an earlier
	 *                            callback (e.g. a buggy plugin returning null)
	 *                            doesn't fatal the request. Scalar-falsy values
	 *                            (`false`, `0`, `'0'`, `''`) are honored as a
	 *                            suppression decision, matching WP filter
	 *                            convention. Only `null` is treated as
	 *                            "unknown" and falls through to the own-thread
	 *                            evaluation — coercing it to false would
	 *                            silently drop legitimate external replies the
	 *                            moment any earlier callback misbehaves.
	 * @param array $notification Notification or synthesized own-record (must include
	 *                            `uri` and `author.did`).
	 * @param int   $post_id      Resolved WP post the reply targets.
	 * @return bool
	 */
	public static function suppress_own_thread_chunks( $should, array $notification, int $post_id ): bool {
		// Honor scalar-falsy as suppression (matches WP filter convention),
		// but treat `null` as "unknown" so a buggy upstream callback can't
		// silently drop external replies. A noisy fatal is recoverable; a
		// silent drop is not.
		if ( null !== $should && ! $should ) {
			return false;
		}

		$own_did = \Atmosphere\get_did();
		if ( '' === $own_did ) {
			return true;
		}

		$author_did = $notification['author']['did'] ?? '';
		$reply_uri  = $notification['uri'] ?? '';

		if ( $author_did !== $own_did || '' === $reply_uri ) {
			return true;
		}

		$thread_uris = \get_post_meta( $post_id, BskyPost::META_URI_INDEX, false );
		if ( ! \is_array( $thread_uris ) || ! \in_array( $reply_uri, $thread_uris, true ) ) {
			return true;
		}

		return false;
	}
}
