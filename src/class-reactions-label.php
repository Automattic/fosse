<?php
/**
 * FOSSE-side relabel for the bundled activitypub/reactions block.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Overlays a FOSSE-flavored title and description on the bundled
 * `activitypub/reactions` block at registration time.
 *
 * The bundled ActivityPub plugin owns the block. Its server-side render
 * is already protocol-agnostic — `get_comments()` queries by
 * `comment_type` and never inspects the `protocol` comment-meta value —
 * so Bluesky reactions written by `atmosphere`'s `Reaction_Sync` (with
 * `comment_type='like'`/`'repost'` and `protocol='atproto'`) appear in
 * the same block as ActivityPub-protocol reactions.
 *
 * Once both networks share that block, the bundled wording becomes
 * narrower than the audience: "Fediverse Reactions" describes only one
 * of the two protocols actually being aggregated. This class hooks
 * `register_block_type_args` to overlay a source-agnostic wording
 * ("Social Reactions") onto the registered block metadata so the
 * inserter UI matches what the block actually shows.
 *
 * Scope is deliberately narrow: only the inserter title and description
 * are rewritten. The block's render path is untouched. The legacy
 * v1.0.0 fallback string in the bundled `render.php` is also not
 * covered: it only fires for pre-v1.1 reactions blocks that supplied
 * the title as a block attribute, which the inserter UI no longer
 * produces.
 */
class Reactions_Label {

	private const BLOCK_NAME = 'activitypub/reactions';

	/**
	 * Register the relabel filter. No-op if the bundled ActivityPub
	 * plugin is absent — the block we relabel is owned by AP, so without
	 * AP there is nothing to overlay. Safe to call more than once per
	 * request: WordPress dedupes identical callable-as-array
	 * registrations via the unique-id key in `WP_Hook::add_filter()`,
	 * leaving a single registered callback at this hook + priority.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! \class_exists( '\Activitypub\Activitypub' ) ) {
			return;
		}

		\add_filter( 'register_block_type_args', array( self::class, 'rewrite_block_args' ), 10, 2 );
	}

	/**
	 * Overlay the FOSSE title and description onto the bundled
	 * activitypub/reactions block at registration time. Other block names
	 * pass through unchanged. Existing string values for the keys we own
	 * are replaced; absent keys (and non-string values upstream might one
	 * day pass) are left untouched so a future regression here cannot
	 * silently coerce a translatable wrapper into a plain string.
	 *
	 * @param array  $args Block-type arguments as registered upstream.
	 * @param string $name Block name being registered.
	 * @return array The (possibly overlaid) arguments.
	 */
	public static function rewrite_block_args( array $args, string $name ): array {
		if ( self::BLOCK_NAME !== $name ) {
			return $args;
		}

		if ( isset( $args['title'] ) && \is_string( $args['title'] ) ) {
			$args['title'] = \__( 'Social Reactions', 'fosse' );
		}

		if ( isset( $args['description'] ) && \is_string( $args['description'] ) ) {
			$args['description'] = \__( 'Display social likes and reposts for your posts.', 'fosse' );
		}

		return $args;
	}
}
