<?php
/**
 * Tests for the Plugins-screen handoff row beneath FOSSE.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Standalone_Handoff_Notice;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the row appears only when at least one standalone backend is
 * active, names the standalone(s) correctly, and respects capability.
 */
class Standalone_Handoff_NoticeTest extends BaseTestCase {

	/**
	 * Reset capability state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		wp_set_current_user( 0 );
	}

	/**
	 * Promote the current user so capability checks pass.
	 *
	 * @return void
	 */
	private function become_plugin_manager(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'handoff-test-admin',
				'user_email' => 'handoff-test@example.org',
				'user_pass'  => 'handoff-test-pass',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );
	}

	/**
	 * Standalone ActivityPub only → row mentions ActivityPub in singular form.
	 */
	public function test_row_renders_for_standalone_activitypub(): void {
		$this->become_plugin_manager();

		$html = Standalone_Handoff_Notice::render_for_active_plugins( array( 'activitypub/activitypub.php' ) );

		$this->assertNotSame( '', $html );
		$this->assertStringContainsString( 'ActivityPub', $html );
		$this->assertStringNotContainsString( 'Atmosphere', $html );
		$this->assertStringContainsString( 'standalone ActivityPub plugin', $html );
	}

	/**
	 * Standalone Atmosphere only → row mentions Atmosphere in singular form.
	 */
	public function test_row_renders_for_standalone_atmosphere(): void {
		$this->become_plugin_manager();

		$html = Standalone_Handoff_Notice::render_for_active_plugins( array( 'atmosphere/atmosphere.php' ) );

		$this->assertNotSame( '', $html );
		$this->assertStringContainsString( 'Atmosphere', $html );
		$this->assertStringNotContainsString( 'ActivityPub', $html );
		$this->assertStringContainsString( 'standalone Atmosphere plugin', $html );
	}

	/**
	 * Both standalone backends active → row names both, plural plugin label.
	 */
	public function test_row_renders_for_both_standalones(): void {
		$this->become_plugin_manager();

		$html = Standalone_Handoff_Notice::render_for_active_plugins(
			array( 'activitypub/activitypub.php', 'atmosphere/atmosphere.php' )
		);

		$this->assertStringContainsString( 'ActivityPub', $html );
		$this->assertStringContainsString( 'Atmosphere', $html );
		$this->assertStringContainsString( 'plugins', $html );
	}

	/**
	 * Neither standalone active → row is silent.
	 */
	public function test_row_silent_when_no_standalone_active(): void {
		$this->become_plugin_manager();

		$html = Standalone_Handoff_Notice::render_for_active_plugins( array() );

		$this->assertSame( '', $html );
	}

	/**
	 * Logged-out / non-admin viewer → row is silent regardless of which
	 * standalones are active.
	 */
	public function test_row_silent_for_user_without_activate_plugins(): void {
		// reset_state() already cleared the current user; no promotion.

		$html = Standalone_Handoff_Notice::render_for_active_plugins( array( 'activitypub/activitypub.php' ) );

		$this->assertSame( '', $html );
	}
}
