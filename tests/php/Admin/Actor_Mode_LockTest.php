<?php
/**
 * Tests for Actor_Mode_Lock — the helper that mirrors bundled AP's
 * `pre_option_activitypub_actor_mode` filter so FOSSE's own admin UI
 * and save layer honor the same constant-based actor-mode locks.
 *
 * Constants are sticky for the PHP process lifetime, so each truth-table
 * row runs in its own subprocess. Without isolation, the first test
 * to define a constant would force every subsequent test into the
 * same locked branch, masking the matrix entirely.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Actor_Mode_Lock;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WorDBless\BaseTestCase;

/**
 * Locks bundled AP's three actor-mode constants behind FOSSE's helper.
 */
class Actor_Mode_LockTest extends BaseTestCase {

	/**
	 * No constants defined: helper reports the option is freely settable.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_forced_mode_returns_null_when_no_constants_defined(): void {
		$this->assertNull( Actor_Mode_Lock::forced_mode() );
		$this->assertFalse( Actor_Mode_Lock::is_locked() );
	}

	/**
	 * `ACTIVITYPUB_SINGLE_USER_MODE` is the wp.com-platform lock.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_single_user_mode_locks_to_blog(): void {
		define( 'ACTIVITYPUB_SINGLE_USER_MODE', true );

		$this->assertSame( Actor_Mode_Lock::MODE_BLOG, Actor_Mode_Lock::forced_mode() );
		$this->assertTrue( Actor_Mode_Lock::is_locked() );
	}

	/**
	 * `ACTIVITYPUB_DISABLE_USER` also locks to blog mode (mirrors AP).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_disable_user_locks_to_blog(): void {
		define( 'ACTIVITYPUB_DISABLE_USER', true );

		$this->assertSame( Actor_Mode_Lock::MODE_BLOG, Actor_Mode_Lock::forced_mode() );
		$this->assertTrue( Actor_Mode_Lock::is_locked() );
	}

	/**
	 * `ACTIVITYPUB_DISABLE_BLOG_USER` flips the lock to author mode.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_disable_blog_user_locks_to_actor(): void {
		define( 'ACTIVITYPUB_DISABLE_BLOG_USER', true );

		$this->assertSame( Actor_Mode_Lock::MODE_ACTOR, Actor_Mode_Lock::forced_mode() );
		$this->assertTrue( Actor_Mode_Lock::is_locked() );
	}

	/**
	 * Precedence regression canary: `SINGLE_USER_MODE` wins over
	 * `DISABLE_BLOG_USER` (the two return different forced modes).
	 * If a future refactor reorders the checks, this test fails.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_single_user_mode_takes_precedence_over_disable_blog_user(): void {
		define( 'ACTIVITYPUB_SINGLE_USER_MODE', true );
		define( 'ACTIVITYPUB_DISABLE_BLOG_USER', true );

		$this->assertSame( Actor_Mode_Lock::MODE_BLOG, Actor_Mode_Lock::forced_mode() );
	}

	/**
	 * Skip-on-falsy: a constant defined to false (or 0) does not lock,
	 * so a lower-priority truthy constant still takes effect.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_falsy_single_user_mode_does_not_lock(): void {
		define( 'ACTIVITYPUB_SINGLE_USER_MODE', false );
		define( 'ACTIVITYPUB_DISABLE_BLOG_USER', true );

		$this->assertSame( Actor_Mode_Lock::MODE_ACTOR, Actor_Mode_Lock::forced_mode() );
	}

	/**
	 * The locked notice is non-empty and preserves the warning glyph
	 * (translators are explicitly told to keep it). Pure string check;
	 * runs in the main process.
	 */
	public function test_locked_notice_includes_warning_glyph(): void {
		$notice = Actor_Mode_Lock::locked_notice();

		$this->assertNotEmpty( $notice );
		$this->assertStringContainsString( '⚠', $notice );
	}

