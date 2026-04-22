<?php
/**
 * Plugin Name: FOSSE e2e Atmosphere Capture
 * Description: Test-only helper. Hooks transition_post_status BEFORE
 *   Atmosphere's publisher and dumps both transformed records (the
 *   app.bsky.feed.post and the site.standard.document that
 *   Publisher::publish would write in an atomic applyWrites call) to
 *   uploads/fosse-bsky-capture.json so Playwright can assert their
 *   shape without standing up a real PDS or OAuth connection.
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

		if ( ! \class_exists( '\Atmosphere\Transformer\Post' )
			|| ! \class_exists( '\Atmosphere\Transformer\Document' )
		) {
			return;
		}

		$bsky = ( new \Atmosphere\Transformer\Post( $post ) )->transform();
		$doc  = ( new \Atmosphere\Transformer\Document( $post ) )->transform();

		$upload = \wp_upload_dir();
		$path   = \trailingslashit( $upload['basedir'] ) . 'fosse-bsky-capture.json';

		\file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$path,
			\wp_json_encode(
				array(
					'post_id'     => $post->ID,
					'bsky_record' => $bsky,
					'doc_record'  => $doc,
				)
			)
		);
	},
	5,
	3
);
