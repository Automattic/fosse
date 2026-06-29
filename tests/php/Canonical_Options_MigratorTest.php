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
	 * Hook-order independence: the sentinel read in `migrate_object_type()`
	 * must correctly detect an unset canonical option even when AP's
	 * `option_activitypub_object_type` filter (which coerces falsy values to
	 * the AP default) is already registered. The migration runs at init
	 * priority 5 and AP's `Options::init` at priority 10, so today the
	 * filter is not yet attached when the migrator reads — but the read must
	 * not depend on that ordering. Here we register AP's exact filter shape
	 * before migrating to prove the sentinel survives: WordPress only applies
	 * the `option_{$option}` filter when the row exists, so an unset option
	 * returns the verbatim sentinel and the legacy `note` value still copies
	 * across. If the read regressed (e.g. the filter coerced the sentinel),
	 * `migrate_object_type` would treat the canonical option as explicitly
	 * set and silently discard the legacy value, firing the conflict action
	 * instead of copying.
	 */
	public function test_migrate_object_type_sentinel_survives_ap_option_filter(): void {
		if ( ! class_exists( '\Activitypub\Options' ) ) {
			$this->markTestSkipped( 'Bundled ActivityPub not loaded — cannot exercise the real default_object_type callback.' );
		}

		update_option( 'fosse_object_type', 'note' );

		// Register the REAL bundled callback — not a copy. Any upstream
		// change to `Activitypub\Options::default_object_type()` (e.g.
		// future versions that start coercing unknown non-empty strings
		// to the default) flows straight through this test, so a
		// sentinel-coerced-away failure mode surfaces immediately rather
		// than a year later when production trips it.
		add_filter( 'option_activitypub_object_type', array( '\Activitypub\Options', 'default_object_type' ) );

		$conflict_fired = false;
		add_action(
			'fosse_canonical_migration_conflict',
			static function () use ( &$conflict_fired ): void {
				$conflict_fired = true;
			}
		);

		Canonical_Options_Migrator::maybe_migrate();

		remove_filter( 'option_activitypub_object_type', array( '\Activitypub\Options', 'default_object_type' ) );

		// Sentinel was seen as unset, so the legacy value copied across and
		// no conflict was reported.
		$this->assertSame( 'note', get_option( 'activitypub_object_type' ) );
		$this->assertFalse( get_option( 'fosse_object_type' ) );
		$this->assertFalse( $conflict_fired, 'Unset canonical option must not be treated as a conflict.' );
	}

	/**
	 * Defense-in-depth for a hypothetical future AP that moves the default
	 * onto the `default_option_*` filter (which, unlike `option_*`, DOES run
	 * on the absent-row path). The sentinel is a non-empty string, so AP's
	 * `! $value` coercion leaves it untouched and the unset detection still
	 * holds. Pins the second of the two independent grounds the sentinel
	 * read relies on.
	 */
	public function test_migrate_object_type_sentinel_survives_ap_default_option_filter(): void {
		if ( ! class_exists( '\Activitypub\Options' ) ) {
			$this->markTestSkipped( 'Bundled ActivityPub not loaded.' );
		}

		update_option( 'fosse_object_type', 'note' );

		add_filter( 'default_option_activitypub_object_type', array( '\Activitypub\Options', 'default_object_type' ) );

		Canonical_Options_Migrator::maybe_migrate();

		remove_filter( 'default_option_activitypub_object_type', array( '\Activitypub\Options', 'default_object_type' ) );

		$this->assertSame( 'note', get_option( 'activitypub_object_type' ) );
		$this->assertFalse( get_option( 'fosse_object_type' ) );
	}

	/**
	 * Hostile upstream: a future AP (or a third-party policy filter) that
	 * coerces ANY value — including our `__fosse_unset__` sentinel — to
	 * the default would silently break unset-detection. A bare conflict
	 * signal is NOT enough to satisfy this regression — the user's
	 * `fosse_object_type=note` data would still be gone forever even
	 * though a hook fired. The durable outcome must protect the user's
	 * data: either the canonical row holds `'note'` (copy succeeded) or
	 * the legacy row still holds `'note'` (preserve-on-uncertainty). The
	 * conflict signal alone, with both options drifted off `'note'`, is
	 * a silent destruction with a hook — that's what we never accept.
	 */
	/**
	 * A `pre_option_*` filter short-circuits `get_option()` BEFORE any
	 * cache/DB lookup, so a pinned non-`false` return makes an absent
	 * row look present. Without detaching this chain alongside the
	 * `option_*` and `default_option_*` chains, the row-existence probe
	 * would still misclassify the canonical as "explicitly set" and the
	 * migration would delete the legacy `'note'` without copying it.
	 * Regression guard.
	 */
	public function test_migrate_object_type_does_not_silently_destroy_legacy_when_pre_option_overrides(): void {
		update_option( 'fosse_object_type', 'note' );

		$short_circuit = static function () {
			return 'wordpress-post-format';
		};
		add_filter( 'pre_option_activitypub_object_type', $short_circuit );

		Canonical_Options_Migrator::maybe_migrate();

		remove_filter( 'pre_option_activitypub_object_type', $short_circuit );

		$canonical_copied = 'note' === get_option( 'activitypub_object_type' );
		$legacy_preserved = 'note' === get_option( 'fosse_object_type' );

		$this->assertTrue(
			$canonical_copied || $legacy_preserved,
			'When pre_option_* pins a default for an absent row, the migration must either complete the copy or keep the legacy intact.'
		);
	}

	/**
	 * Hostile upstream: a future AP (or a third-party policy filter) that
	 * coerces every value — including our `__fosse_unset__` sentinel — to
	 * the AP default would silently break unset-detection. The durable
	 * outcome must protect user state: either the canonical row holds
	 * `'note'` (copy succeeded) or the legacy row still holds it
	 * (preserve-on-uncertainty). A bare conflict signal with both options
	 * drifted is silent destruction; that is what we never accept.
	 */
	public function test_migrate_object_type_does_not_silently_destroy_legacy_when_sentinel_coerced(): void {
		update_option( 'fosse_object_type', 'note' );

		$hostile = static function ( $value ) {
			if ( '__fosse_unset__' === $value || ! $value ) {
				return 'wordpress-post-format';
			}
			return $value;
		};
		add_filter( 'option_activitypub_object_type', $hostile );
		add_filter( 'default_option_activitypub_object_type', $hostile );

		Canonical_Options_Migrator::maybe_migrate();

		remove_filter( 'option_activitypub_object_type', $hostile );
		remove_filter( 'default_option_activitypub_object_type', $hostile );

		$canonical_copied = 'note' === get_option( 'activitypub_object_type' );
		$legacy_preserved = 'note' === get_option( 'fosse_object_type' );

		$this->assertTrue(
			$canonical_copied || $legacy_preserved,
			"When the sentinel is coerced away, the migration must EITHER copy 'note' to the canonical option OR keep the legacy option intact. Discarding the legacy without copying — even if a conflict hook fires — silently destroys the user's stored choice."
		);
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

	/**
	 * If `update_option` for the canonical AP option fails to converge
	 * (here forced via a `pre_update_option_*` filter that intercepts
	 * the write), the migrator must NOT delete the legacy option and
	 * MUST NOT set the completion flag — leaving the site in its
	 * pre-migration state so the next request retries. The alternative
	 * (delete legacy + set flag anyway) would lock the site in a
	 * half-migrated state with neither option holding the right value
	 * and the migrator never running again.
	 */
	public function test_migrate_object_type_failure_retries_on_next_request(): void {
		update_option( 'fosse_object_type', 'note' );

		// Block the canonical write by short-circuiting the update via the
		// pre_update_option filter. Returning the existing (sentinel)
		// value tells WordPress "no change" and the option stays unset.
		$short_circuit = static function ( $value, $old_value ) {
			return $old_value;
		};
		add_filter( 'pre_update_option_activitypub_object_type', $short_circuit, 10, 2 );

		$failures = array();
		add_action(
			'fosse_canonical_migration_failed',
			static function ( $key, $attempted, $actual ) use ( &$failures ): void {
				$failures[] = compact( 'key', 'attempted', 'actual' );
			},
			10,
			3
		);

		Canonical_Options_Migrator::maybe_migrate();

		// Canonical write was rejected, so the legacy option is preserved
		// and the completion flag is unset — first request retries on the
		// next load.
		$this->assertFalse( get_option( 'activitypub_object_type' ) );
		$this->assertSame( 'note', get_option( 'fosse_object_type' ) );
		$this->assertFalse( get_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION ) );
		$this->assertCount( 1, $failures );
		$this->assertSame( 'object_type', $failures[0]['key'] );
		$this->assertSame( 'note', $failures[0]['attempted'] );

		remove_filter( 'pre_update_option_activitypub_object_type', $short_circuit, 10 );

		// Second pass with the filter removed: migration converges and
		// the flag finally lands.
		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'note', get_option( 'activitypub_object_type' ) );
		$this->assertFalse( get_option( 'fosse_object_type' ) );
		$this->assertSame( '1', (string) get_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION ) );
	}

	/**
	 * Same retry contract for the long-form half: if the Atmosphere
	 * canonical write is intercepted, the legacy option is preserved
	 * and the completion flag stays unset so the migrator runs again
	 * next request.
	 */
	public function test_migrate_long_form_failure_retries_on_next_request(): void {
		update_option( 'fosse_long_form_strategy', 'truncate-link' );

		$short_circuit = static function ( $value, $old_value ) {
			return $old_value;
		};
		add_filter( 'pre_update_option_atmosphere_long_form_composition', $short_circuit, 10, 2 );

		$failures = array();
		add_action(
			'fosse_canonical_migration_failed',
			static function ( $key, $attempted, $actual ) use ( &$failures ): void {
				$failures[] = compact( 'key', 'attempted', 'actual' );
			},
			10,
			3
		);

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertFalse( get_option( 'atmosphere_long_form_composition' ) );
		$this->assertSame( 'truncate-link', get_option( 'fosse_long_form_strategy' ) );
		$this->assertFalse( get_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION ) );
		$this->assertCount( 1, $failures );
		$this->assertSame( 'long_form_strategy', $failures[0]['key'] );
		$this->assertSame( 'truncate-link', $failures[0]['attempted'] );

		remove_filter( 'pre_update_option_atmosphere_long_form_composition', $short_circuit, 10 );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'truncate-link', get_option( 'atmosphere_long_form_composition' ) );
		$this->assertFalse( get_option( 'fosse_long_form_strategy' ) );
		$this->assertSame( '1', (string) get_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION ) );
	}

	/**
	 * The fresh-install seed path must also report failure so the
	 * completion flag stays unset and the seed retries on the next
	 * request. Without the failure-aware return path the migrator would
	 * mark itself complete with the canonical option stuck unset, and
	 * Atmosphere would silently fall through to its `'link-card'`
	 * default — opposite of the FOSSE preference the seed exists to
	 * preserve.
	 */
	public function test_migrate_fresh_install_seed_failure_retries_on_next_request(): void {
		$short_circuit = static function ( $value, $old_value ) {
			return $old_value;
		};
		add_filter( 'pre_update_option_atmosphere_long_form_composition', $short_circuit, 10, 2 );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertFalse( get_option( 'atmosphere_long_form_composition' ) );
		$this->assertFalse( get_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION ) );

		remove_filter( 'pre_update_option_atmosphere_long_form_composition', $short_circuit, 10 );

		Canonical_Options_Migrator::maybe_migrate();

		$this->assertSame( 'teaser-thread', get_option( 'atmosphere_long_form_composition' ) );
		$this->assertSame( '1', (string) get_option( Canonical_Options_Migrator::MIGRATED_FLAG_OPTION ) );
	}
}
