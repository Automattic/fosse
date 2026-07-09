<?php
/**
 * Plugin Name: FOSSE e2e Atmosphere Capture
 * Description: Test-only helper. Short-circuits Atmosphere's
 *   `atmosphere_pre_apply_writes` filter so every batch of writes the
 *   Publisher emits is recorded to a WordPress option (instead of
 *   being sent to a real PDS) while still committing the publisher's
 *   own post-meta side effects (META_THREAD_RECORDS, META_URI, etc.).
 *   The filter returns a synthesized `applyWrites` success response
 *   with one deterministic uri/cid per write so Publisher's sequential
 *   thread path can chain reply refs across calls. Specs read the
 *   recorded batches via `GET /wp-json/fosse-e2e/v1/apply-writes`,
 *   reset state between tests via `DELETE` on the same route, and
 *   inspect committed post-meta via `GET /wp-json/fosse-e2e/v1/post-meta`
 *   (since `_atmosphere_*` keys aren't `show_in_rest`).
 *
 *   Atmosphere's publish flow normally queues `atmosphere_publish_post`
 *   on the cron, which Playground would only drain on a subsequent
 *   HTTP request. To keep specs deterministic without polling cron,
 *   we hook `transition_post_status` at priority 11 (right after
 *   Atmosphere's own scheduler at priority 10), clear the freshly-
 *   queued event, and invoke the async hook synchronously so the
 *   apply_writes filter fires before the REST POST returns.
 *
 *   Also exposes seed endpoints so specs can flip the canonical
 *   object-type option, pin the long-form composition strategy, and
 *   toggle the Atmosphere connection without re-booting Playground.
 *   Copied into wp-content/mu-plugins/ by blueprint.json.
 *
 * @package Automattic\Fosse\Tests\E2E
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option name where each captured `applyWrites` batch is appended.
 *
 * Stored shape: `[ { writes: array, did: string }, ... ]` — one entry
 * per `apply_writes` call, in invocation order. A 3-entry teaser-thread
 * produces 3 entries (root+doc, then each reply as its own batch).
 */
const FOSSE_E2E_APPLY_WRITES_OPTION = 'fosse_e2e_apply_writes_calls';

/*
 * Capture every applyWrites batch the Publisher emits and short-circuit
 * the PDS round-trip with a deterministic success response.
 *
 * Synthesized `uri` mirrors the real PDS shape so Publisher's reply-ref
 * chaining works end-to-end without a real network: each create yields
 * `at://<did>/<collection>/<rkey>` derived from the write itself. `cid`
 * uses a deterministic `bafyrei…`-prefixed hash so META_THREAD_RECORDS
 * gets populated with realistic-looking values (and so specs can
 * compare CIDs across writes).
 *
 * Filter runs at priority 10 (default). API::apply_writes validates
 * the return shape (`[ 'results' => [ { uri, cid }, ... ] ]` with one
 * entry per write), so failures here surface as Publisher WP_Errors
 * and specs see the publish path break cleanly rather than time out.
 */
add_filter(
	'atmosphere_pre_apply_writes',
	static function ( $short_circuit, array $writes ) {
		// Defensive: respect an upstream short-circuit if anything
		// else is already in the chain (other mu-plugins, dev shims).
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		$did = \function_exists( 'Atmosphere\\get_did' ) ? \Atmosphere\get_did() : '';

		$existing = \get_option( FOSSE_E2E_APPLY_WRITES_OPTION, array() );
		if ( ! \is_array( $existing ) ) {
			$existing = array();
		}

		$existing[] = array(
			'writes' => $writes,
			'did'    => $did,
		);
		\update_option( FOSSE_E2E_APPLY_WRITES_OPTION, $existing, false );

		$results = array();
		foreach ( $writes as $write ) {
			$type       = (string) ( $write['$type'] ?? '' );
			$collection = (string) ( $write['collection'] ?? '' );
			$rkey       = (string) ( $write['rkey'] ?? '' );

			if ( 'com.atproto.repo.applyWrites#delete' === $type ) {
				// Deletes have no uri/cid response per the lexicon.
				$results[] = array();
				continue;
			}

			$results[] = array(
				'uri' => \sprintf( 'at://%s/%s/%s', $did, $collection, $rkey ),
				'cid' => 'bafyrei' . \substr( \hash( 'sha256', $collection . '/' . $rkey ), 0, 32 ),
			);
		}

		return array( 'results' => $results );
	},
	10,
	2
);

/*
 * Drain the freshly-scheduled `atmosphere_publish_post` (or
 * `_update_post`, `_delete_post`) cron event inline so specs don't
 * need to wait on Playground's cron loop.
 *
 * Atmosphere's `on_status_change` callback at priority 10 calls
 * `wp_schedule_single_event` for the relevant async hook. We run at
 * priority 11, clear the just-scheduled event so it can't double-fire
 * later, and dispatch the action synchronously. With the apply_writes
 * filter already in place, this means by the time `POST /wp/v2/posts`
 * returns, the capture option is fully populated and any post-meta
 * side effects (META_THREAD_RECORDS, META_URI, etc.) are committed.
 */
