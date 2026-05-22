<?php
/**
 * Tests for the FOSSE Status page.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\AP_Provider;
use Automattic\Fosse\Admin\Bluesky_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use Automattic\Fosse\Admin\Status_Page;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the Status dashboard describes provider state clearly.
 */
class Status_PageTest extends BaseTestCase {

	/**
	 * Reset provider and option state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
		Bluesky_Provider::register_provider();

		delete_option( 'activitypub_actor_mode' );
		delete_option( 'activitypub_support_post_types' );
		delete_option( 'atmosphere_connection' );

		$this->become_admin();
	}

	/**
	 * Clear request-scoped state after each test.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_state(): void {
		Connection_Provider_Registry::reset();
		wp_set_current_user( 0 );
	}

	/**
	 * Provider summary uses "active" so ActivityPub does not read like an
	 * OAuth connection to an external Mastodon account.
	 */
	public function test_render_summary_uses_active_provider_language(): void {
		$output = $this->capture_render();

		$this->assertStringContainsString( 'Provider status', $output );
		$this->assertStringContainsString( '1 of 2 providers active', $output );
		$this->assertStringContainsString( 'See whether each network is active and which identities FOSSE will publish from.', $output );
		$this->assertStringNotContainsString( 'providers connected', $output );
		$this->assertStringNotContainsString( 'See whether each network is connected', $output );
	}

	/**
	 * Render the Status page and return its output.
	 *
	 * @return string
	 */
	private function capture_render(): string {
		ob_start();
		Status_Page::render();
		return (string) ob_get_clean();
	}

	/**
	 * Create and authenticate an administrator.
	 *
	 * @return void
	 */
	private function become_admin(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_status_admin_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );
	}

	/**
	 * Fail the test if the value is a WP_Error.
	 *
	 * @param mixed $value Value to check.
	 * @return void
	 */
	private function assertNotWPError( $value ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- camelCase mirrors PHPUnit's assertion style.
		if ( is_wp_error( $value ) ) {
			$this->fail( 'Unexpected WP_Error: ' . $value->get_error_message() );
		}
		$this->assertFalse( is_wp_error( $value ) );
	}
}
