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
	 * migrating. Mirrors the strategies the deleted `Long_Form_Strategy`
	 * projector returned as-is. Atmosphere itself accepts only the first
	 * three (`Atmosphere::LONG_FORM_STRATEGIES`); legacy values outside
	 * the upstream enum are coerced to the fallback default below to
	 * preserve the deleted projector's coercion behavior.
	 *
	 * @var string[]
	 */
	private const ATMOSPHERE_KNOWN_STRATEGIES = array(
		'teaser-thread',
		'truncate-link',
		'link-card',
	);

	/**
	 * FOSSE's preferred default. The deleted `Long_Form_Strategy`
	 * projector coerced unset / empty / unrecognized option values to
	 * this strategy, so the migrator preserves that behavior: a legacy
	 * value Atmosphere wouldn't accept maps here rather than dropping
	 * silently and falling through to Atmosphere's `'link-card'`
	 * default. Per `sdd/long-form-bluesky-strategy/`.
	 *
	 * @var string
	 */
	private const DEFAULT_LONG_FORM_STRATEGY = 'teaser-thread';

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
	 * Copy `fosse_object_type=note` to `activitypub_object_type=note` (the
	 * only FOSSE-set value that materially differs from upstream defaults),
	 * then delete the FOSSE-side option. Other stored values were
	 * pass-throughs in the deleted projector and need no migration.
	 *
	 * @return void
	 */
	private static function migrate_object_type(): void {
		$stored = \get_option( 'fosse_object_type' );

		if ( 'note' === $stored ) {
			\update_option( 'activitypub_object_type', 'note' );
		}

		if ( false !== $stored ) {
			\delete_option( 'fosse_object_type' );
		}
	}

	/**
	 * Move `fosse_long_form_strategy` into `atmosphere_long_form_composition`
	 * and delete the FOSSE-side option. Always overwrites the upstream value
	 * because this site previously expressed its long-form choice via FOSSE,
	 * which silently overrode whatever Atmosphere had stored.
	 *
	 * Empty / unknown / non-string legacy values coerce to
	 * `self::DEFAULT_LONG_FORM_STRATEGY` rather than dropping. The deleted
	 * `Long_Form_Strategy` projector applied the same coercion at filter
	 * time, so preserving it here keeps the site's effective behavior
	 * consistent across the migration boundary.
	 *
	 * On a fresh install with neither option set, seed
	 * `atmosphere_long_form_composition` with the same default so
	 * installing FOSSE keeps opting users into the thread strategy
	 * without further configuration.
	 *
	 * @return void
	 */
	private static function migrate_long_form_strategy(): void {
		$stored = \get_option( 'fosse_long_form_strategy' );

		if ( false !== $stored ) {
			$resolved = \is_string( $stored ) && \in_array( $stored, self::ATMOSPHERE_KNOWN_STRATEGIES, true )
				? $stored
				: self::DEFAULT_LONG_FORM_STRATEGY;

			\update_option( 'atmosphere_long_form_composition', $resolved );
			\delete_option( 'fosse_long_form_strategy' );
			return;
		}

		// Fresh install: seed the FOSSE default if and only if Atmosphere
		// hasn't been configured. Use a default-sentinel argument to
		// distinguish "never set" from "explicitly set to ''".
		$sentinel  = '__fosse_unset__';
		$atmo_long = \get_option( 'atmosphere_long_form_composition', $sentinel );
		if ( $sentinel === $atmo_long ) {
			\update_option( 'atmosphere_long_form_composition', self::DEFAULT_LONG_FORM_STRATEGY );
		}
	}
}
