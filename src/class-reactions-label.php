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
 * is already protocol-agnostic — `get_comments()` filters by
 * `comment_type`, not by the `protocol` comment-meta value — so Bluesky
 * reactions written by `wordpress-atmosphere`'s `Reaction_Sync` (with
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
 * v1.0.0 fallback string in the bundled `render.php` is not covered;
 * see `sdd/unified-reactions-display/spec.md` "Known Gaps" for the
 * reasoning.
 */
class Reactions_Label {

	/**
	 * Block name targeted by the relabel. Other registrations pass through.
	 *
	 * @var string
	 */
	private const BLOCK_NAME = 'activitypub/reactions';

	/**
	 * FOSSE-flavored title overlaid onto the block's registered metadata.
	 *
	 * @var string
	 */
	private const TITLE = 'Social Reactions';

	/**
	 * FOSSE-flavored description overlaid onto the block's registered metadata.
	 *
	 * @var string
	 */
	private const DESCRIPTION = 'Display social likes and reposts for your posts.';

	/**
	 * Register the relabel filter. Safe to call more than once per request —
	 * WordPress dedupes identical callable-as-array registrations via the
	 * unique-id key in `WP_Hook::add_filter()`, so repeated calls leave a
	 * single registered callback at this hook + priority.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_filter( 'register_block_type_args', array( self::class, 'rewrite_block_args' ), 10, 2 );
	}

	/**
	 * Overlay the FOSSE title and description onto the bundled
	 * activitypub/reactions block at registration time. Other block names
	 * pass through unchanged. Existing keys in `$args` that the projector
	 * does not own are preserved untouched; absent keys are not invented.
	 *
	 * @param array  $args Block-type arguments as registered upstream.
	 * @param string $name Block name being registered.
	 * @return array The (possibly overlaid) arguments.
	 */
	public static function rewrite_block_args( array $args, string $name ): array {
		if ( self::BLOCK_NAME !== $name ) {
			return $args;
		}

		if ( isset( $args['title'] ) ) {
			$args['title'] = self::TITLE;
		}

		if ( isset( $args['description'] ) ) {
			$args['description'] = self::DESCRIPTION;
		}

		return $args;
	}
}
