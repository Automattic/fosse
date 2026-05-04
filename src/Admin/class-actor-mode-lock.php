<?php
/**
 * Detects when the ActivityPub actor mode is constant-locked.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Mirrors the constant checks bundled ActivityPub uses in its
 * `pre_option_activitypub_actor_mode` filter (see
 * `bundled/activitypub/includes/class-options.php:501-515`). FOSSE's
 * own admin UI must honor the same locks, otherwise users see controls
 * they can interact with that have no effect on the saved option (the
 * pre_option filter overrides the read regardless).
 *
 * On wp.com, `ACTIVITYPUB_SINGLE_USER_MODE = true` is defined
 * unconditionally in `wpcom-activitypub-load.php`, so any FOSSE
 * deployment on wp.com Simple is permanently locked to blog mode.
 */
class Actor_Mode_Lock {

	/**
	 * Returns the actor mode the host's constants force, or null if free.
	 *
	 * @return string|null One of 'blog', 'actor', or null when no constant lock is set.
	 */
	public static function forced_mode(): ?string {
		if ( defined( 'ACTIVITYPUB_SINGLE_USER_MODE' ) && ACTIVITYPUB_SINGLE_USER_MODE ) {
			return 'blog';
		}

		if ( defined( 'ACTIVITYPUB_DISABLE_USER' ) && ACTIVITYPUB_DISABLE_USER ) {
			return 'blog';
		}

		if ( defined( 'ACTIVITYPUB_DISABLE_BLOG_USER' ) && ACTIVITYPUB_DISABLE_BLOG_USER ) {
			return 'actor';
		}

		return null;
	}

	/**
	 * Whether actor mode is locked by host constants.
	 *
	 * @return bool
	 */
	public static function is_locked(): bool {
		return null !== self::forced_mode();
	}

	/**
	 * The user-facing notice explaining the lock. Mirrors the wording
	 * bundled ActivityPub uses on its own settings page so users see a
	 * consistent message across both surfaces.
	 *
	 * @return string
	 */
	public static function locked_notice(): string {
		return __( '⚠ This setting is defined through server configuration by your blog\'s administrator.', 'fosse' );
	}
}
