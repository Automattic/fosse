<?php
/**
 * Plugin Name: FOSSE e2e Atmosphere Capture
 * Description: Test-only helper. Hooks transition_post_status BEFORE
 *   Atmosphere's publisher and dumps both transformed records (the
 *   app.bsky.feed.post and the site.standard.document that
 *   Publisher::publish would write in an atomic applyWrites call) to
 *   uploads/fosse-bsky-capture.json so Playwright can assert their
 *   shape without standing up a real PDS or OAuth connection. Also
 *   exposes a REST helper so specs can flip the fosse_object_type
 *   option between tests without re-booting Playground. Copied into
 *   wp-content/mu-plugins/ by blueprint.json.
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

		$upload = \wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[fosse-bsky-capture] wp_upload_dir() error: ' . $upload['error']
			);
			return;
		}

		$bsky = ( new \Atmosphere\Transformer\Post( $post ) )->transform();
		$doc  = ( new \Atmosphere\Transformer\Document( $post ) )->transform();

		$path    = \trailingslashit( $upload['basedir'] ) . 'fosse-bsky-capture.json';
		$written = \file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$path,
			\wp_json_encode(
				array(
					'post_id'     => $post->ID,
					'bsky_record' => $bsky,
					'doc_record'  => $doc,
				)
			)
		);
		if ( false === $written ) {
			\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[fosse-bsky-capture] file_put_contents failed writing to ' . $path
			);
		}
	},
	5,
	3
);

/*
 * Test-only REST endpoint so specs can set/clear `fosse_object_type`
 * between tests without re-booting Playground. manage_options gate keeps
 * this inaccessible to unauthenticated requests; Playground's
 * `login: true` blueprint step admin-session satisfies it.
 */
add_action(
	'rest_api_init',
	static function (): void {
		\register_rest_route(
			'fosse-e2e/v1',
			'/object-type',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return \current_user_can( 'manage_options' );
				},
				'args'                => array(
					'value' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
				'callback'            => static function ( $request ) {
					try {
						$value = $request->get_param( 'value' );
						if ( null === $value || '' === $value ) {
							\delete_option( 'fosse_object_type' );
						} else {
							\update_option( 'fosse_object_type', $value );
						}
						return \rest_ensure_response(
							array(
								'ok'      => true,
								'current' => \get_option( 'fosse_object_type', null ),
							)
						);
					} catch ( \Throwable $e ) {
						\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							'[fosse-e2e/object-type] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
						);
						return new \WP_Error(
							'fosse_e2e_error',
							$e->getMessage(),
							array( 'status' => 500 )
						);
					}
				},
			)
		);

		\register_rest_route(
			'fosse-e2e/v1',
			'/bluesky-state',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return \current_user_can( 'manage_options' );
				},
				'args'                => array(
					'connected'    => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'handle'       => array(
						'type'     => 'string',
						'required' => false,
					),
					'did'          => array(
						'type'     => 'string',
						'required' => false,
					),
					'pds_endpoint' => array(
						'type'     => 'string',
						'required' => false,
					),
					'auto_publish' => array(
						'type'     => 'boolean',
						'required' => false,
					),
				),
				'callback'            => static function ( $request ) {
					try {
						$connected = (bool) $request->get_param( 'connected' );
						$auto_publish = $request->get_param( 'auto_publish' );

						if ( $connected ) {
							\update_option(
								'atmosphere_connection',
								array(
									'did'          => (string) ( $request->get_param( 'did' ) ?: 'did:plc:fossee2e123' ),
									'handle'       => (string) ( $request->get_param( 'handle' ) ?: 'alice.bsky.social' ),
									'pds_endpoint' => (string) ( $request->get_param( 'pds_endpoint' ) ?: 'https://bsky.social' ),
									'access_token' => \Atmosphere\OAuth\Encryption::encrypt( 'fosse-e2e-token' ),
								)
							);
						} else {
							\delete_option( 'atmosphere_connection' );
						}

						if ( null !== $auto_publish ) {
							\update_option( 'atmosphere_auto_publish', $auto_publish ? '1' : '0' );
						}

						return \rest_ensure_response(
							array(
								'ok'             => true,
								'connected'      => $connected,
								'connection'     => \get_option( 'atmosphere_connection', array() ),
								'auto_publish'   => \get_option( 'atmosphere_auto_publish', '1' ),
							)
						);
					} catch ( \Throwable $e ) {
						\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							'[fosse-e2e/bluesky-state] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
						);
						return new \WP_Error(
							'fosse_e2e_error',
							$e->getMessage(),
							array( 'status' => 500 )
						);
					}
				},
			)
		);
	}
);
