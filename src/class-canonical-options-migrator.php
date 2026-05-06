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
 * Runs at most once per site. Gated on the
 * `fosse_canonical_options_migrated` option so a partially-migrated
 * site converges on a re-visit of the admin and a fully-migrated site
 * skips the work entirely. Hooked on `admin_init` so the migration
 * never runs on a frontend request — bots and uncached pageviews
 * shouldn't multiply into option writes.
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
	 * projector used to coerce against; an unrecognized value is dropped
	 * during migration so the canonical option is never written with garbage.
	 *
	 * @var string[]
	 */
	private const KNOWN_LONG_FORM_STRATEGIES = array(
		'teaser-thread',
		'truncate-link',
		'link-card',
		'document-card',
	);

	/**
	 * FOSSE's preferred default for fresh installs. Atmosphere's own
	 * default is `'link-card'`; FOSSE's opinionated default is the
	 * teaser-thread strategy (per `sdd/long-form-bluesky-strategy/`).
	 *
	 * @var string
	 */
	private const DEFAULT_LONG_FORM_STRATEGY = 'teaser-thread';

	/**
	 * Wire the `admin_init` migration hook. Idempotent: WordPress dedupes
	 * identical callable-as-array registrations.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'admin_init', array( self::class, 'maybe_migrate' ) );
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
	 * On a fresh install with neither option set, seed
	 * `atmosphere_long_form_composition` with FOSSE's preferred default
	 * (`'teaser-thread'`) so installing FOSSE keeps opting users into the
	 * thread strategy without further configuration.
	 *
	 * @return void
	 */
	private static function migrate_long_form_strategy(): void {
		$stored = \get_option( 'fosse_long_form_strategy' );

		if ( false !== $stored ) {
			if ( \is_string( $stored ) && \in_array( $stored, self::KNOWN_LONG_FORM_STRATEGIES, true ) ) {
				\update_option( 'atmosphere_long_form_composition', $stored );
			}
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
