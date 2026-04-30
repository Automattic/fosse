<?php
/**
 * Plugin Name: FOSSE e2e Reactions Seed
 * Description: Test-only helper. Exposes a REST endpoint that, on POST,
 *   inserts a published post containing the activitypub/reactions block
 *   plus three pre-approved comment rows split across protocols
 *   (one ActivityPub like, one Bluesky like, one Bluesky repost) so the
 *   reactions-display Playwright spec can assert the unified-display
 *   behavior without standing up a live AP federation or a real
 *   Bluesky reaction-sync poll. Copied into wp-content/mu-plugins/ by
 *   blueprint.json. The endpoint is gated on the FOSSE_E2E PHP constant
 *   set by the same blueprint, so dropping this file into a non-test
 *   site is inert.
 *
 * @package Automattic\Fosse\Tests\E2E
 */

defined( 'ABSPATH' ) || exit;

// Defense-in-depth: register nothing unless FOSSE_E2E is strictly true.
// `manage_options` already gates the endpoint, but a strict check rules
// out a future blueprint variant defining the constant as a truthy
// non-true value (e.g. `define('FOSSE_E2E', 'false')` — the string
// 'false' is truthy in PHP). When the constant is defined but rejected,
// a one-shot warning makes the test harness leave a breadcrumb instead
// of vanishing into a 404.
if ( ! defined( 'FOSSE_E2E' ) ) {
	return;
}
if ( true !== FOSSE_E2E ) {
	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_var_export
	\error_log(
		sprintf(
			'[fosse-e2e/seed-reactions] FOSSE_E2E is defined but not strictly (bool) true (got %s); seed endpoint not registered.',
			\var_export( FOSSE_E2E, true )
		)
	);
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_var_export
	return;
}

const FOSSE_E2E_SEED_POST_TITLE = 'FOSSE e2e Reactions test post';
const FOSSE_E2E_SEED_META_KEY   = '_fosse_e2e_seed';

add_action(
	'rest_api_init',
	static function (): void {
		\register_rest_route(
			'fosse-e2e/v1',
			'/seed-reactions',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return \current_user_can( 'manage_options' );
				},
				'callback'            => static function () {
					try {
						$post_id = fosse_e2e_upsert_seed_post();
						if ( \is_wp_error( $post_id ) ) {
							return $post_id;
						}

						$comment_ids = fosse_e2e_seed_reaction_comments( $post_id );
						if ( \is_wp_error( $comment_ids ) ) {
							return $comment_ids;
						}

						return \rest_ensure_response(
							array(
								'ok'          => true,
								'post_id'     => $post_id,
								'post_url'    => \get_permalink( $post_id ),
								'comment_ids' => $comment_ids,
							)
						);
					} catch ( \Throwable $e ) {
						$location = sprintf( '%s:%d', $e->getFile(), $e->getLine() );
						\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							sprintf(
								'[fosse-e2e/seed-reactions] %s: %s @ %s',
								get_class( $e ),
								$e->getMessage(),
								$location
							)
						);
						return new \WP_Error(
							'fosse_e2e_seed_error',
							sprintf( '%s: %s @ %s', get_class( $e ), $e->getMessage(), $location ),
							array( 'status' => 500 )
						);
					}
				},
			)
		);
	}
);

/**
 * Insert (or reuse) the seed post. Reusing on re-run keeps the spec
 * idempotent — Playwright retries or `--repeat-each` won't pile up
 * additional posts and break count assertions.
 *
 * The post body embeds the `activitypub/reactions` block explicitly
 * so the spec exercises a user-inserted block, not whatever AP's
 * `blockHooks` happens to auto-inject this release.
 *
 * Cleanup is scoped via the FOSSE_E2E_SEED_META_KEY post-meta marker
 * (and the matching comment-meta marker on each seeded comment) so
 * we never delete unrelated comments off a post that happens to
 * collide on title.
 *
 * @return int|\WP_Error
 */
