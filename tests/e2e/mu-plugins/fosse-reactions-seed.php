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
 *   blueprint.json.
 *
 * @package Automattic\Fosse\Tests\E2E
 */

defined( 'ABSPATH' ) || exit;

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
						$post_id = \wp_insert_post(
							array(
								'post_title'   => 'Reactions test post',
								'post_content' => "<!-- wp:paragraph -->\n<p>Reactions seed body.</p>\n<!-- /wp:paragraph -->",
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

							if ( $cid ) {
								\update_comment_meta( $cid, 'protocol', $spec['protocol'] );
								\update_comment_meta( $cid, 'source_id', $spec['source_id'] );
								\update_comment_meta( $cid, 'source_url', $spec['source_url'] );
								$comment_ids[] = $cid;
							}
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
						\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							'[fosse-e2e/seed-reactions] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
						);
						return new \WP_Error(
							'fosse_e2e_seed_error',
							$e->getMessage(),
							array( 'status' => 500 )
						);
					}
				},
			)
		);
	}
);
