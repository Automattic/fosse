<?php
/**
 * Tests for the self-thread comment suppressor.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Atmosphere\Transformer\Post as BskyPost;
use Automattic\Fosse\Self_Thread_Comment_Filter;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use WorDBless\BaseTestCase;

/**
 * Verifies the FOSSE callback on `atmosphere_should_sync_reply` suppresses
 * our own teaser-thread chunks but lets external replies and manually-
 * authored own-DID replies pass through.
 */
class Self_Thread_Comment_FilterTest extends BaseTestCase {

	private const OWN_DID   = 'did:plc:fosseown';
	private const POST_ID   = 4242;
	private const POST_URI  = 'at://did:plc:fosseown/app.bsky.feed.post/root';
	private const REPLY_URI = 'at://did:plc:fosseown/app.bsky.feed.post/chunk2';

	/**
	 * Reset hook + option + post-meta state and register the filter.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		remove_all_filters( 'atmosphere_should_sync_reply' );

		update_option(
			'atmosphere_connection',
			array(
				'did'          => self::OWN_DID,
				'access_token' => 'token',
			)
		);

		delete_post_meta( self::POST_ID, BskyPost::META_URI_INDEX );
		add_post_meta( self::POST_ID, BskyPost::META_URI_INDEX, self::POST_URI );
		add_post_meta( self::POST_ID, BskyPost::META_URI_INDEX, self::REPLY_URI );

		Self_Thread_Comment_Filter::register();
	}

	/**
	 * Own-DID reply whose URI is in the post's META_URI_INDEX is one of
	 * our teaser-thread chunks — suppress it.
	 */
	public function test_suppresses_own_chunk_in_thread_index(): void {
		$notification = $this->build_notification( self::OWN_DID, self::REPLY_URI );

		$this->assertFalse(
			apply_filters( 'atmosphere_should_sync_reply', true, $notification, self::POST_ID, 0 )
		);
	}

	/**
	 * Own-DID reply whose URI is NOT in the index — manually authored
	 * on bsky.app — passes through and syncs as a comment.
	 */
	public function test_allows_own_manual_reply_not_in_thread_index(): void {
		$manual_uri   = 'at://did:plc:fosseown/app.bsky.feed.post/manual-from-bsky-app';
		$notification = $this->build_notification( self::OWN_DID, $manual_uri );

		$this->assertTrue(
			apply_filters( 'atmosphere_should_sync_reply', true, $notification, self::POST_ID, 0 )
		);
	}

	/**
	 * External author always passes through, even if (improbably) their
	 * reply URI happens to be in the index.
	 */
	public function test_allows_external_author(): void {
		$notification = $this->build_notification( 'did:plc:stranger', self::REPLY_URI );

		$this->assertTrue(
			apply_filters( 'atmosphere_should_sync_reply', true, $notification, self::POST_ID, 0 )
		);
	}

	/**
	 * If Atmosphere has no DID connected (empty `atmosphere_connection`),
	 * we don't have a basis to identify "own" replies — pass through
	 * rather than risk false positives.
	 */
	public function test_passes_through_when_no_own_did(): void {
		delete_option( 'atmosphere_connection' );

		$notification = $this->build_notification( self::OWN_DID, self::REPLY_URI );

		$this->assertTrue(
			apply_filters( 'atmosphere_should_sync_reply', true, $notification, self::POST_ID, 0 )
		);
	}

	/**
	 * If a prior callback already returned false, stay false. Uses the
	 * exact inputs that would otherwise be suppressed (own DID + URI in
	 * thread index) to prove the early-return is what's keeping the
	 * value false, not the suppression path coincidentally also
	 * returning false.
	 */
	public function test_preserves_prior_false(): void {
		$notification = $this->build_notification( self::OWN_DID, self::REPLY_URI );

		$this->assertFalse(
			apply_filters( 'atmosphere_should_sync_reply', false, $notification, self::POST_ID, 0 )
		);
	}

	/**
	 * Posts published via single-record paths (e.g. short-form, link-card,
	 * truncate-link) never populate META_URI_INDEX. An inbound own-DID
	 * reply targeting one of those posts must pass through and sync, not
	 * be suppressed by an absent-but-empty-array check.
	 */
	public function test_allows_own_reply_when_post_has_no_thread_index(): void {
		delete_post_meta( self::POST_ID, BskyPost::META_URI_INDEX );

		$notification = $this->build_notification( self::OWN_DID, self::REPLY_URI );

		$this->assertTrue(
			apply_filters( 'atmosphere_should_sync_reply', true, $notification, self::POST_ID, 0 )
		);
	}

