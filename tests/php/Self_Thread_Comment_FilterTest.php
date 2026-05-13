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
