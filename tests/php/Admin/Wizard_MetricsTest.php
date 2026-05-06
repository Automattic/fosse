<?php
/**
 * Tests for wizard-emitted FOSSE metrics events.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Onboarding_Wizard;
use Automattic\Fosse\Tests\Metrics\Asserts_Metrics;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies `fosse_wizard_started` is per-user-deduped, `fosse_wizard_completed`
 * carries the documented properties, and entry-source defaulting works.
 */
class Wizard_MetricsTest extends BaseTestCase {

	use Asserts_Metrics;

	/**
	 * Reset wizard + metrics state, log in as an admin user.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		\delete_option( Onboarding_Wizard::COMPLETED_OPTION );
		\delete_option( Onboarding_Wizard::DESTINATION_OPTION );
		\delete_option( 'activitypub_actor_mode' );
		\delete_option( 'activitypub_support_post_types' );

		$user_id = \wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_metrics_' . \uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertFalse( \is_wp_error( $user_id ), 'wp_insert_user must succeed in test setup.' );
		\wp_set_current_user( $user_id );

		$_GET = array();

		$this->reset_metrics_channels();
	}

	/**
	 * First render on the destinations step emits `fosse_wizard_started`.
	 */
	public function test_first_render_emits_started(): void {
		$_GET['fosse_entry'] = 'auto';

		\ob_start();
		Onboarding_Wizard::render();
		\ob_end_clean();

		$this->assertEventRecorded(
			'fosse_wizard_started',
			array( 'entry' => 'auto' )
		);
	}

	/**
	 * Second render in the same user's session does not re-emit.
	 */
	public function test_repeat_render_dedupes(): void {
		\ob_start();
		Onboarding_Wizard::render();
		Onboarding_Wizard::render();
		\ob_end_clean();

		$captured = $this->tracks_channel()->events_for( 'fosse_wizard_started' );
		$this->assertCount( 1, $captured, 'Wizard started must dedupe per user.' );
	}

	/**
	 * Unknown `?fosse_entry` value falls back to `'menu'`.
	 */
	public function test_entry_falls_back_to_menu(): void {
		$_GET['fosse_entry'] = 'pretend-evil';

		\ob_start();
		Onboarding_Wizard::render();
		\ob_end_clean();

		$this->assertEventRecorded(
			'fosse_wizard_started',
			array( 'entry' => 'menu' )
		);
	}

	/**
	 * `handle_complete` emits `fosse_wizard_completed` with destination,
	 * actor mode, post-types-count bucket, and bluesky-state.
	 */
	public function test_handle_complete_emits_with_properties(): void {
		\update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );
		\update_option( 'activitypub_actor_mode', 'blog' );
		\update_option( 'activitypub_support_post_types', array( 'post', 'page' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_complete',
			'_wpnonce' => \wp_create_nonce( 'fosse_wizard_complete' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		\add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			Onboarding_Wizard::handle_complete();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$captured = $this->tracks_channel()->events_for( 'fosse_wizard_completed' );
		$this->assertCount( 1, $captured );

		$properties = $captured[0]['properties'];
		$this->assertSame( 'fediverse_only', $properties['destination'] );
		$this->assertSame( 'blog', $properties['actor_mode'] );
		$this->assertSame( '2-3', $properties['post_types_count_bucket'] );
		// `bluesky_state` is `'connected'`, `'skipped'`, or `'unavailable'` —
		// in WorDBless without an Atmosphere connection it's deterministic
		// at `'skipped'` (the function exists but is not connected).
		$this->assertContains( $properties['bluesky_state'], array( 'connected', 'skipped', 'unavailable' ) );
	}

	/**
	 * `handle_reset` clears the per-user dedup so a re-run emits started again.
	 */
	public function test_reset_clears_started_dedup(): void {
		\ob_start();
		Onboarding_Wizard::render();
		\ob_end_clean();

		$this->assertCount( 1, $this->tracks_channel()->events_for( 'fosse_wizard_started' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_reset',
			'_wpnonce' => \wp_create_nonce( 'fosse_wizard_reset' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		\add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);

		try {
			Onboarding_Wizard::handle_reset();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		\ob_start();
		Onboarding_Wizard::render();
		\ob_end_clean();

		$this->assertCount( 2, $this->tracks_channel()->events_for( 'fosse_wizard_started' ) );
	}
}
