<?php
/**
 * Post types FOSSE offers in its chooser UIs.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Centralizes the list of post types FOSSE surfaces in its wizard and
 * Settings choosers, and provides the merge pattern that preserves
 * upstream-configured values FOSSE doesn't manage.
 *
 * Why not just use `get_post_types( array( 'public' => true ) )` everywhere:
 *
 * - `attachment` is a public post type, so it falls out of that filter and
 *   would surface as a "Media" checkbox alongside Posts and Pages. The
 *   wizard's question is "what kind of content do I want to share?" - Media
 *   doesn't fit that frame. The actual behavior is also surprising:
 *   enabling it federates every image upload as its own ActivityPub object
 *   the moment it's uploaded, including images attached to unpublished
 *   drafts. The FOSSE UI shouldn't put that footgun in front of users.
 * - Power users who deliberately want Media federation can still flip
 *   `attachment` on via bundled ActivityPub's own settings. FOSSE's
 *   contract on `activitypub_support_post_types` is "manage the curated
 *   subset, preserve anything else" so that upstream choice survives a
 *   FOSSE save.
 *
 * See DOTCOM-17047 for the discussion that motivated this.
 */
final class Post_Type_Chooser {

	/**
	 * Post types FOSSE deliberately omits from its chooser UIs even though
	 * `get_post_types( array( 'public' => true ) )` returns them.
	 *
	 * @var array<string>
	 */
	private const EXCLUDED = array( 'attachment' );

	/**
	 * Post-type objects FOSSE offers in its chooser UIs.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( self::EXCLUDED as $name ) {
			unset( $types[ $name ] );
		}
		return $types;
	}

	/**
	 * Post-type names FOSSE offers in its chooser UIs.
	 *
	 * Derives from `types()` so the exclusion logic has a single source
	 * of truth — adding a new EXCLUDED entry, or any future runtime
	 * filtering inside `types()`, automatically propagates here.
	 *
	 * @return array<string>
	 */
	public static function names(): array {
		return array_values( array_keys( self::types() ) );
	}

	/**
	 * Reconcile a chooser submission with the stored option, preserving
	 * any values FOSSE doesn't manage.
	 *
	 * Without this, saving the wizard or Settings page would silently strip
	 * an upstream-enabled `attachment` from `activitypub_support_post_types`
	 * because FOSSE's UI doesn't surface a checkbox for it.
	 *
	 * @param array<string> $submitted Sanitized post-type names from `$_POST`.
	 * @param array<string> $existing  Current value of the stored option.
	 * @return array<string>
	 */
	public static function reconcile_submission( array $submitted, array $existing ): array {
		$managed   = self::names();
		$preserved = array_diff( $existing, $managed );
		$selected  = array_intersect( $submitted, $managed );
		return array_values( array_unique( array_merge( $selected, $preserved ) ) );
	}
}