	/**
	 * A prior subscriber returning a non-bool (e.g. null) must not fatal.
	 * The callback's `$should` parameter is loosely typed: a scalar type
	 * hint would raise a TypeError even in coercive mode when fed null.
	 * Only an explicit `false` is honored as upstream suppression, so a
	 * null arriving alongside an own-thread chunk still suppresses
	 * because the own-thread evaluation fires.
	 */
	public function test_survives_null_upstream_should_for_own_thread_chunk(): void {
		add_filter( 'atmosphere_should_sync_reply', fn() => null, 5 );

		$notification = $this->build_notification( self::OWN_DID, self::REPLY_URI );

		$this->assertFalse(
			apply_filters( 'atmosphere_should_sync_reply', true, $notification, self::POST_ID, 0 ),
			'A null upstream still leads to suppression when the reply is an own-thread chunk.'
		);
	}

	/**
	 * A prior subscriber returning null for an EXTERNAL reply (different
	 * author DID, or own DID but URI not in our META_URI_INDEX) must NOT
	 * be coerced to a suppression decision. Coercing null to false would
	 * silently drop legitimate external replies whenever an earlier filter
	 * callback misbehaves — a noisy fatal is recoverable, a silent drop
	 * is not. Regression guard for the "null → false fast-return" fix.
	 */
	public function test_null_upstream_does_not_suppress_external_reply(): void {
		add_filter( 'atmosphere_should_sync_reply', fn() => null, 5 );

		// Different author DID (external reply, not our own thread chunk).
		$notification = $this->build_notification( 'did:plc:someoneelse', 'at://did:plc:someoneelse/app.bsky.feed.post/abc' );

		$this->assertTrue(
			apply_filters( 'atmosphere_should_sync_reply', true, $notification, self::POST_ID, 0 ),
			'A null upstream for an external reply must default to sync (true), not silently drop.'
		);
	}

	/**
	 * Scalar-falsy values from a prior callback (`0`, `'0'`, `''`) match
	 * WP filter convention for "suppress" and must continue to do so,
	 * even though `null` is exempted as "unknown". A site policy callback
	 * that derives suppression from a numeric or string flag relies on
	 * this — over-correcting to "only explicit boolean false suppresses"
	 * would silently start syncing replies the site tried to block.
	 *
	 * @dataProvider scalar_falsy_provider
	 *
	 * @param mixed $falsy A scalar-falsy value an upstream callback might return.
	 */
	#[DataProvider( 'scalar_falsy_provider' )]
	public function test_scalar_falsy_upstream_is_honored_as_suppression( $falsy ): void {
		add_filter( 'atmosphere_should_sync_reply', static fn() => $falsy, 5 );

		// External reply (different author) — would otherwise sync.
		$notification = $this->build_notification( 'did:plc:someoneelse', 'at://did:plc:someoneelse/app.bsky.feed.post/abc' );

		$this->assertFalse(
			apply_filters( 'atmosphere_should_sync_reply', true, $notification, self::POST_ID, 0 ),
			'Scalar-falsy upstream values must be treated as suppression, matching WP filter convention.'
		);
	}

	/**
	 * Scalar-falsy values an upstream callback might return.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function scalar_falsy_provider(): array {
		return array(
			'zero int'    => array( 0 ),
			'zero string' => array( '0' ),
			'empty string' => array( '' ),
			'literal false' => array( false ),
		);
	}

	/**
	 * Build a minimal `Reaction_Sync` reply notification for the filter to inspect.
	 *
	 * @param string $author_did Author DID to put on the notification.
	 * @param string $reply_uri  Reply record URI to put on the notification.
	 * @return array
	 */
	private function build_notification( string $author_did, string $reply_uri ): array {
		return array(
			'uri'    => $reply_uri,
			'author' => array( 'did' => $author_did ),
			'record' => array(
				'text'  => 'chunk body',
				'reply' => array(
					'parent' => array( 'uri' => self::POST_URI ),
					'root'   => array( 'uri' => self::POST_URI ),
				),
			),
		);
	}
}
