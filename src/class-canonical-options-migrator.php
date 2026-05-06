<?php
/**
 * One-time migration from FOSSE-side projector options to the canonical
 * upstream options.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Moves `fosse_object_type` to `activitypub_object_type` and
 * `fosse_long_form_strategy` to `atmosphere_long_form_composition`,
 * then deletes the FOSSE-side options. Also seeds the long-form
 * composition with FOSSE's preferred default (`'teaser-thread'`)
 * for fresh installs that have neither the old FOSSE option nor a
 * stored upstream value.
 *
 * Replaces the Object_Type AP-side projector and the Long_Form_Strategy
 * projector entirely. Object_Type still drives the Atmosphere
 * short-form bridge from the canonical `activitypub_object_type` value.
 *
 * Runs at most once per site, gated on the
 * `fosse_canonical_options_migrated` option. Hooked at `init` priority 5
 * so the migration completes before the projector callbacks run at the
 * default priority 10. This guarantees that any post publish — including
 * those triggered by REST, cron, CLI, or a frontend hit immediately
 * after deployment — sees the canonical option values, not the legacy
 * ones the deleted projectors used to read. After the flag is set the
 * hook is a single cached option-read per request.
 */
class Canonical_Options_Migrator {

	/**
	 * Option name holding the migration completion flag.
	 *
	 * @var string
	 */
	public const MIGRATED_FLAG_OPTION = 'fosse_canonical_options_migrated';

	/**
	 * Atmosphere long-form composition values FOSSE recognizes when
	 * migrating. Matches Atmosphere's current `LONG_FORM_STRATEGIES`
	 * enum exactly so the migrator never writes a value Atmosphere's
	 * sanitize callback would reject. Other legacy values map per
	 * {@see self::resolve_legacy_long_form_strategy()}.
	 *
	 * @var string[]
	 */
	private const ATMOSPHERE_KNOWN_STRATEGIES = array(
		'teaser-thread',
		'truncate-link',
		'link-card',
	);

	/**
	 * FOSSE's preferred default for unset / empty / unknown legacy
	 * values. The deleted `Long_Form_Strategy` projector coerced these
	 * cases to `'teaser-thread'` at filter time, so the migrator
	 * preserves that effective behavior rather than dropping silently
	 * and falling through to Atmosphere's `'link-card'` default. Per
	 * `sdd/long-form-bluesky-strategy/`.
	 *
	 * @var string
	 */
	private const DEFAULT_LONG_FORM_STRATEGY = 'teaser-thread';

	/**
	 * Legacy `'document-card'` was the deleted projector's forward-compat
	 * slot for the Atmosphere v2 renderer. Atmosphere itself doesn't
	 * recognize it today and falls back to `'link-card'` for unknown
	 * strategies, so the deleted projector's pass-through effectively
	 * resolved to `'link-card'` in production. Map it explicitly here
	 * to preserve current effective behavior — coercing to the FOSSE
	 * default would silently shift `document-card` sites from a single
	 * link card to a multi-post teaser thread.
	 *
	 * @var string
	 */
	private const DOCUMENT_CARD_FALLBACK = 'link-card';

	/**
	 * Wire the `init` migration hook at priority 5.
	 *
	 * Runs before the Object_Type bridge (registered at priority 10) so
	 * the migration completes before any filter callback that reads the
	 * canonical option. Idempotent: WordPress dedupes identical
	 * callable-as-array registrations.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'init', array( self::class, 'maybe_migrate' ), 5 );
	}

	/**
	 * Run the migration once if it hasn't completed yet.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( '1' === (string) \get_option( self::MIGRATED_FLAG_OPTION, '' ) ) {
			return;
		}

		self::migrate_object_type();
		self::migrate_long_form_strategy();

		\update_option( self::MIGRATED_FLAG_OPTION, '1', false );
	}

	/**
	 * Copy `fosse_object_type=note` to `activitypub_object_type=note`,
	 * then delete the FOSSE-side option.
	 *
	 * If `activitypub_object_type` is already set, preserve it — that
	 * value is what the user can see and edit in ActivityPub's settings
	 * UI, so trusting the legacy FOSSE option to overwrite it would
	 * silently change the publishing shape away from what the visible
	 * UI claims. The legacy value pre-canonicalization may have been an
	 * implicit default rather than an explicit user choice; respecting
	 * the canonical option keeps the user's most recent explicit choice
	 * authoritative. Other stored legacy values were pass-throughs in
	 * the deleted projector and need no migration.
	 *
	 * @return void
	 */
	private static function migrate_object_type(): void {
		$stored = \get_option( 'fosse_object_type' );

		if ( 'note' === $stored ) {
			$sentinel = '__fosse_unset__';
			$existing = \get_option( 'activitypub_object_type', $sentinel );
			if ( $sentinel === $existing ) {
				\update_option( 'activitypub_object_type', 'note' );
			}
		}

		if ( false !== $stored ) {
			\delete_option( 'fosse_object_type' );
		}
	}

