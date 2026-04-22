<?php
/**
 * Plugin Name: FOSSE e2e Bsky Capture
 * Description: Test-only helper. Hooks transition_post_status BEFORE
 *   Atmosphere's publisher and dumps the transformed app.bsky.feed.post
 *   record to uploads/fosse-bsky-capture.json so Playwright can assert
 *   its shape without standing up a real PDS or OAuth connection.
 *   Mounted at wp-content/mu-plugins/ by playwright.config.ts.
 *
 * @package Automattic\Fosse\Tests\E2E
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'transition_post_status',
	static function ( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! \in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		if ( ! \class_exists( '\Atmosphere\Transformer\Post' ) ) {
			return;
		}

		$record  = ( new \Atmosphere\Transformer\Post( $post ) )->transform();
		$upload  = \wp_upload_dir();
		$path    = \trailingslashit( $upload['basedir'] ) . 'fosse-bsky-capture.json';

		\file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$path,
			\wp_json_encode(
				array(
					'post_id'    => $post->ID,
					'collection' => 'app.bsky.feed.post',
					'record'     => $record,
				)
			)
		);
	},
	5,
	3
);
