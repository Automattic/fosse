<?php
/**
 * Detects when the ActivityPub actor mode is constant-locked.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Mirrors the constant checks bundled ActivityPub uses in
 * `Options::pre_option_activitypub_actor_mode()` (see
 * `bundled/activitypub/includes/class-options.php`). FOSSE's own admin
 * UI must honor the same locks, otherwise users see controls they can
 * interact with that have no effect on the saved option (the pre_option
 * filter overrides the read regardless).
 *
 * `register_hooks()` additionally enforces the lock at the save layer
 * via `pre_update_option_activitypub_actor_mode`, which closes the
 * write paths the UI alone cannot guard (tampered form POST, direct
 * options.php submission, REST PUT to `/wp/v2/settings`).
 *
 * Hosts (including WordPress.com) may define `ACTIVITYPUB_SINGLE_USER_MODE`
 * to permanently lock the actor mode at the platform level.
 */
final class Actor_Mode_Lock {

	/**
	 * Blog actor mode — site posts under a single site-wide profile.
	 *
	 * Matches `ACTIVITYPUB_BLOG_MODE` in
	 * `bundled/activitypub/includes/constants.php`. Mirrored as a class
	 * constant here to avoid a load-order dependency on bundled AP.
	 *
	 * @var string
	 */
	public const MODE_BLOG = 'blog';

	/**
	 * Author actor mode — each author posts under their own profile.
	 *
	 * Matches `ACTIVITYPUB_ACTOR_MODE` in
	 * `bundled/activitypub/includes/constants.php`. Mirrored as a class
	 * constant here to avoid a load-order dependency on bundled AP.
	 *
	 * @var string
	 */
	public const MODE_ACTOR = 'actor';

	/**
	 * Static-only utility; never instantiated.
	 */
	private function __construct() {}

	/**
	 * Returns the actor mode the host's constants force, or null if free.
	 *
	 * @return string|null One of self::MODE_BLOG, self::MODE_ACTOR, or null when no constant lock is set.
	 */
	public static function forced_mode(): ?string {
		if ( defined( 'ACTIVITYPUB_SINGLE_USER_MODE' ) && ACTIVITYPUB_SINGLE_USER_MODE ) {
			return self::MODE_BLOG;
		}

		if ( defined( 'ACTIVITYPUB_DISABLE_USER' ) && ACTIVITYPUB_DISABLE_USER ) {
			return self::MODE_BLOG;
		}

		if ( defined( 'ACTIVITYPUB_DISABLE_BLOG_USER' ) && ACTIVITYPUB_DISABLE_BLOG_USER ) {
			return self::MODE_ACTOR;
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
		return __(
			/* translators: leading ⚠ (U+26A0) is a warning sign indicating the field is locked by a server-defined constant; preserve it verbatim. */
			'⚠ This setting is defined through server configuration by your blog\'s administrator.',
			'fosse'
		);
	}

	/**
	 * Wire save-layer enforcement so the stored option can't drift from
	 * the constant-forced value.
	 *
	 * The UI `is_locked()` branches in the setup page and onboarding
	 * wizard are advisory: they hide the controls and submit a hidden
	 * input with the forced mode. They do not block the three other
	 * write paths into `activitypub_actor_mode` — a tampered POST to
	 * the FOSSE handlers, a direct submission to options.php (the
	 * bundled AP page is reachable by URL), or a REST PUT to
	 * `/wp/v2/settings` (bundled AP registers `activitypub_actor_mode`
	 * as a REST-visible setting with no sanitize guard). All three
	 * end up in `update_option()`, where this filter coerces the
	 * incoming value back to the forced mode.
	 *
	 * Without this, the stored value can disagree with what bundled
	 * AP serves on read, and removing the lock later would surface
	 * the stale tampered value as the new active mode.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'pre_update_option_activitypub_actor_mode', array( self::class, 'coerce_to_forced_mode' ) );
	}

	/**
	 * Coerce an incoming actor-mode write to the forced mode when locked.
	 *
	 * Callback for `pre_update_option_activitypub_actor_mode`. Pass-through
	 * when no lock is set, so non-locked installs see no behavior change.
	 *
	 * @param mixed $value The incoming value being written.
	 * @return mixed The forced mode when locked, otherwise the original value.
	 */
	public static function coerce_to_forced_mode( $value ) {
		$forced = self::forced_mode();
		return null === $forced ? $value : $forced;
	}
}