	/**
	 * Move `fosse_long_form_strategy` into `atmosphere_long_form_composition`
	 * and delete the FOSSE-side option.
	 *
	 * Conflict policy: when `atmosphere_long_form_composition` is already
	 * stored, preserve it instead of overwriting from the legacy option.
	 * The canonical option is the value the user can see and edit in
	 * Atmosphere's settings UI; carrying the legacy value forward over
	 * an explicit canonical choice would silently change publishing
	 * behavior away from what the visible UI claims. The legacy value
	 * pre-canonicalization may have been an implicit default (the
	 * deleted projector coerced unset/empty/unknown to `'teaser-thread'`),
	 * not an explicit user choice — so trusting the canonical when both
	 * exist keeps the user's most recent explicit choice authoritative.
	 *
	 * When only the legacy is set, copy it to the canonical option via
	 * {@see self::resolve_legacy_long_form_strategy()} (which coerces
	 * unrecognized values per the deleted projector's coercion rules).
	 *
	 * On a fresh install with neither option set, seed the canonical
	 * option with FOSSE's preferred default so installing FOSSE keeps
	 * opting users into the thread strategy without further configuration.
	 *
	 * @return void
	 */
	private static function migrate_long_form_strategy(): void {
		$stored        = \get_option( 'fosse_long_form_strategy' );
		$sentinel      = '__fosse_unset__';
		$canonical_set = $sentinel !== \get_option( 'atmosphere_long_form_composition', $sentinel );

		if ( false !== $stored ) {
			if ( ! $canonical_set ) {
				\update_option( 'atmosphere_long_form_composition', self::resolve_legacy_long_form_strategy( $stored ) );
			}
			\delete_option( 'fosse_long_form_strategy' );
			return;
		}

		// Fresh install: seed the FOSSE default if and only if Atmosphere
		// hasn't been configured.
		if ( ! $canonical_set ) {
			\update_option( 'atmosphere_long_form_composition', self::DEFAULT_LONG_FORM_STRATEGY );
		}
	}

	/**
	 * Map a legacy `fosse_long_form_strategy` value to the canonical
	 * Atmosphere strategy that preserves the user's *effective* behavior
	 * under the deleted `Long_Form_Strategy` projector.
	 *
	 * - `teaser-thread` / `truncate-link` / `link-card` — pass through
	 *   unchanged; Atmosphere accepts them directly.
	 * - `document-card` — map to `link-card` (the deleted projector
	 *   passed `document-card` through, but Atmosphere doesn't recognize
	 *   it and falls back to `link-card`, so that was the user's actual
	 *   effective behavior in production).
	 * - Anything else (empty, garbage, non-string) — coerce to the
	 *   FOSSE default; the deleted projector's coercion branch fired
	 *   in the same cases.
	 *
	 * @param mixed $stored Raw legacy option value.
	 * @return string Canonical Atmosphere strategy slug.
	 */
	private static function resolve_legacy_long_form_strategy( $stored ): string {
		if ( ! \is_string( $stored ) ) {
			return self::DEFAULT_LONG_FORM_STRATEGY;
		}
		if ( 'document-card' === $stored ) {
			return self::DOCUMENT_CARD_FALLBACK;
		}
		if ( \in_array( $stored, self::ATMOSPHERE_KNOWN_STRATEGIES, true ) ) {
			return $stored;
		}
		return self::DEFAULT_LONG_FORM_STRATEGY;
	}
}
