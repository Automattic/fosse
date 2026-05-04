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
	 * Wires the pre_update filter at default priority via register_hooks().
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_hooks_wires_pre_update_filter(): void {
		Actor_Mode_Lock::register_hooks();

		$this->assertNotFalse(
			has_filter(
				'pre_update_option_activitypub_actor_mode',
				array( Actor_Mode_Lock::class, 'coerce_to_forced_mode' )
			)
		);
	}

	/**
	 * End-to-end: with the hook wired and a constant set, an
	 * `update_option()` write of an arbitrary value is silently
	 * coerced to the forced mode in the database. Closes the bypass
	 * the UI hidden inputs alone could not.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_hooks_blocks_tampered_update_when_locked(): void {
		define( 'ACTIVITYPUB_SINGLE_USER_MODE', true );
		Actor_Mode_Lock::register_hooks();

		update_option( 'activitypub_actor_mode', 'actor_blog' );

		$this->assertSame( Actor_Mode_Lock::MODE_BLOG, get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Pass-through end-to-end: with the hook wired but no lock, a
	 * write proceeds untouched. Guards against a regression where
	 * the filter accidentally coerces every install.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_hooks_passes_through_when_unlocked(): void {
		Actor_Mode_Lock::register_hooks();

		update_option( 'activitypub_actor_mode', 'actor_blog' );

		$this->assertSame( 'actor_blog', get_option( 'activitypub_actor_mode' ) );
	}
}
