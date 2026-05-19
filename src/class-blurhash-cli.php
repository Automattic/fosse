<?php
/**
 * WP-CLI commands for FOSSE's Blurhash encoder.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

use WP_CLI;
use WP_Query;

/**
 * `wp fosse blurhash …` command surface for backfilling and managing
 * stored blurhash placeholders.
 *
 * The runtime path ({@see Blurhash}) covers new uploads via a cron
 * event; this command exists for two cases the runtime can't handle:
 *
 *   1. **First install on a site with existing media** — every
 *      already-uploaded image attachment is missing the postmeta and
 *      will never get it without a re-upload or a forced re-encode.
 *      `wp fosse blurhash backfill` walks the library and fills the
 *      gap.
 *   2. **Re-encode after an algorithm change** — if the encoder's
 *      components or source-size change, the previously-computed
 *      hashes are stale (still decodable, but produced from a
 *      different recipe). `--force` deletes and recomputes.
 *
 * Registration is gated on `WP_CLI` so the class is only loaded by
 * the CLI process — no overhead on web requests.
 */
class Blurhash_CLI {

	/**
	 * Compute and persist Blurhash placeholders for image
	 * attachments missing one. Use after first install on a site
	 * with existing media, or after an encoder change with --force.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Walk and report what would be encoded, but don't write postmeta.
	 *
	 * [--limit=<n>]
	 * : Process at most <n> attachments. Default: 0 (no limit).
	 *
	 * [--force]
	 * : Re-encode attachments that already have a stored hash.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fosse blurhash backfill --dry-run
	 *     wp fosse blurhash backfill --limit=100
	 *     wp fosse blurhash backfill --force
	 *
	 * @param array<int, string>   $args       Positional CLI args (unused).
	 * @param array<string, mixed> $assoc_args Associative CLI flags.
	 *
	 * @when after_wp_load
	 */
	public function backfill( $args, $assoc_args ) {
		unset( $args );

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$force   = ! empty( $assoc_args['force'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$processed = 0;
		$encoded   = 0;
		$skipped   = 0;
		$failed    = 0;
		$paged     = 1;
		$per_page  = 100;

		while ( true ) {
			$query = new WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => 'image',
					'posts_per_page'         => $per_page,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $attachment_id ) {
				$attachment_id = (int) $attachment_id;

				if ( $limit > 0 && $processed >= $limit ) {
					break 2;
				}

				++$processed;
				$existing = Blurhash::get( $attachment_id );

				if ( null !== $existing && ! $force ) {
					++$skipped;
					continue;
				}

				if ( $dry_run ) {
					++$encoded;
					WP_CLI::log( "would encode: attachment {$attachment_id}" );
					continue;
				}

				if ( $force && null !== $existing ) {
					Blurhash::delete( $attachment_id );
				}

				$hash = Blurhash::encode_from_attachment( $attachment_id );
				if ( null === $hash ) {
					++$failed;
					WP_CLI::warning( "encode failed: attachment {$attachment_id}" );
					continue;
				}

				Blurhash::set( $attachment_id, $hash );
				++$encoded;
				WP_CLI::log( "encoded {$attachment_id}: {$hash}" );
			}

			++$paged;
		}

		$summary = sprintf(
			'Processed %d image attachments — encoded %d, skipped %d (already had hash), failed %d.',
			$processed,
			$encoded,
			$skipped,
			$failed
		);

		if ( $dry_run ) {
			WP_CLI::success( '[dry-run] ' . $summary );
		} else {
			WP_CLI::success( $summary );
		}
	}

	/**
	 * Register the `wp fosse blurhash` command surface. No-op when
	 * WP-CLI isn't loaded.
	 */
	public static function register(): void {
		if ( ! \defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'fosse blurhash', self::class );
	}
}