add_action(
	'transition_post_status',
	static function ( string $new_status, string $old_status, \WP_Post $post ): void {
		unset( $new_status, $old_status );

		if ( ! \in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		if ( ! \class_exists( '\\Atmosphere\\Publisher' ) ) {
			return;
		}

		$hooks = array(
			'atmosphere_publish_post',
			'atmosphere_update_post',
			'atmosphere_delete_post',
		);

		foreach ( $hooks as $hook ) {
			$scheduled = \wp_next_scheduled( $hook, array( $post->ID ) );
			if ( false === $scheduled ) {
				continue;
			}

			\wp_clear_scheduled_hook( $hook, array( $post->ID ) );
			\do_action( $hook, $post->ID );
		}
	},
	11,
	3
);

/*
 * Test-only REST endpoints registered on `rest_api_init`. Behind
 * `manage_options` so they are inaccessible to unauthenticated
 * requests; Playground's `login: true` blueprint step satisfies that.
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
							\delete_option( 'activitypub_object_type' );
						} else {
							\update_option( 'activitypub_object_type', $value );
						}
						return \rest_ensure_response(
							array(
								'ok'      => true,
								'current' => \get_option( 'activitypub_object_type', null ),
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

		/*
		 * Pin Atmosphere's long-form composition strategy
		 * (`atmosphere_long_form_composition`) between tests. FOSSE's
		 * canonical-options migrator seeds this to `'teaser-thread'`
		 * for fresh installs; specs that want to assert the
		 * `'link-card'` or `'truncate-link'` branches need to flip it
		 * explicitly so test ordering can't bleed state.
		 */
		\register_rest_route(
			'fosse-e2e/v1',
			'/long-form-strategy',
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
							\delete_option( 'atmosphere_long_form_composition' );
						} else {
							\update_option( 'atmosphere_long_form_composition', $value );
						}
						return \rest_ensure_response(
							array(
								'ok'      => true,
								'current' => \get_option( 'atmosphere_long_form_composition', null ),
							)
						);
					} catch ( \Throwable $e ) {
						\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							'[fosse-e2e/long-form-strategy] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
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
						$connected    = (bool) $request->get_param( 'connected' );
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
							// Atmosphere's get_identity() lazy-migrates from
							// atmosphere_connection into atmosphere_identity
							// on first read after a connect, and the cached
							// identity outlives the connection. A spec that
							// disconnects without clearing identity would
							// see a poisoned reconnect path on its next seed.
							\delete_option( 'atmosphere_identity' );
						}

						if ( null !== $auto_publish ) {
							\update_option( 'atmosphere_auto_publish', $auto_publish ? '1' : '0' );
						}

						return \rest_ensure_response(
							array(
								'ok'           => true,
								'connected'    => $connected,
								'connection'   => \get_option( 'atmosphere_connection', array() ),
								'auto_publish' => \get_option( 'atmosphere_auto_publish', '1' ),
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

		/*
		 * Read and reset the captured applyWrites batches.
		 *
		 * `GET` returns `{ calls: [ { writes, did }, ... ] }` in
		 * invocation order. `DELETE` clears the option so specs can
		 * isolate one publish flow per test.
		 */
		\register_rest_route(
			'fosse-e2e/v1',
			'/apply-writes',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => static function (): bool {
						return \current_user_can( 'manage_options' );
					},
					'callback'            => static function () {
						$calls = \get_option( FOSSE_E2E_APPLY_WRITES_OPTION, array() );
						if ( ! \is_array( $calls ) ) {
							$calls = array();
						}
						return \rest_ensure_response( array( 'calls' => $calls ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => static function (): bool {
						return \current_user_can( 'manage_options' );
					},
					'callback'            => static function () {
						\delete_option( FOSSE_E2E_APPLY_WRITES_OPTION );
						return \rest_ensure_response( array( 'ok' => true ) );
					},
				),
			)
		);

		/*
		 * Read selected post-meta keys for a given post. Needed
		 * because the publisher writes to `_atmosphere_*` keys
		 * (META_URI, META_THREAD_RECORDS, etc.) that aren't
		 * registered with `show_in_rest`, so the standard
		 * /wp/v2/posts/<id> response omits them.
		 */
		\register_rest_route(
			'fosse-e2e/v1',
			'/post-meta',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function (): bool {
					return \current_user_can( 'manage_options' );
				},
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'keys'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => static function ( $request ) {
					$post_id = (int) $request->get_param( 'post_id' );
					$keys    = \array_filter( \array_map( 'trim', \explode( ',', (string) $request->get_param( 'keys' ) ) ) );

					$out = array();
					foreach ( $keys as $key ) {
						$out[ $key ] = \get_post_meta( $post_id, $key, true );
					}

					return \rest_ensure_response(
						array(
							'post_id' => $post_id,
							'meta'    => $out,
						)
					);
				},
			)
		);
	}
);