function fosse_e2e_upsert_seed_post() {
	global $wpdb;

	$existing = \get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'title'          => FOSSE_E2E_SEED_POST_TITLE,
			'meta_key'       => FOSSE_E2E_SEED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	// `get_posts()` returns `array()` for both "no rows" and "query
	// failed" — without this `last_error` check the second case
	// silently flows into `wp_insert_post`, accumulating duplicate
	// seed posts across runs.
	if ( ! empty( $wpdb->last_error ) ) {
		return new \WP_Error(
			'fosse_e2e_seed_post_lookup_failed',
			'Existing-post lookup failed: ' . $wpdb->last_error,
			array( 'status' => 500 )
		);
	}

	if ( ! empty( $existing ) ) {
		$existing_id = (int) $existing[0];
		// Wipe ONLY the prior seed-marked comments. Anything else on
		// the post (real AP/AT comments that landed here in a long-
		// running Playground session, hand-added test fixtures) stays.
		$prior = \get_comments(
			array(
				'post_id'    => $existing_id,
				'meta_key'   => FOSSE_E2E_SEED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ids',
				'status'     => 'any',
			)
		);
		foreach ( $prior as $cid ) {
			$deleted = \wp_delete_comment( (int) $cid, true );
			if ( ! $deleted ) {
				return new \WP_Error(
					'fosse_e2e_seed_cleanup_failed',
					sprintf( 'wp_delete_comment(%d) returned false during seed cleanup.', $cid ),
					array( 'status' => 500 )
				);
			}
		}
		return $existing_id;
	}

	$post_id = \wp_insert_post(
		array(
			'post_title'   => FOSSE_E2E_SEED_POST_TITLE,
			'post_content' => "<!-- wp:paragraph -->\n<p>Reactions seed body.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:activitypub/reactions /-->",
			'post_status'  => 'publish',
			'post_type'    => 'post',
		),
		true
	);

	if ( \is_wp_error( $post_id ) ) {
		return new \WP_Error(
			'fosse_e2e_seed_post_failed',
			'wp_insert_post failed: ' . $post_id->get_error_message(),
			array( 'status' => 500 )
		);
	}
	if ( ! $post_id ) {
		return new \WP_Error(
			'fosse_e2e_seed_post_failed',
			'wp_insert_post returned 0.',
			array( 'status' => 500 )
		);
	}

	\update_post_meta( (int) $post_id, FOSSE_E2E_SEED_META_KEY, '1' );

	return (int) $post_id;
}

/**
 * Seed three reaction comments — one AP like, one AT like, one AT
 * repost — onto the given post. Fails fast with a `WP_Error` naming
 * the offending spec on the first comment-insert or meta-write
 * failure, so Playwright sees the real cause instead of a
 * downstream count-mismatch assertion.
 *
 * @param int $post_id Target post.
 * @return array<int>|\WP_Error Inserted comment IDs, in spec order.
 */
function fosse_e2e_seed_reaction_comments( int $post_id ) {
	$specs = array(
		array(
			'comment_type' => 'like',
			'protocol'     => 'activitypub',
			'source_id'    => 'https://mastodon.example/users/alice/likes/1',
			'source_url'   => 'https://mastodon.example/users/alice',
			'author_name'  => 'Alice via Mastodon',
			'author_url'   => 'https://mastodon.example/@alice',
		),
		array(
			'comment_type' => 'like',
			'protocol'     => 'atproto',
			'source_id'    => 'at://did:plc:fossee2eseed/app.bsky.feed.like/abc',
			'source_url'   => 'https://bsky.app/profile/bob.bsky.social',
			'author_name'  => 'Bob via Bluesky',
			'author_url'   => 'https://bsky.app/profile/bob.bsky.social',
		),
		array(
			'comment_type' => 'repost',
			'protocol'     => 'atproto',
			'source_id'    => 'at://did:plc:fossee2eseed/app.bsky.feed.repost/xyz',
			'source_url'   => 'https://bsky.app/profile/carol.bsky.social',
			'author_name'  => 'Carol via Bluesky',
			'author_url'   => 'https://bsky.app/profile/carol.bsky.social',
		),
	);

	$comment_ids = array();
	foreach ( $specs as $spec ) {
		$cid = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_type'         => $spec['comment_type'],
				'comment_approved'     => 1,
				'comment_parent'       => 0,
				'comment_author'       => $spec['author_name'],
				'comment_author_url'   => $spec['author_url'],
				'comment_author_email' => '',
				'comment_content'      => '',
			)
		);

		if ( ! $cid ) {
			return new \WP_Error(
				'fosse_e2e_seed_comment_failed',
				sprintf(
					'wp_insert_comment failed for reaction spec "%s" (%s:%s).',
					$spec['author_name'],
					$spec['protocol'],
					$spec['source_id']
				),
				array( 'status' => 500 )
			);
		}

		// `update_comment_meta` returns the new meta id on insert and
		// `true` on update. `false` is the only failure signal — and it
		// also fires when an update is a no-op because the value didn't
		// change. New comments here always have no prior meta, so any
		// `false` return on this fresh-insert path is a real failure
		// worth surfacing.
		$meta_writes = array(
			'protocol'              => $spec['protocol'],
			'source_id'             => $spec['source_id'],
			'source_url'            => $spec['source_url'],
			FOSSE_E2E_SEED_META_KEY => '1',
		);
		foreach ( $meta_writes as $meta_key => $meta_value ) {
			if ( false === \update_comment_meta( $cid, $meta_key, $meta_value ) ) {
				return new \WP_Error(
					'fosse_e2e_seed_meta_failed',
					sprintf(
						'update_comment_meta(%d, "%s") failed for reaction spec "%s".',
						$cid,
						$meta_key,
						$spec['author_name']
					),
					array( 'status' => 500 )
				);
			}
		}

		$comment_ids[] = (int) $cid;
	}

	return $comment_ids;
}
