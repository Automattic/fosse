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
	 * as a REST-visible setting with no sanitize guard).
	 *
	 * Two hooks are wired:
	 *
	 * - `pre_update_option_activitypub_actor_mode` coerces the incoming
	 *   value passed to `update_option()`. This shapes what callers
	 *   reading the in-memory `$value` see, but does NOT by itself fix
	 *   the stored row: `update_option()` calls `get_option()` to
	 *   compute `$old_value`, AP's `pre_option` mask makes that return
	 *   the forced mode, and the new vs old comparison short-circuits
	 *   before the row is touched.
	 *
	 * - `admin_init` runs `repair_stored_value()`, which writes the
	 *   forced value through to the stored row when it disagrees.
	 *   Hooked on `admin_init` rather than `init` so the repair only
	 *   runs on admin requests — frontend page views (which AP's read
	 *   mask already serves the forced value to) skip it entirely.
	 *   That avoids hammering the DB with repair attempts under
	 *   high-traffic spikes and confines write activity to the surface
	 *   where an admin would actually observe the stored value.
	 *
	 * Both hooks are guarded for idempotency so repeat registration
	 * (a re-initializing test, a stray double-load) is harmless.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( false !== has_filter( 'pre_update_option_activitypub_actor_mode', array( self::class, 'coerce_to_forced_mode' ) ) ) {
			return;
		}

		add_filter( 'pre_update_option_activitypub_actor_mode', array( self::class, 'coerce_to_forced_mode' ) );
		add_action( 'admin_init', array( self::class, 'repair_stored_value' ) );
	}

	/**
	 * Coerce an incoming actor-mode write to the forced mode when locked.
	 *
	 * Pass-through when no lock is set, so non-locked installs see no
	 * behavior change.
	 *
	 * @internal Filter callback for `pre_update_option_activitypub_actor_mode`. Not for direct callers.
	 *
	 * @param mixed $value The incoming value being written.
	 * @return mixed The forced mode when locked, otherwise the original value.
	 */
	public static function coerce_to_forced_mode( $value ) {
		$forced = self::forced_mode();
		return null === $forced ? $value : $forced;
	}

	/**
	 * Reconcile the raw stored `activitypub_actor_mode` value with the
	 * constant-forced value.
	 *
	 * The pre_update filter alone cannot do this. When bundled AP's
	 * `pre_option_activitypub_actor_mode` mask is active,
	 * `get_option('activitypub_actor_mode')` returns the forced mode
	 * regardless of what's stored. `update_option()` then sees the
	 * coerced incoming value === old value and short-circuits without
	 * touching the row, so a value written before the lock activated
	 * (or a prior tampered POST that landed before this code shipped)
	 * stays in the database. Removing the lock later would surface
	 * that stale value as the active mode.
	 *
	 * Repair: temporarily remove the bundled AP read mask so
	 * `update_option()` sees the actual stored value as the old value,
	 * write the forced value through if it disagrees, then restore the
	 * mask. The unhooking targets bundled AP's exact static callable
	 * (`Activitypub\Options::pre_option_activitypub_actor_mode`) — which
	 * is the canonical registration in our vendored AP copy
	 * (`bundled/activitypub/includes/class-options.php`). If the mask
	 * isn't registered (e.g., bundled AP didn't load or a third-party
	 * AP fork is active under a different class), the repair still
	 * runs harmlessly: `get_option()` returns the raw stored value
	 * directly and the comparison/write proceeds normally.
	 *
	 * One option-read per request on locked installs; the actual write
	 * only fires when the stored value disagrees with the forced mode.
	 * Unlocked installs return early before any work.
	 *
	 * @internal Action callback for `admin_init`. Not for direct callers.
	 *
	 * @return void
	 */
	public static function repair_stored_value(): void {
		$forced = self::forced_mode();
		if ( null === $forced ) {
			return;
		}

		$ap_mask     = array( 'Activitypub\Options', 'pre_option_activitypub_actor_mode' );
		$ap_priority = has_filter( 'pre_option_activitypub_actor_mode', $ap_mask );
		if ( false !== $ap_priority ) {
			remove_filter( 'pre_option_activitypub_actor_mode', $ap_mask, $ap_priority );
		}

		if ( $forced !== get_option( 'activitypub_actor_mode' ) ) {
			update_option( 'activitypub_actor_mode', $forced );
		}

		if ( false !== $ap_priority ) {
			add_filter( 'pre_option_activitypub_actor_mode', $ap_mask, $ap_priority );
		}
	}
}
