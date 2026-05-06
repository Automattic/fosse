<?php
/**
 * Tests for the one-time canonical-options migration.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Canonical_Options_Migrator;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the migrator correctly moves FOSSE-side projector options to
 * the canonical upstream options on first run, no-ops on subsequent runs,
 * and seeds the long-form composition default for fresh installs.
 */
class Canonical_Options_MigratorTest extends BaseTestCase {

	/**
	 * Reset the migration flag and both legacy + canonical options before
	 * each test so each scenario starts from a known, never-migrated state.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		delete_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION );
		delete_option( 'fosse_object_type' );
		delete_option( 'fosse_long_form_strategy' );
		delete_option( 'activitypub_object_type' );
		delete_option( 'atmosphere_long_form_composition' );
	}

	/**
	 * `fosse_object_type=note` migrates to `activitypub_object_type=note`
	 * and the legacy option is deleted. Locks in the only object-type
	 * value that materially differed from upstream pass-through.
	 */
	public function test_migrate_object_type_note_value(): void {
		update_option( 'fosse_object_type', 'note' );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'note', get_option( 'activitypub_object_type' ) );
		$this->assertFalse( get_option( 'fosse_object_type' ) );
	}

	/**
	 * Non-note legacy values are dropped without touching the canonical
	 * option — the deleted projector treated them as pass-throughs, so
	 * carrying them forward would fabricate state the user never asked
	 * for.
	 */
	public function test_migrate_object_type_drops_pass_through_values(): void {
		update_option( 'fosse_object_type', 'wordpress-post-format' );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertFalse( get_option( 'fosse_object_type' ) );
		// Canonical option is untouched (still the seeded default for fresh sites).
		$this->assertFalse( get_option( 'activitypub_object_type' ) );
	}

	/**
	 * Stored long-form strategy moves to the canonical Atmosphere option
	 * and the FOSSE-side option is deleted.
	 */
	public function test_migrate_long_form_strategy_truncate_link(): void {
		update_option( 'fosse_long_form_strategy', 'truncate-link' );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'truncate-link', get_option( 'atmosphere_long_form_composition' ) );
		$this->assertFalse( get_option( 'fosse_long_form_strategy' ) );
	}

	/**
	 * Conflict: when both legacy and canonical are set, preserve the
	 * canonical value. The canonical option is what the user can see
	 * and edit in Atmosphere's settings UI, so carrying the legacy
	 * value forward would silently change publishing behavior away
	 * from what the visible UI claims. The legacy value may have been
	 * an implicit default rather than an explicit user choice; the
	 * canonical option is always an explicit (or empty) state.
	 */
	public function test_migrate_long_form_strategy_preserves_canonical_on_conflict(): void {
		update_option( 'atmosphere_long_form_composition', 'link-card' );
		update_option( 'fosse_long_form_strategy', 'teaser-thread' );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'link-card', get_option( 'atmosphere_long_form_composition' ) );
		$this->assertFalse( get_option( 'fosse_long_form_strategy' ) );
	}

	/**
	 * Object-type conflict mirrors the long-form policy: when
	 * `activitypub_object_type` is already stored, keep it instead of
	 * overwriting from the legacy `fosse_object_type`. The legacy option
	 * is still deleted so the migration completes cleanly.
	 */
	public function test_migrate_object_type_preserves_canonical_on_conflict(): void {
		update_option( 'activitypub_object_type', 'wordpress-post-format' );
		update_option( 'fosse_object_type', 'note' );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'wordpress-post-format', get_option( 'activitypub_object_type' ) );
		$this->assertFalse( get_option( 'fosse_object_type' ) );
	}

	/**
	 * Object-type conflict fires the operator visibility hook so a site
	 * that wants to log/notice on this can wire it up. Drives the
	 * "canonical wins, but tell the operator" half of the conflict
	 * policy — the migration is silent in the database but observable
	 * via the action.
	 */
	public function test_migrate_object_type_conflict_fires_operator_hook(): void {
		update_option( 'activitypub_object_type', 'wordpress-post-format' );
		update_option( 'fosse_object_type', 'note' );

		$captured = array();
		add_action(
			'fosse_canonical_migration_conflict',
			static function ( $key, $legacy, $existing ) use ( &$captured ): void {
				$captured[] = compact( 'key', 'legacy', 'existing' );
			},
			10,
			3
		);

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertCount( 1, $captured );
		$this->assertSame( 'object_type', $captured[0]['key'] );
		$this->assertSame( 'note', $captured[0]['legacy'] );
		$this->assertSame( 'wordpress-post-format', $captured[0]['existing'] );
	}

	/**
	 * Long-form conflict fires the same operator hook. A canonical value
	 * that happens to match the resolved legacy value is *not* a
	 * conflict (no surprise to the operator), so the hook stays silent
	 * in that case.
	 */
	public function test_migrate_long_form_conflict_fires_operator_hook(): void {
		update_option( 'atmosphere_long_form_composition', 'link-card' );
		update_option( 'fosse_long_form_strategy', 'teaser-thread' );

		$captured = array();
		add_action(
			'fosse_canonical_migration_conflict',
			static function ( $key, $legacy, $existing ) use ( &$captured ): void {
				$captured[] = compact( 'key', 'legacy', 'existing' );
			},
			10,
			3
		);

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertCount( 1, $captured );
		$this->assertSame( 'long_form_strategy', $captured[0]['key'] );
		$this->assertSame( 'teaser-thread', $captured[0]['legacy'] );
		$this->assertSame( 'link-card', $captured[0]['existing'] );
	}

	/**
	 * Matching canonical and legacy values are not a conflict — operator
	 * hook stays silent so log noise tracks real disagreements only.
	 */
	public function test_migrate_long_form_matching_values_does_not_fire_operator_hook(): void {
		update_option( 'atmosphere_long_form_composition', 'link-card' );
		update_option( 'fosse_long_form_strategy', 'link-card' );

		$fired = false;
		add_action(
			'fosse_canonical_migration_conflict',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertFalse( $fired );
	}

	/**
	 * Unknown legacy values coerce to FOSSE's preferred default
	 * (`'teaser-thread'`) before being written to the canonical option.
	 * The deleted `Long_Form_Strategy` projector applied the same
	 * coercion at filter time; preserving it on the legacy-only path
	 * keeps the site's effective behavior consistent rather than
	 * silently falling through to Atmosphere's `'link-card'` default
	 * (or worse, leaving an upstream-rejected value in place).
	 */
	public function test_migrate_long_form_strategy_coerces_unknown_to_default(): void {
		update_option( 'fosse_long_form_strategy', 'garbage' );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'teaser-thread', get_option( 'atmosphere_long_form_composition' ) );
		$this->assertFalse( get_option( 'fosse_long_form_strategy' ) );
	}

	/**
	 * Empty-string legacy values follow the same coercion as unknown
	 * values — the deleted projector treated empty as "use default."
	 */
	public function test_migrate_long_form_strategy_coerces_empty_to_default(): void {
		update_option( 'fosse_long_form_strategy', '' );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'teaser-thread', get_option( 'atmosphere_long_form_composition' ) );
		$this->assertFalse( get_option( 'fosse_long_form_strategy' ) );
	}

	/**
	 * `document-card` is the deleted projector's forward-compat slot for
	 * the Atmosphere v2 renderer. The projector passed it through, but
	 * current Atmosphere doesn't recognize it and falls back to
	 * `'link-card'` — so the user's *effective* behavior was a single
	 * link card, not a teaser thread. Map to `'link-card'` to preserve
	 * that effective behavior on the legacy-only path; coercing to the
	 * FOSSE default would silently shift these sites to multi-post
	 * threads.
	 */
	public function test_migrate_long_form_strategy_maps_document_card_to_link_card(): void {
		update_option( 'fosse_long_form_strategy', 'document-card' );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'link-card', get_option( 'atmosphere_long_form_composition' ) );
		$this->assertFalse( get_option( 'fosse_long_form_strategy' ) );
	}

	/**
	 * Fresh install (neither option set): seed Atmosphere's option with
	 * FOSSE's preferred default so installing FOSSE keeps opting users
	 * into the teaser-thread strategy without further configuration.
	 */
	public function test_migrate_seeds_default_long_form_for_fresh_install(): void {
		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'teaser-thread', get_option( 'atmosphere_long_form_composition' ) );
	}

	/**
	 * Fresh install where Atmosphere's option is already set: do NOT seed
	 * over it. A site that already configured Atmosphere standalone before
	 * installing FOSSE keeps its choice.
	 */
	public function test_migrate_does_not_overwrite_existing_canonical_long_form(): void {
		update_option( 'atmosphere_long_form_composition', 'link-card' );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'link-card', get_option( 'atmosphere_long_form_composition' ) );
	}

	/**
	 * The migration sets the completion flag so it never runs twice.
	 */
	public function test_migrate_marks_completion_flag(): void {
		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( '1', (string) get_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION ) );
	}

	/**
	 * Subsequent runs are no-ops: the flag short-circuits the work even if
	 * legacy options have somehow been re-introduced.
	 */
	public function test_migrate_is_idempotent(): void {
		Canonical_Options_Migrator::maybe_migrate();

		// Re-introduce a legacy option after the first migration completed.
		update_option( 'fosse_object_type', 'note' );

		Canonical_Options_Migrator::maybe_migrate();

		// The legacy option is untouched — second run did not re-run the work.
		$this->assertSame( 'note', get_option( 'fosse_object_type' ) );
	}

	/**
	 * `register()` wires the migration onto `init` priority 5 so the
	 * migration completes before the Object_Type bridge filter runs at
	 * priority 10 (and before any post publish path queries the
	 * canonical option). After the flag is set, the hook is a single
	 * cached option-read per request.
	 */
	public function test_register_attaches_init_hook_at_priority_5(): void {
		remove_all_actions( 'init' );

		Canonical_Options_Migrator::register();

		$this->assertSame(
			5,
			has_action( 'init', array( Canonical_Options_Migrator::class, 'maybe_migrate' ) ),
			'register() must hook maybe_migrate onto init at priority 5.'
		);
	}

	/**
	 * Bootstrap-order regression: the migrator must be reachable when
	 * registered from `plugins_loaded` (per `fosse.php`) so the priority-5
	 * `init` callback lands in the same iteration that ultimately fires
	 * the bridge at priority 10. An earlier draft of this PR registered
	 * the migrator from inside an `init` default-priority callback, which
	 * missed the priority-5 slot in the active iteration and the
	 * migration silently never ran.
	 */
	public function test_plugins_loaded_registration_runs_migration_on_init(): void {
		remove_all_actions( 'plugins_loaded' );
		remove_all_actions( 'init' );

		// Mirror fosse.php's plugins_loaded callback — class-existence guard
		// then register().
		add_action(
			'plugins_loaded',
			static function (): void {
				if ( ! class_exists( Canonical_Options_Migrator::class ) ) {
					return;
				}
				Canonical_Options_Migrator::register();
			}
		);

		update_option( 'fosse_object_type', 'note' );
		update_option( 'fosse_long_form_strategy', 'truncate-link' );

		do_action( 'plugins_loaded' );
		do_action( 'init' );

		$this->assertSame( 'note', get_option( 'activitypub_object_type' ) );
		$this->assertSame( 'truncate-link', get_option( 'atmosphere_long_form_composition' ) );
		$this->assertFalse( get_option( 'fosse_object_type' ) );
		$this->assertFalse( get_option( 'fosse_long_form_strategy' ) );
		$this->assertSame( '1', (string) get_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION ) );
	}
}