	/**
	 * Coerce passes the incoming value through when no lock is active.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_coerce_passes_through_when_unlocked(): void {
		$this->assertSame( 'actor_blog', Actor_Mode_Lock::coerce_to_forced_mode( 'actor_blog' ) );
		$this->assertSame( 'arbitrary', Actor_Mode_Lock::coerce_to_forced_mode( 'arbitrary' ) );
	}

	/**
	 * Coerce overrides any incoming value with the forced mode when locked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_coerce_overrides_when_locked(): void {
		define( 'ACTIVITYPUB_SINGLE_USER_MODE', true );

		$this->assertSame( Actor_Mode_Lock::MODE_BLOG, Actor_Mode_Lock::coerce_to_forced_mode( 'actor_blog' ) );
		$this->assertSame( Actor_Mode_Lock::MODE_BLOG, Actor_Mode_Lock::coerce_to_forced_mode( 'tampered-value' ) );
	}

	/**
	 * Wires both the pre_update filter and the admin_init repair action
	 * via register_hooks().
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_hooks_wires_filter_and_admin_init_action(): void {
		Actor_Mode_Lock::register_hooks();

		$this->assertNotFalse(
			has_filter(
				'pre_update_option_activitypub_actor_mode',
				array( Actor_Mode_Lock::class, 'coerce_to_forced_mode' )
			),
			'pre_update filter should be wired'
		);
		$this->assertNotFalse(
			has_action(
				'admin_init',
				array( Actor_Mode_Lock::class, 'repair_stored_value' )
			),
			'admin_init repair action should be wired'
		);
	}

	/**
	 * Register-hooks is idempotent — repeated calls don't double-register.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_hooks_is_idempotent(): void {
		Actor_Mode_Lock::register_hooks();
		Actor_Mode_Lock::register_hooks();
		Actor_Mode_Lock::register_hooks();

		$this->assertSame( 10, has_filter( 'pre_update_option_activitypub_actor_mode', array( Actor_Mode_Lock::class, 'coerce_to_forced_mode' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( Actor_Mode_Lock::class, 'repair_stored_value' ) ) );
	}

	/**
	 * Repair writes the forced mode when no value exists yet.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_repair_writes_when_no_value_exists(): void {
		define( 'ACTIVITYPUB_SINGLE_USER_MODE', true );

		delete_option( 'activitypub_actor_mode' );

		Actor_Mode_Lock::repair_stored_value();

		$this->assertSame( Actor_Mode_Lock::MODE_BLOG, get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Repair rewrites a stored value that disagrees with the lock —
	 * models the "value written before the lock activated" path.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_repair_updates_when_value_disagrees_with_lock(): void {
		// Seed a value as if it was written before the lock shipped.
		update_option( 'activitypub_actor_mode', 'actor_blog' );

		define( 'ACTIVITYPUB_SINGLE_USER_MODE', true );

		Actor_Mode_Lock::repair_stored_value();

		$this->assertSame( Actor_Mode_Lock::MODE_BLOG, get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Repair leaves a correctly-aligned value untouched.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_repair_no_ops_when_value_already_matches_lock(): void {
		update_option( 'activitypub_actor_mode', 'blog' );

		define( 'ACTIVITYPUB_SINGLE_USER_MODE', true );

		Actor_Mode_Lock::repair_stored_value();

		$this->assertSame( 'blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Repair is a no-op on unlocked installs — a stored value disagreeing
	 * with the default is left alone.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_repair_no_ops_when_unlocked(): void {
		update_option( 'activitypub_actor_mode', 'actor_blog' );

		Actor_Mode_Lock::repair_stored_value();

		$this->assertSame( 'actor_blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Production-faithful regression: with bundled AP's actual
	 * `Activitypub\Options::pre_option_activitypub_actor_mode` mask
	 * registered, the masked `get_option` call inside `update_option`
	 * makes old-value-via-mask equal to new-value-via-coerce, so the
	 * naive write path short-circuits. The repair pass unhooks AP's
	 * mask, sees the actual stored value, writes the forced value
	 * through, and restores the mask. After repair, removing the mask
	 * exposes the corrected raw value. Reproduces (and fixes) the
	 * failure mode codex called out on round-2 review.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_repair_fixes_value_under_active_pre_option_mask(): void {
		// Seed a tampered value as if it landed before the lock shipped.
		update_option( 'activitypub_actor_mode', 'actor_blog' );

		define( 'ACTIVITYPUB_SINGLE_USER_MODE', true );

		// Register bundled AP's actual pre_option mask so the test
		// exercises the production code path (not an inline closure
		// that the repair couldn't unhook).
		$ap_mask = array( 'Activitypub\Options', 'pre_option_activitypub_actor_mode' );
		add_filter( 'pre_option_activitypub_actor_mode', $ap_mask );

		// With the mask active, get_option reports the forced mode
		// regardless of what's stored — confirming the mask is in play.
		$this->assertSame( 'blog', get_option( 'activitypub_actor_mode' ), 'mask should make get_option report blog' );

		Actor_Mode_Lock::register_hooks();
		Actor_Mode_Lock::repair_stored_value();

		// Mask restored after repair — get_option still reports masked.
		$this->assertSame( 'blog', get_option( 'activitypub_actor_mode' ) );

		// Remove the mask to inspect the raw stored value.
		remove_filter( 'pre_option_activitypub_actor_mode', $ap_mask );
		$this->assertSame( Actor_Mode_Lock::MODE_BLOG, get_option( 'activitypub_actor_mode' ), 'repair should have rewritten the stored value to the forced mode' );
	}
}
