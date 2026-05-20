<?php
/**
 * Tests for Onboarding_Wizard.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Atmosphere\OAuth\Encryption;
use Automattic\Fosse\Admin\Actor_Mode_Lock;
use Automattic\Fosse\Admin\AP_Provider;
use Automattic\Fosse\Admin\Bluesky_Domain_Handle;
use Automattic\Fosse\Admin\Bluesky_Provider;
use Automattic\Fosse\Admin\Connection_Provider;
use Automattic\Fosse\Admin\Connection_Provider_Registry;
use Automattic\Fosse\Admin\Onboarding_Wizard;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use WorDBless\BaseTestCase;

/**
 * Verifies wizard completion tracking, form handling, and skip/reset flows.
 */
class Onboarding_WizardTest extends BaseTestCase {

	/**
	 * Clean option and registry state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'fosse-test-auth-key' );
		}

		if ( ! defined( 'AUTH_SALT' ) ) {
			define( 'AUTH_SALT', 'fosse-test-auth-salt' );
		}

		delete_option( Onboarding_Wizard::COMPLETED_OPTION );
		delete_option( Onboarding_Wizard::DESTINATION_OPTION );
		delete_option( 'activitypub_actor_mode' );
		delete_option( 'activitypub_blog_identifier' );
		delete_option( 'activitypub_support_post_types' );
		delete_option( 'atmosphere_connection' );
		delete_option( 'atmosphere_auto_publish' );
		delete_option( Onboarding_Wizard::REDIRECT_OPTION );
		delete_transient( Onboarding_Wizard::REDIRECT_TRANSIENT );

		// Most tests assume an ActivityPub provider is registered. Tests
		// that need the unavailable path call Connection_Provider_Registry::reset()
		// explicitly; the #[After] hook re-registers AP and Bluesky for the
		// next test.
		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
		Bluesky_Provider::register_provider();
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_state(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test cleanup.
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();

		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		remove_all_actions( 'fosse_wizard_unauthorized' );

		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core global reset for testing.
		delete_transient( 'settings_errors' );

		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
		Bluesky_Provider::register_provider();
	}

	// --- is_complete / mark_complete ---

	/**
	 * Wizard is not complete by default.
	 */
	public function test_is_complete_false_by_default() {
		$this->assertFalse( Onboarding_Wizard::is_complete() );
	}

	/**
	 * Mark_complete sets the option and is_complete returns true.
	 */
	public function test_mark_complete_sets_option() {
		Onboarding_Wizard::mark_complete();

		$this->assertTrue( Onboarding_Wizard::is_complete() );
	}

	/**
	 * Deleting the option makes is_complete return false again.
	 */
	public function test_delete_option_resets_completion() {
		Onboarding_Wizard::mark_complete();
		delete_option( Onboarding_Wizard::COMPLETED_OPTION );

		$this->assertFalse( Onboarding_Wizard::is_complete() );
	}

	// --- handle_save: appearance step ---

	/**
	 * Saving the appearance step stores the actor mode option.
	 */
	public function test_handle_save_appearance_stores_actor_mode() {
		$this->simulate_save_request( 'appearance', array( 'activitypub_actor_mode' => 'blog' ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Saving appearance with actor_blog mode works.
	 */
	public function test_handle_save_appearance_actor_blog() {
		$this->simulate_save_request( 'appearance', array( 'activitypub_actor_mode' => 'actor_blog' ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor_blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Invalid actor mode is rejected.
	 */
	public function test_handle_save_appearance_rejects_invalid_mode() {
		update_option( 'activitypub_actor_mode', 'actor' );
		$this->simulate_save_request( 'appearance', array( 'activitypub_actor_mode' => 'evil' ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor', get_option( 'activitypub_actor_mode' ) );
	}

	// --- handle_save: destinations step ---

	/**
	 * Saving the destinations step stores the wizard destination intent.
	 */
	public function test_handle_save_destinations_stores_destination(): void {
		$this->simulate_save_request(
			'destinations',
			array( 'fosse_onboarding_destination' => 'fediverse_only' )
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'fediverse_only', get_option( 'fosse_onboarding_destination' ) );
	}

	/**
	 * Invalid destination submissions fall back to the recommended path.
	 */
	public function test_handle_save_destinations_invalid_falls_back_to_default(): void {
		$this->simulate_save_request(
			'destinations',
			array( 'fosse_onboarding_destination' => 'not-a-destination' )
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'fediverse_bluesky', get_option( 'fosse_onboarding_destination' ) );
	}

	/**
	 * Saving appearance fires AP's native update_option_* hook so
	 * downstream subscribers (the federation scheduler) propagate the
	 * mode change. Pre-seeding the option ensures the second write
	 * fires update_option_* (rather than add_option_*).
	 */
	public function test_handle_save_appearance_fires_native_update_hook() {
		update_option( 'activitypub_actor_mode', 'actor' );

		$fired = false;
		add_action(
			'update_option_activitypub_actor_mode',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$this->simulate_save_request( 'appearance', array( 'activitypub_actor_mode' => 'blog' ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( $fired, 'update_option_activitypub_actor_mode should fire on value change.' );
	}

	// --- handle_save: content step ---

	/**
	 * The wizard's "what do you want to share?" step deliberately omits
	 * `attachment` from the rendered checkboxes (DOTCOM-17047). Power
	 * users who want Media federation can still toggle it via bundled
	 * ActivityPub's own settings.
	 */
	public function test_render_content_step_omits_attachment_checkbox(): void {
		$output = $this->render_wizard_step( 'content' );

		$this->assertStringContainsString( 'name="activitypub_support_post_types[]"', $output );
		$this->assertStringNotContainsString(
			'value="attachment"',
			$output,
			'Wizard content step should not render a Media (attachment) checkbox.'
		);
		$this->assertStringContainsString( 'Posts', $output );
		$this->assertStringContainsString( 'Pages', $output );
		$this->assertStringNotContainsString( 'form-table', $output );
	}

	/**
	 * Saving the content step stores post types.
	 */
	public function test_handle_save_content_stores_post_types() {
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array( 'post', 'page' ) ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertContains( 'page', $saved );
	}

	/**
	 * Invalid post types are filtered out.
	 */
	public function test_handle_save_content_filters_invalid_types() {
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array( 'post', 'faketype' ) ) );

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertNotContains( 'faketype', $saved );
	}

	/**
	 * `attachment` is registered as a public post type but the chooser
	 * deliberately hides it (DOTCOM-17047). A crafted POST that submits
	 * `attachment` must not slip past the save handler.
	 */
	public function test_handle_save_content_rejects_attachment_in_submission() {
		$this->simulate_save_request(
			'content',
			array( 'activitypub_support_post_types' => array( 'post', 'attachment' ) )
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = (array) get_option( 'activitypub_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertNotContains( 'attachment', $saved );
	}

	/**
	 * If `attachment` is already set in the stored option (enabled via
	 * bundled ActivityPub's own settings), the wizard's content step
	 * must preserve that value rather than silently nuking the
	 * upstream choice.
	 */
	public function test_handle_save_content_preserves_existing_attachment_value() {
		update_option( 'activitypub_support_post_types', array( 'post', 'attachment' ) );

		$this->simulate_save_request(
			'content',
			array( 'activitypub_support_post_types' => array( 'post', 'page' ) )
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = (array) get_option( 'activitypub_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertContains( 'page', $saved );
		$this->assertContains( 'attachment', $saved );
	}

	/**
	 * An empty managed selection still bounces back with the
	 * `empty_post_types` error even when a preserved upstream value
	 * (e.g. `attachment`) is present. The wizard step is explicitly
	 * about picking content types to share, so a preserved Media-only
	 * state isn't enough to satisfy the step.
	 */
	public function test_handle_save_content_empty_managed_with_preserved_attachment_redirects_with_error() {
		update_option( 'activitypub_support_post_types', array( 'post', 'attachment' ) );

		$captured = null;
		$this->simulate_save_request(
			'content',
			array( 'activitypub_support_post_types' => array() )
		);
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			},
			9
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertStringContainsString( 'step=content', (string) $captured );
		$this->assertStringContainsString( 'error=empty_post_types', (string) $captured );
		// Stored option untouched on the error path.
		$this->assertSame( array( 'post', 'attachment' ), get_option( 'activitypub_support_post_types' ) );
	}

	/**
	 * Nested arrays inside the post-types payload (e.g. a crafted POST with
	 * `activitypub_support_post_types[0][]=post`) are filtered out before
	 * `sanitize_text_field()` runs, so the array-to-string warning that
	 * `phpunit.xml.dist` promotes to a hard failure via `failOnWarning` does
	 * not fire and the option is not overwritten with an invalid shape.
	 *
	 * Mirrors the Settings page's `test_handle_save_drops_nested_array_post_types`
	 * coverage so the wizard path matches the hardened Settings path.
	 */
	public function test_handle_save_content_drops_nested_array_post_types(): void {
		update_option( 'activitypub_support_post_types', array( 'post' ) );

		$this->simulate_save_request(
			'content',
			array(
				'activitypub_support_post_types' => array(
					array( 'post' ),
					'page',
				),
			)
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertNotContains( 'post', $saved, 'Nested-array element must not survive sanitization.' );
		$this->assertContains( 'page', $saved, 'Sibling string element must still pass through.' );
	}

	/**
	 * A scalar (non-array) post-types payload is coerced to an array
	 * containing the scalar — the existing `(array) $value` cast handles
	 * the shape, and `is_string` filtering keeps it sane. Locks in the
	 * coercion so a future refactor can't quietly start rejecting scalars.
	 */
	public function test_handle_save_content_coerces_scalar_post_types(): void {
		$this->simulate_save_request(
			'content',
			array( 'activitypub_support_post_types' => 'page' )
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertSame( array( 'page' ), $saved );
	}

	/**
	 * A payload that is entirely nested arrays (no string elements) becomes
	 * an empty selection after sanitization and triggers the
	 * `empty_post_types` redirect rather than overwriting the option.
	 */
	public function test_handle_save_content_invalid_only_redirects_with_error(): void {
		update_option( 'activitypub_support_post_types', array( 'post' ) );

		$captured = null;
		$this->simulate_save_request(
			'content',
			array(
				'activitypub_support_post_types' => array(
					array( 'post' ),
					array( 'page' ),
				),
			)
		);
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			},
			9
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertStringContainsString( 'step=content', (string) $captured );
		$this->assertStringContainsString( 'error=empty_post_types', (string) $captured );
		$this->assertSame( array( 'post' ), get_option( 'activitypub_support_post_types' ) );
	}

	// --- handle_skip ---

	/**
	 * Skip marks wizard complete.
	 */
	public function test_handle_skip_marks_complete() {
		$this->simulate_skip_request();

		try {
			Onboarding_Wizard::handle_skip();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( Onboarding_Wizard::is_complete() );
	}

	// --- handle_complete ---

	/**
	 * Complete action marks wizard complete.
	 */
	public function test_handle_complete_marks_complete() {
		$this->simulate_complete_request();

		try {
			Onboarding_Wizard::handle_complete();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( Onboarding_Wizard::is_complete() );
	}

	/**
	 * Saving content on the fediverse-only path completes the wizard and skips Bluesky.
	 */
	public function test_handle_save_content_fediverse_only_redirects_to_complete(): void {
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );

		$captured = null;
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array( 'post' ) ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			},
			9
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( Onboarding_Wizard::is_complete() );
		$this->assertStringContainsString( 'step=complete', (string) $captured );
		$this->assertStringNotContainsString( 'step=bluesky', (string) $captured );
	}

	// --- handle_reset ---

	/**
	 * Reset clears the completed option.
	 */
	public function test_handle_reset_clears_completion() {
		Onboarding_Wizard::mark_complete();
		$this->simulate_reset_request();

		try {
			Onboarding_Wizard::handle_reset();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertFalse( Onboarding_Wizard::is_complete() );
	}

	/**
	 * Reset clears destination intent along with completion state.
	 */
	public function test_handle_reset_clears_destination_intent(): void {
		Onboarding_Wizard::mark_complete();
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );
		$this->simulate_reset_request();

		try {
			Onboarding_Wizard::handle_reset();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertFalse( get_option( Onboarding_Wizard::DESTINATION_OPTION ) );
	}

	// --- empty post types redirect ---

	/**
	 * Empty post-types submission bounces back to the content step with
	 * an error code instead of overwriting the option with [].
	 */
	public function test_handle_save_content_empty_redirects_with_error(): void {
		update_option( 'activitypub_support_post_types', array( 'post' ) );

		$captured = null;
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array() ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			},
			9
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'step=content', $captured );
		$this->assertStringContainsString( 'error=empty_post_types', $captured );
		$this->assertSame( array( 'post' ), get_option( 'activitypub_support_post_types' ) );
	}

	// --- audit hook: render capability failure ---

	/**
	 * Render fires the audit hook (and dies) for a non-admin user.
	 */
	public function test_render_unauthorized_fires_audit(): void {
		$this->become_subscriber();
		$captured = array();
		$this->hook_audit_capture( $captured );
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::render();
		} finally {
			$this->assertCount( 1, $captured );
			$this->assertSame( 'fosse_wizard_render', $captured[0][0] );
			$this->assertSame( 'capability', $captured[0][2] );
		}
	}

	// --- degraded render ---

	/**
	 * Render shows a clear notice when no AP provider is registered,
	 * instead of trying to render steps that depend on AP actor data.
	 */
	public function test_render_shows_degraded_notice_when_activitypub_unavailable(): void {
		$this->become_admin();
		Connection_Provider_Registry::reset();

		ob_start();
		try {
			Onboarding_Wizard::render();
		} finally {
			$output = ob_get_clean();
		}

		$this->assertStringContainsString( 'Setup is unavailable', $output );
		$this->assertStringContainsString( 'ActivityPub', $output );
		$this->assertStringContainsString( 'data-fosse-lizard-toggle', $output );
	}

	// --- Destinations step render ---

	/**
	 * The default wizard screen is the destination-selection step.
	 */
	public function test_render_default_step_shows_destination_cards(): void {
		$output = $this->render_wizard_step( '' );

		$this->assertStringContainsString( 'Where should your WordPress posts appear?', $output );
		$this->assertStringContainsString( 'Fediverse sharing is enabled by default, so people can follow your site from Fediverse apps like Mastodon. You can also connect Bluesky now or set it up later.', $output );
		$this->assertStringContainsString( 'Fediverse + Bluesky', $output );
		$this->assertStringContainsString( 'Fediverse only', $output );
		$this->assertStringContainsString( 'Simple setup', $output );
		$this->assertSame( 3, substr_count( $output, 'Fediverse apps like Mastodon' ) );
		$this->assertStringContainsString( 'Let people follow your site from Fediverse apps like Mastodon. You can connect Bluesky later.', $output );
		$this->assertStringNotContainsString( 'Mastodon and similar apps', $output );
		$this->assertStringContainsString( 'name="fosse_onboarding_destination"', $output );
		$this->assertStringContainsString( 'data-fosse-lizard-toggle', $output );
		$this->assertStringContainsString( 'aria-label="Toggle wizard theme"', $output );
		$this->assertStringContainsString( 'aria-pressed="false"', $output );
		$this->assertStringContainsString( '&#x1F98E;', $output );
		$this->assertStringNotContainsString( 'Welcome to FOSSE', $output );
		$this->assertStringNotContainsString( '>Later<', $output );
	}

	/**
	 * The legacy welcome slug resolves to the destination-selection step.
	 */
	public function test_render_legacy_welcome_step_shows_destination_cards(): void {
		$output = $this->render_wizard_step( 'welcome' );

		$this->assertStringContainsString( 'Where should your WordPress posts appear?', $output );
		$this->assertStringContainsString( 'Fediverse + Bluesky', $output );
		$this->assertStringContainsString( 'Fediverse only', $output );
		$this->assertStringContainsString( 'name="fosse_onboarding_destination"', $output );
		$this->assertStringContainsString( 'Skip setup', $output );
		$this->assertStringContainsString( 'Continue', $output );
	}

	/**
	 * Progress reflects the destination-first flow and labels the final step Review.
	 */
	public function test_progress_uses_destination_first_labels(): void {
		$output = $this->render_wizard_step( 'destinations' );

		$this->assertStringContainsString( 'Destinations', $output );
		$this->assertStringContainsString( 'Identity', $output );
		$this->assertStringContainsString( 'Content', $output );
		$this->assertStringContainsString( 'Bluesky', $output );
		$this->assertStringContainsString( 'Review', $output );
		$this->assertStringNotContainsString( '>Welcome<', $output );
	}

	/**
	 * The identity step leads with the follow decision and default card first.
	 */
	public function test_identity_step_uses_follow_question_and_actor_first(): void {
		$output = $this->render_wizard_step( 'appearance' );

		$this->assertStringContainsString( 'Who should people follow?', $output );
		$this->assertStringContainsString( 'Choose the fediverse identity people can follow when FOSSE shares your selected content types.', $output );
		$this->assertMatchesRegularExpression(
			'/As you[\s\S]+As your site/',
			$output,
			'The default author-profile option should render before the site-profile option.'
		);
	}

	/**
	 * Local preview URLs should not make localhost read like a product identity.
	 */
	public function test_identity_step_uses_generic_site_copy_for_localhost_preview(): void {
		$old_home    = get_option( 'home' );
		$old_siteurl = get_option( 'siteurl' );

		update_option( 'home', 'http://localhost:9400' );
		update_option( 'siteurl', 'http://localhost:9400' );

		try {
			$output = $this->render_wizard_step( 'appearance' );
		} finally {
			update_option( 'home', $old_home );
			update_option( 'siteurl', $old_siteurl );
		}

		$this->assertStringContainsString( 'People follow your site, and posts show your site name. Best for blogs and publications.', $output );
		$this->assertStringNotContainsString( 'People follow <strong>localhost</strong>', $output );
	}

	// --- Appearance step render ---

	/**
	 * The appearance step renders all three actor-mode preview containers
	 * up front so a small JS toggle can swap visibility without a page
	 * reload. Each container is tagged with `data-fosse-mode` so the JS
	 * can target it. Saved mode is `actor` here, so only that one stays
	 * visible — the others render with the `is-hidden` class for
	 * progressive enhancement.
	 */
	public function test_render_appearance_renders_all_three_preview_containers(): void {
		update_option( 'activitypub_actor_mode', 'actor' );

		// Grant AP eligibility so `get_user_address()` returns a real
		// webfinger; without it the wrapper for that mode renders empty
		// and is now suppressed to avoid an empty styled grey box.
		add_filter( 'activitypub_user_can_activitypub', '__return_true' );
		$output = $this->render_wizard_step( 'appearance' );
		remove_filter( 'activitypub_user_can_activitypub', '__return_true' );

		$this->assertMatchesRegularExpression(
			'/<div[^>]*\bclass="[^"]*\bfosse-address-preview\b[^"]*"[^>]*\bdata-fosse-mode="actor"/i',
			$output,
			'Expected an actor-mode preview container.'
		);
		$this->assertMatchesRegularExpression(
			'/<div[^>]*\bclass="[^"]*\bfosse-address-preview\b[^"]*"[^>]*\bdata-fosse-mode="blog"/i',
			$output,
			'Expected a blog-mode preview container.'
		);
		$this->assertMatchesRegularExpression(
			'/<div[^>]*\bclass="[^"]*\bfosse-address-preview\b[^"]*"[^>]*\bdata-fosse-mode="actor_blog"/i',
			$output,
			'Expected an actor_blog-mode preview container.'
		);
		$this->assertStringContainsString( 'Your fediverse address:', $output );
		$this->assertStringContainsString( 'Site fediverse address:', $output );
	}

	/**
	 * Only the container matching the saved mode renders without
	 * `is-hidden`; the inactive ones are rendered hidden so JS can swap
	 * them in without a reload, and so a no-JS fallback still matches the
	 * pre-#68 behavior of showing only the active mode's preview.
	 */
	public function test_render_appearance_marks_inactive_previews_hidden(): void {
		update_option( 'activitypub_actor_mode', 'blog' );

		// Grant AP eligibility so `get_user_address()` returns a real
		// webfinger; without it the actor / actor_blog wrappers would be
		// suppressed (empty-content path) and the inactive-hidden assertion
		// would have nothing to match.
		add_filter( 'activitypub_user_can_activitypub', '__return_true' );
		$output = $this->render_wizard_step( 'appearance' );
		remove_filter( 'activitypub_user_can_activitypub', '__return_true' );

		$this->assertMatchesRegularExpression(
			'/<div[^>]*\bclass="[^"]*\bfosse-address-preview\b(?:(?!is-hidden)[^"])*"[^>]*\bdata-fosse-mode="blog"/i',
			$output,
			'The active mode container must not be marked is-hidden.'
		);
		$this->assertMatchesRegularExpression(
			'/<div[^>]*\bclass="[^"]*\bfosse-address-preview\b[^"]*\bis-hidden\b[^"]*"[^>]*\bdata-fosse-mode="actor"/i',
			$output,
			'Inactive containers must be marked is-hidden.'
		);
		$this->assertMatchesRegularExpression(
			'/<div[^>]*\bclass="[^"]*\bfosse-address-preview\b[^"]*\bis-hidden\b[^"]*"[^>]*\bdata-fosse-mode="actor_blog"/i',
			$output,
			'Inactive containers must be marked is-hidden.'
		);
	}

	/**
	 * Modes whose handle resolves to an empty string suppress their preview
	 * wrapper entirely. Without this, the active mode would render as an
	 * empty styled grey box (the `.fosse-address-preview` rule applies
	 * background + padding even when no inner row is emitted).
	 */
	public function test_render_appearance_skips_actor_preview_when_user_handle_empty(): void {
		update_option( 'activitypub_actor_mode', 'actor' );
		// No `activitypub_user_can_activitypub` filter — `get_user_address()`
		// returns '' so the actor wrapper has nothing to render.

		$output = $this->render_wizard_step( 'appearance' );

		$this->assertDoesNotMatchRegularExpression(
			'/<div[^>]*\bdata-fosse-mode="actor"[^>]*>/i',
			$output,
			'Actor wrapper must not render when the user handle is empty.'
		);
	}

	/**
	 * The appearance step exposes an inline Site Handle input keyed to the
	 * AP option, so users can edit the site username from the wizard rather
	 * than bouncing to the Setup page.
	 */
	public function test_render_appearance_renders_inline_site_handle_input(): void {
		update_option( 'activitypub_actor_mode', 'blog' );
		update_option( 'activitypub_blog_identifier', 'mysite' );

		$output = $this->render_wizard_step( 'appearance' );

		$this->assertMatchesRegularExpression(
			'/<input[^>]*\bname="activitypub_blog_identifier"[^>]*\bvalue="mysite"/i',
			$output,
			'Expected the site handle input to render with the saved value.'
		);
		// The input should be inside the form so it submits with the rest
		// of the appearance step.
		$this->assertMatchesRegularExpression(
			'~<form\b[^>]*>.*name="activitypub_blog_identifier".*</form>~is',
			$output,
			'Site handle input must be inside the appearance form.'
		);
	}

	/**
	 * The site handle row is hidden when the saved mode does not include
	 * the blog actor, so the no-JS fallback matches the old behavior of
	 * not surfacing the field. JS reveals it when the user picks `blog`
	 * or `actor_blog`.
	 */
	public function test_render_appearance_marks_site_handle_hidden_when_actor_mode(): void {
		update_option( 'activitypub_actor_mode', 'actor' );

		$output = $this->render_wizard_step( 'appearance' );

		$this->assertMatchesRegularExpression(
			'/<div[^>]*\bclass="[^"]*\bfosse-wizard__blog-handle\b[^"]*\bis-hidden\b[^"]*"[^>]*\bdata-fosse-when="includes-blog"/i',
			$output,
			'Site handle row must be marked is-hidden when actor mode is selected.'
		);
	}

	/**
	 * Saving the appearance step persists a non-empty site handle into the
	 * shared `activitypub_blog_identifier` option, mirroring the Setup
	 * page behavior. AP's own option sanitizer enforces collisions.
	 */
	public function test_handle_save_appearance_stores_blog_identifier(): void {
		$this->simulate_save_request(
			'appearance',
			array(
				'activitypub_actor_mode'      => 'blog',
				'activitypub_blog_identifier' => 'newsroom',
			)
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'newsroom', get_option( 'activitypub_blog_identifier' ) );
	}

	/**
	 * Empty handle submissions preserve any existing stored value rather
	 * than reverting to AP's default — matches AP_Provider::handle_save()
	 * so users who haven't touched the field don't accidentally freeze
	 * the dynamic default into the option.
	 */
	public function test_handle_save_appearance_preserves_existing_handle_when_empty(): void {
		update_option( 'activitypub_blog_identifier', 'sticky' );

		$this->simulate_save_request(
			'appearance',
			array(
				'activitypub_actor_mode'      => 'blog',
				'activitypub_blog_identifier' => '   ',
			)
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'sticky', get_option( 'activitypub_blog_identifier' ) );
	}

	/**
	 * AP's sanitizer adds settings errors under `activitypub_blog_identifier`
	 * when the input collides with an existing user. The wizard re-tags them
	 * under the `fosse` group so `settings_errors( 'fosse' )` on the
	 * appearance step renders the message — without re-tagging the user
	 * would land back on the wizard with no feedback.
	 *
	 * Forces the rejection branch directly via AP's filter; WorDBless's
	 * dbless engine doesn't satisfy `WP_User_Query`'s LIKE search so seeding
	 * a colliding user wouldn't trip AP's collision path.
	 */
	public function test_handle_save_appearance_rewires_ap_settings_errors_to_fosse_group(): void {
		// Capture the closure so cleanup can target only this callback —
		// `remove_all_filters` would also wipe AP's own sanitizer (and any
		// other registered callbacks), affecting unrelated tests.
		$rejector = static function ( $value ) {
			add_settings_error(
				'activitypub_blog_identifier',
				'collision_test',
				'Collision test error.',
				'error'
			);
			return $value;
		};
		add_filter( 'sanitize_option_activitypub_blog_identifier', $rejector, 11 );

		$this->simulate_save_request(
			'appearance',
			array(
				'activitypub_actor_mode'      => 'blog',
				'activitypub_blog_identifier' => 'whatever',
			)
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		remove_filter( 'sanitize_option_activitypub_blog_identifier', $rejector, 11 );

		$fosse_codes = array_column( get_settings_errors( 'fosse' ), 'code' );
		$this->assertContains( 'collision_test', $fosse_codes );
	}

	/**
	 * On collision rejection the wizard redirects back to the appearance
	 * step instead of advancing to content, so the user can read the
	 * surfaced error and correct the input.
	 */
	public function test_handle_save_appearance_redirects_back_on_blog_identifier_rejection(): void {
		$rejector = static function ( $value ) {
			add_settings_error(
				'activitypub_blog_identifier',
				'collision_test',
				'Collision test error.',
				'error'
			);
			return $value;
		};
		add_filter( 'sanitize_option_activitypub_blog_identifier', $rejector, 11 );

		$captured = null;
		$this->simulate_save_request(
			'appearance',
			array(
				'activitypub_actor_mode'      => 'blog',
				'activitypub_blog_identifier' => 'whatever',
			)
		);
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			},
			9
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		remove_filter( 'sanitize_option_activitypub_blog_identifier', $rejector, 11 );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'step=appearance', $captured );
		$this->assertStringNotContainsString( 'step=content', $captured );
	}

	/**
	 * The appearance step renders `settings_errors( 'fosse' )` so a fresh
	 * collision message persisted via the `settings_errors` transient on
	 * redirect surfaces above the form on page load.
	 */
	public function test_render_appearance_renders_fosse_settings_errors(): void {
		add_settings_error(
			'fosse',
			'collision_test',
			'Pretend collision message.',
			'error'
		);

		$output = $this->render_wizard_step( 'appearance' );

		$this->assertStringContainsString( 'Pretend collision message.', $output );
	}

	/**
	 * When the actor mode is constant-locked, the appearance step
	 * replaces the mode-picker cards with a hidden input + locked
	 * notice. Regression guard against the locked branch ever being
	 * deleted by accident or the hidden input being dropped.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_render_appearance_locked_replaces_mode_cards_with_hidden_input(): void {
		define( 'ACTIVITYPUB_SINGLE_USER_MODE', true );

		$output = $this->render_wizard_step( 'appearance' );

		$this->assertStringContainsString(
			'<input type="hidden" name="activitypub_actor_mode" value="' . Actor_Mode_Lock::MODE_BLOG . '"',
			$output
		);
		$this->assertStringNotContainsString( 'type="radio"', $output );
		$this->assertStringContainsString( 'defined through server configuration', $output );
	}

	// --- Bluesky step render ---

	/**
	 * The Bluesky wizard step renders the live OAuth connect form when disconnected.
	 */
	public function test_render_bluesky_step_disconnected_shows_connect_form(): void {
		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringContainsString( 'fosse_connect_bluesky', $output );
		$this->assertStringContainsString( 'bluesky_handle', $output );
		$this->assertStringContainsString( 'fosse_bluesky_return', $output );
		$this->assertStringContainsString( 'value="wizard"', $output );
		$this->assertStringContainsString( 'Connect Bluesky', $output );
		$this->assertStringContainsString( 'Skip Bluesky for now', $output );
		$this->assertStringContainsString( 'Bluesky handle', $output );
		$this->assertStringContainsString( 'Enter your Bluesky handle, such as yourname.bsky.social. If you use your own domain as your handle, enter that.', $output );
		$this->assertStringNotContainsString( 'Coming Soon', $output );
		$this->assertStringNotContainsString( 'Bluesky Handle', $output );
		$this->assertMatchesRegularExpression( '/<input\b(?=[^>]*\bid="fosse-bsky-handle")(?=[^>]*\bname="bluesky_handle")[^>]*>/i', $output );
		$this->assertDoesNotMatchRegularExpression( '/<input\b(?=[^>]*\bid="fosse-bsky-handle")[^>]*\bdisabled\b/i', $output );
	}

	/**
	 * The disconnected Bluesky step points the Connect button at the connect
	 * form rendered inside the step.
	 */
	public function test_render_bluesky_step_disconnected_connect_targets_connect_form(): void {
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_bluesky' );

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertMatchesRegularExpression(
			'/<button[^>]*form="fosse-wizard-bluesky-connect-form"[^>]*>\s*Connect Bluesky\s*<\/button>/i',
			$output,
			'Connect Bluesky should submit the in-step connect form.'
		);
		$this->assertStringContainsString( 'Skip Bluesky for now', $output );
	}

	/**
	 * A fediverse-only destination does not render the Bluesky connect form.
	 */
	public function test_render_bluesky_step_fediverse_only_falls_back_to_content(): void {
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringContainsString( 'What do you want to share?', $output );
		$this->assertStringNotContainsString( 'fosse_connect_bluesky', $output );
	}

	/**
	 * The wizard's connect form embeds a `_wpnonce` that validates against
	 * the `fosse_connect_bluesky` action — so a typo'd action name in the
	 * form template would surface as a test failure rather than a 403 in
	 * production.
	 */
	public function test_render_bluesky_step_emits_valid_connect_nonce(): void {
		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertMatchesRegularExpression(
			'/<input[^>]*\bname="_wpnonce"[^>]*\bvalue="([^"]+)"/i',
			$output,
			'Expected a _wpnonce hidden input in the rendered form.'
		);

		preg_match( '/<input[^>]*\bname="_wpnonce"[^>]*\bvalue="([^"]+)"/i', $output, $matches );
		$nonce = $matches[1] ?? '';

		$this->assertNotEmpty( $nonce );
		$this->assertNotFalse(
			wp_verify_nonce( $nonce, 'fosse_connect_bluesky' ),
			'Embedded form nonce must validate against fosse_connect_bluesky.'
		);
	}

	/**
	 * When the Bluesky provider is not registered at all, the wizard renders
	 * the unavailable notice rather than a connect form whose admin-post
	 * handler isn't attached.
	 */
	public function test_render_bluesky_step_unregistered_provider_shows_unavailable(): void {
		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
		// Deliberately do NOT register Bluesky_Provider.

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringContainsString( 'Bluesky setup is unavailable', $output );
		$this->assertStringNotContainsString( 'fosse_connect_bluesky', $output );
	}

	/**
	 * The Bluesky wizard step does not render a dead connect form when unavailable.
	 */
	public function test_render_bluesky_step_unavailable_omits_connect_form(): void {
		Connection_Provider_Registry::reset();
		AP_Provider::register_provider();
		Connection_Provider_Registry::register(
			new class() implements Connection_Provider {
				/**
				 * Get the provider slug.
				 *
				 * @return string
				 */
				public function get_slug(): string {
					return 'bluesky';
				}

				/**
				 * Get the provider display name.
				 *
				 * @return string
				 */
				public function get_name(): string {
					return 'Bluesky';
				}

				/**
				 * Whether the provider is available.
				 *
				 * @return bool
				 */
				public function is_available(): bool {
					return false;
				}

				/**
				 * Get current provider status.
				 *
				 * @return array<string, mixed>
				 */
				public function get_status(): array {
					return array( 'connected' => false );
				}

				/**
				 * Render setup UI.
				 *
				 * @return void
				 */
				public function render_setup_section(): void {}

				/**
				 * Render connection actions.
				 *
				 * @return void
				 */
				public function render_connection_actions(): void {}

				/**
				 * Render status UI.
				 *
				 * @return void
				 */
				public function render_status_card(): void {}

				/**
				 * Register hooks.
				 *
				 * @return void
				 */
				public function register_hooks(): void {}

				/**
				 * Persist provider settings.
				 *
				 * @param array<string, mixed> $post_data POST payload.
				 * @return bool
				 */
				public function save_settings( array $post_data ): bool {
					unset( $post_data );
					return true;
				}
			}
		);

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringContainsString( 'Bluesky setup is unavailable', $output );
		$this->assertStringContainsString( 'Skip for now', $output );
		$this->assertStringNotContainsString( 'fosse_connect_bluesky', $output );
		$this->assertStringNotContainsString( 'fosse-bsky-handle', $output );
	}

	/**
	 * The Bluesky wizard step renders connected account details instead of the form.
	 */
	public function test_render_bluesky_step_connected_shows_connection_details(): void {
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringContainsString( 'Review Bluesky connection', $output );
		$this->assertSame( 1, substr_count( $output, 'Bluesky is connected' ) );
		$this->assertStringContainsString( 'alice.bsky.social', $output );
		$this->assertStringContainsString( 'did:plc:alice123', $output );
		$this->assertStringContainsString( 'Finish setup', $output );
		$this->assertStringNotContainsString( 'fosse_connect_bluesky', $output );
	}

	/**
	 * The connected-state Bluesky step exposes the explicit "use my domain"
	 * confirm button when the install qualifies (root install, connected,
	 * non-matching handle). Clicking the button is required to actually
	 * perform the change — the wizard never auto-sets the handle.
	 */
	public function test_render_bluesky_step_connected_shows_domain_handle_panel_when_eligible(): void {
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringContainsString( 'fosse_set_bluesky_domain_handle', $output );
		$this->assertStringContainsString( 'Use your domain as your Bluesky handle', $output );
		$this->assertStringContainsString( 'Heads up: replacing your handle is destructive', $output );
		// The panel must label which handle replaces what so the user knows
		// what they're trading.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertStringContainsString( (string) $site_host, $output );
		$this->assertStringContainsString( 'alice.bsky.social', $output );
	}

	/**
	 * When FOSSE has previously set the handle and it no longer matches
	 * the site (domain change, or user changed it on bsky.app), the
	 * wizard panel surfaces the drift-specific copy instead of the
	 * first-time-setup copy. Same CTA button — only the framing changes.
	 */
	public function test_render_bluesky_step_renders_drift_copy_when_snapshot_exists(): void {
		$this->seed_bluesky_connection( 'oldhandle.example', 'did:plc:alice123' );
		update_option(
			\Automattic\Fosse\Admin\Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => 'did:plc:alice123',
				'handle' => 'alice.bsky.social',
			),
			false
		);

		$output = $this->render_wizard_step( 'bluesky' );

		// Drift heading + drift copy present; first-time copy absent.
		$this->assertStringContainsString( 'Realign your Bluesky handle with this site', $output );
		$this->assertStringContainsString( 'FOSSE previously set your Bluesky handle', $output );
		$this->assertStringNotContainsString( 'Replace it with', $output );

		// CTA button itself is unchanged.
		$this->assertStringContainsString( 'fosse_set_bluesky_domain_handle', $output );
	}

	/**
	 * The pre-OAuth connect form must NOT carry a domain-handle checkbox.
	 *
	 * The earlier design used a checkbox to opt into setting the handle
	 * during OAuth. That was changed to a post-connect confirm flow
	 * because replacing a Bluesky handle is destructive — a checkbox can
	 * be ticked accidentally, but a labeled confirm button after the user
	 * has visibly connected requires deliberate action.
	 */
	public function test_render_bluesky_step_disconnected_omits_domain_handle_checkbox(): void {
		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringContainsString( 'fosse_connect_bluesky', $output );
		$this->assertStringNotContainsString( 'fosse_set_domain_handle', $output );
		$this->assertStringNotContainsString( 'fosse_set_bluesky_domain_handle', $output );
	}

	/**
	 * Disabling the feature suppresses the connected-state confirm button.
	 */
	public function test_render_bluesky_step_omits_domain_handle_panel_when_feature_disabled(): void {
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );

		try {
			$output = $this->render_wizard_step( 'bluesky' );
		} finally {
			remove_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );
		}

		$this->assertStringNotContainsString( 'fosse_set_bluesky_domain_handle', $output );
	}

	/**
	 * Once the connected handle already matches the site host, the offer
	 * disappears — there's nothing to confirm.
	 */
	public function test_render_bluesky_step_omits_domain_handle_panel_when_handle_matches(): void {
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$this->seed_bluesky_connection( $site_host, 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringNotContainsString( 'fosse_set_bluesky_domain_handle', $output );
	}

	/**
	 * The wizard's top-of-step notice-suppression filter exists to dedupe
	 * Atmosphere's own connect-success echo — but it must let our own
	 * domain-handle success/info notices through, since they describe a
	 * separate explicit confirm action that needs its own feedback.
	 */
	public function test_render_bluesky_step_surfaces_domain_handle_success_notice(): void {
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		// Seed the kind of success notice Bluesky_Domain_Handle posts after
		// a confirmed updateHandle call.
		add_settings_error(
			'atmosphere',
			'fosse_domain_handle',
			'Your Bluesky handle is now example.com.',
			'success'
		);

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringContainsString( 'Your Bluesky handle is now example.com', $output );
	}

	/**
	 * Atmosphere's own connect-success echo (different `code`) is still
	 * suppressed at the top of the step — the connected-state copy below
	 * already speaks for the success case.
	 */
	public function test_render_bluesky_step_still_suppresses_atmosphere_connect_success(): void {
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		add_settings_error(
			'atmosphere',
			'fosse_bluesky_notice',
			'Successfully connected to Bluesky.',
			'success'
		);

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringNotContainsString( 'Successfully connected to Bluesky.', $output );
	}

	/**
	 * The completion summary reflects an already-connected Bluesky account.
	 */
	public function test_complete_summary_shows_connected_bluesky_account(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_actor_mode', 'blog' );
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Bluesky', $output );
		$this->assertStringContainsString( '<dl', $output );
		$this->assertStringContainsString( '<dt', $output );
		$this->assertStringContainsString( '<dd', $output );
		$this->assertMatchesRegularExpression(
			'~Connected as\s*<a[^>]+href="https://bsky\.app/profile/alice\.bsky\.social"[^>]*>\s*<code class="fosse-token fosse-admin-token fosse-token--handle fosse-admin-token--handle">@alice\.<wbr>bsky\.<wbr>social</code>\s*</a>~',
			$output
		);
		$this->assertMatchesRegularExpression(
			'~<dt class="fosse-detail-list__term">Fediverse identity</dt>\s*<dd class="fosse-detail-list__description">.*<code class="fosse-token fosse-admin-token fosse-token--ap-address fosse-admin-token--ap-address">~s',
			$output
		);
		$this->assertStringNotContainsString( 'Not connected', $output );

		$fediverse_row = strpos( $output, '<dt class="fosse-detail-list__term">Fediverse identity</dt>' );
		$bluesky_row   = strpos( $output, '<dt class="fosse-detail-list__term">Bluesky</dt>' );
		$content_row   = strpos( $output, '<dt class="fosse-detail-list__term">Content types</dt>' );

		$debug = "DEBUG OUTPUT (length=" . strlen( $output ) . "):\n" . $output;
		$this->assertNotFalse( $fediverse_row, $debug );
		$this->assertNotFalse( $bluesky_row, $debug );
		$this->assertNotFalse( $content_row, $debug );
		$this->assertLessThan( $bluesky_row, $fediverse_row, 'Fediverse identity should appear before Bluesky.' );
		$this->assertLessThan( $content_row, $bluesky_row, 'Bluesky should appear with the identity rows before Content types.' );
	}

	/**
	 * A stored Bluesky handle that includes a leading `@` must still produce
	 * a correct `bsky.app/profile/...` URL — `@` is stripped before
	 * percent-encoding so the link does not become `bsky.app/profile/%40alice...`.
	 */
	public function test_complete_summary_strips_leading_at_from_stored_bluesky_handle_in_link(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_actor_mode', 'blog' );
		$this->seed_bluesky_connection( '@alice.bsky.social', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString(
			'href="https://bsky.app/profile/alice.bsky.social"',
			$output
		);
		$this->assertStringNotContainsString( 'profile/%40', $output );
		$this->assertStringNotContainsString( 'profile/@', $output );
	}

	/**
	 * A degenerate stored Bluesky handle (e.g. a bare `@` left over from a
	 * partial connection) must fall back to the plain "Connected" string
	 * instead of rendering a `@` token linked to an empty bsky.app profile.
	 */
	public function test_complete_summary_bluesky_degenerate_handle_falls_back_to_bare_connected(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_actor_mode', 'blog' );
		$this->seed_bluesky_connection( '@', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertMatchesRegularExpression( '~<dt[^>]*>Bluesky</dt>\s*<dd[^>]*>\s*Connected\s*</dd>~', $output );
		$this->assertStringNotContainsString( 'href="https://bsky.app/profile/"', $output );
	}

	/**
	 * A translation that uses a numbered placeholder (`%1$s`) — which is
	 * legal sprintf syntax that WordPress translators routinely emit when
	 * reordering substitutions — must still substitute the linked handle
	 * instead of rendering the literal placeholder.
	 */
	public function test_complete_summary_bluesky_supports_numbered_translation_placeholder(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_actor_mode', 'blog' );
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		$filter = static function ( $translation, $text, $domain ) {
			if ( 'fosse' === $domain && 'Connected as %s' === $text ) {
				return 'Conectado como %1$s';
			}
			return $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );

		try {
			$output = $this->render_wizard_step( 'complete' );
		} finally {
			remove_filter( 'gettext', $filter, 10 );
		}

		$this->assertStringContainsString( 'Conectado como', $output );
		$this->assertStringContainsString(
			'href="https://bsky.app/profile/alice.bsky.social"',
			$output
		);
		$this->assertStringNotContainsString( '%1$s', $output );
		$this->assertStringNotContainsString( '%s', $output );
	}

	/**
	 * A corrupted `atmosphere_connection` record where `handle` is non-string
	 * (e.g. an array) must not throw a `TypeError` and must not render a
	 * broken link. The wizard treats the missing-handle case as a bare
	 * "Connected" state.
	 */
	public function test_complete_summary_bluesky_non_string_handle_does_not_fatal(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_actor_mode', 'blog' );
		update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:alice123',
				'handle'       => array( 'unexpected', 'array' ),
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);

		$output = $this->render_wizard_step( 'complete' );

		$this->assertMatchesRegularExpression( '~<dt[^>]*>Bluesky</dt>\s*<dd[^>]*>\s*Connected\s*</dd>~', $output );
		$this->assertStringNotContainsString( 'bsky.app/profile/', $output );
	}

	/**
	 * The Review summary includes the selected destination and skipped Bluesky state.
	 */
	public function test_complete_summary_shows_fediverse_only_destination_and_skipped_bluesky(): void {
		Onboarding_Wizard::mark_complete();
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Destinations', $output );
		$this->assertStringContainsString( 'Fediverse identity', $output );
		$this->assertStringContainsString( 'Content types', $output );
		$this->assertStringContainsString( 'Fediverse only', $output );
		$this->assertStringContainsString( 'Skipped', $output );
	}

	/**
	 * The Review summary reports an existing Bluesky connection even when the
	 * latest wizard run selected Fediverse-only onboarding.
	 */
	public function test_complete_summary_prioritizes_connected_bluesky_for_fediverse_only_destination(): void {
		Onboarding_Wizard::mark_complete();
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Fediverse only', $output );
		$this->assertMatchesRegularExpression(
			'~<dt[^>]*>Bluesky</dt>\s*<dd[^>]*>\s*Connected as\s*<a[^>]+href="https://bsky\.app/profile/alice\.bsky\.social"[^>]*>\s*<code class="fosse-token fosse-admin-token fosse-token--handle fosse-admin-token--handle">@alice\.<wbr>bsky\.<wbr>social</code>\s*</a>\s*</dd>~',
			$output,
			'Connected Bluesky accounts must be reported even when the saved destination is Fediverse-only.'
		);
		$this->assertStringNotContainsString( 'Skipped', $output );
	}

	/**
	 * In `actor_blog` mode, the completion screen shows both the user and
	 * site fediverse handles instead of dropping them in favor of a bare
	 * mode label. Stub `activitypub_user_can_activitypub` because WorDBless
	 * doesn't fire AP's activation, so the admin lacks the
	 * `activitypub` capability by default.
	 */
	public function test_complete_summary_shows_user_and_blog_handles_in_both_mode(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_actor_mode', 'actor_blog' );

		add_filter( 'activitypub_user_can_activitypub', '__return_true' );
		$output = $this->render_wizard_step( 'complete' );
		remove_filter( 'activitypub_user_can_activitypub', '__return_true' );

		$this->assertStringContainsString( 'Both (site + authors)', $output );
		$this->assertStringContainsString( 'As you:', $output );
		$this->assertStringContainsString( 'As your site:', $output );
		// Fediverse handle markup should be wrapped in token-styled <code>,
		// with a `<br />` between the label and the handle so long handles
		// don't wrap mid-token (#72).
		$this->assertMatchesRegularExpression( '~As you:<br\s*/?>\s*<code class="fosse-token fosse-admin-token fosse-token--ap-address fosse-admin-token--ap-address">@[^<]+<wbr>@[^<]+(?:<wbr>[^<]+)*</code>~', $output );
		$this->assertMatchesRegularExpression( '~As your site:<br\s*/?>\s*<code class="fosse-token fosse-admin-token fosse-token--ap-address fosse-admin-token--ap-address">@[^<]+<wbr>@[^<]+(?:<wbr>[^<]+)*</code>~', $output );
	}

	/**
	 * In `blog` mode, the completion summary embeds the resolved blog
	 * handle in the "As your site" line — fixing #60 where the line
	 * previously dropped to the bare host.
	 */
	public function test_complete_summary_shows_blog_handle_in_blog_mode(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_actor_mode', 'blog' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'As your site', $output );
		$this->assertMatchesRegularExpression( '~As your site\s*<code class="fosse-token fosse-admin-token fosse-token--ap-address fosse-admin-token--ap-address">@[^<]+<wbr>@[^<]+(?:<wbr>[^<]+)*</code>~', $output );
		// Mirror the actor-mode guard so a future revert of the inline-space
		// change in `format_mode_label()` (back to `<br /><code>`) is caught
		// for the blog branch too — the assertion above's `\s*` accepts both
		// shapes and would not flag the regression on its own.
		$this->assertDoesNotMatchRegularExpression( '~As your site<br\s*/?>~', $output );
	}

	/**
	 * The `blog` fallback shows the site host only when AP cannot resolve a
	 * real blog actor. It must not render that host as `@example.org`, which
	 * looks like a fediverse address with no local-part. The token also
	 * carries a `--host` modifier (not `--handle`) so the class list reflects
	 * the value's actual shape.
	 */
	public function test_complete_summary_blog_fallback_host_does_not_render_as_fediverse_address(): void {
		$output = $this->call_format_mode_label( 'blog', '', '' );

		$this->assertStringContainsString( 'As your site', $output );
		$this->assertStringContainsString(
			'<code class="fosse-token fosse-admin-token fosse-token--host fosse-admin-token--host">example.<wbr>org</code>',
			$output
		);
		$this->assertStringNotContainsString( '@example', $output );
	}

	/**
	 * In `actor` mode the user handle stays with the "As you" label so the
	 * single-value summary reads as one phrase.
	 */
	public function test_complete_summary_keeps_handle_inline_in_actor_mode(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_actor_mode', 'actor' );

		add_filter( 'activitypub_user_can_activitypub', '__return_true' );
		try {
			$output = $this->render_wizard_step( 'complete' );
		} finally {
			remove_filter( 'activitypub_user_can_activitypub', '__return_true' );
		}

		$this->assertStringContainsString( 'As you', $output );
		$this->assertMatchesRegularExpression( '~As you\s*<code class="fosse-token fosse-admin-token fosse-token--ap-address fosse-admin-token--ap-address">@[^<]+<wbr>@[^<]+(?:<wbr>[^<]+)*</code>~', $output );
		// The pre-fix wrapper used parens around the handle on the same line;
		// guard against the parenthesized shape regressing here.
		$this->assertDoesNotMatchRegularExpression( '~As you \(<code>~', $output );
		$this->assertDoesNotMatchRegularExpression( '~As you<br\s*/?>~', $output );
	}

	// --- Bluesky signup help (#58) ---

	/**
	 * The disconnected Bluesky step combines secondary Bluesky help into one callout.
	 */
	public function test_render_bluesky_step_disconnected_shows_signup_link(): void {
		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertSame( 1, substr_count( $output, 'Need a Bluesky account?' ) );
		$this->assertStringContainsString( 'https://bsky.app/', $output );
		$this->assertStringContainsString( 'https://bsky.social/about/blog/4-28-2023-domain-handle-tutorial', $output );
		$this->assertStringContainsString( 'Need a Bluesky account?', $output );
		$this->assertStringContainsString( 'Create one', $output );
		$this->assertStringContainsString( 'learn how to use your own domain as your handle', $output );
		$this->assertStringNotContainsString( 'Want your domain as your handle?', $output );
	}

	/**
	 * The connected Bluesky step does not show a sign-up affordance — the
	 * user is already authenticated, so prompting to "create one" is noise.
	 */
	public function test_render_bluesky_step_connected_omits_signup_link(): void {
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringNotContainsString( 'https://bsky.app/', $output );
		$this->assertStringNotContainsString( 'Need a Bluesky account', $output );
	}

	// --- Bluesky post-OAuth completion state (#59, #70) ---

	/**
	 * Atmosphere's OAuth callback adds a "Successfully connected" settings_error
	 * that previously rendered as a top notice on the wizard's Bluesky step,
	 * doubling up with the in-card "Bluesky is connected" copy. After #70 the
	 * top success notice is suppressed; only the persistent in-card state
	 * remains for the connected confirmation.
	 */
	public function test_render_bluesky_step_suppresses_top_success_notice(): void {
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		// Snapshot prior settings_errors state so seeding the success-type
		// fixture below can't leak into adjacent tests, then seed a notice
		// of the same shape Atmosphere emits on a successful OAuth callback.
		$output = $this->with_isolated_settings_errors(
			function (): string {
				add_settings_error(
					'atmosphere',
					'connected',
					'TOP_NOTICE_SUCCESS_FIXTURE',
					'success'
				);
				return $this->render_wizard_step( 'bluesky' );
			}
		);

		// The duplicate top success notice must not render on the wizard step.
		$this->assertStringNotContainsString( 'TOP_NOTICE_SUCCESS_FIXTURE', $output );
		// The in-card persistent state still speaks for the connected case.
		$this->assertStringContainsString( 'Bluesky is connected', $output );
	}

	/**
	 * Error-typed atmosphere notices still surface on the wizard's Bluesky step
	 * — only success/info confirmations are dropped (#70). Without this, a
	 * failed OAuth callback would re-render the connect form with no feedback.
	 */
	public function test_render_bluesky_step_preserves_top_error_notice(): void {
		$output = $this->with_isolated_settings_errors(
			function (): string {
				add_settings_error(
					'atmosphere',
					'callback_failed',
					'TOP_NOTICE_ERROR_FIXTURE',
					'error'
				);
				return $this->render_wizard_step( 'bluesky' );
			}
		);

		$this->assertStringContainsString( 'TOP_NOTICE_ERROR_FIXTURE', $output );
	}

	/**
	 * After a successful Bluesky connection the wizard suppresses the
	 * "you can always connect later" copy that contradicted the success
	 * state the user is looking at.
	 */
	public function test_render_bluesky_step_connected_suppresses_connect_later_copy(): void {
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringNotContainsString( 'connect later', $output );
		$this->assertStringNotContainsString( 'This step is optional', $output );
		$this->assertStringContainsString( 'Bluesky is connected', $output );
		$this->assertStringContainsString( 'Review the connected account below', $output );
	}

	/**
	 * The post-OAuth view surfaces the resolved fediverse identity so users
	 * see the actual handle they just stood up. AP's `user_can_activitypub`
	 * filter is stubbed because WorDBless doesn't fire AP's activation, so
	 * the `activitypub` capability isn't granted to admins by default.
	 */
	public function test_render_bluesky_step_connected_shows_fediverse_identity(): void {
		update_option( 'activitypub_actor_mode', 'actor' );
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		add_filter( 'activitypub_user_can_activitypub', '__return_true' );
		try {
			$output = $this->render_wizard_step( 'bluesky' );
		} finally {
			remove_filter( 'activitypub_user_can_activitypub', '__return_true' );
		}

		$this->assertStringContainsString( 'Your fediverse address', $output );
		$this->assertMatchesRegularExpression( '/<code>@[^<]+@[^<]+<\/code>/', $output );
	}

	/**
	 * The disconnected Bluesky step does not render a fediverse identity
	 * row — that detail belongs on the post-OAuth confirmation, not on the
	 * pre-connect form.
	 */
	public function test_render_bluesky_step_disconnected_omits_fediverse_identity(): void {
		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertStringNotContainsString( 'Your fediverse address', $output );
		$this->assertStringNotContainsString( 'Site fediverse address', $output );
	}

	// --- Publish CTA on completion step (#63) ---

	/**
	 * Direct GETs to the review step before completion fall back to the first
	 * wizard step when no destination has been saved yet — a brand-new wizard
	 * run shouldn't skip past the destination decision.
	 */
	public function test_render_complete_step_before_completion_falls_back_to_destinations(): void {
		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Where should your WordPress posts appear?', $output );
		$this->assertStringNotContainsString( 'You&#039;re all set!', $output );
	}

	/**
	 * Direct GETs to the review step on an incomplete Fediverse + Bluesky
	 * wizard land on the Bluesky step — the latest in-flow step before
	 * completion — so a partially-finished run resumes near where the
	 * user left off instead of restarting from step 1.
	 */
	public function test_render_complete_step_before_completion_routes_to_bluesky_for_saved_bluesky_destination(): void {
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_bluesky' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Connect to Bluesky', $output );
		$this->assertStringNotContainsString( 'Where should your WordPress posts appear?', $output );
		$this->assertStringNotContainsString( 'You&#039;re all set!', $output );
	}

	/**
	 * Direct GETs to the review step on an incomplete Fediverse-only wizard
	 * land on the Content step — the last step that submits and marks the
	 * wizard complete on this destination path.
	 */
	public function test_render_complete_step_before_completion_routes_to_content_for_saved_fediverse_only(): void {
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'What do you want to share?', $output );
		$this->assertStringNotContainsString( 'Where should your WordPress posts appear?', $output );
		$this->assertStringNotContainsString( 'You&#039;re all set!', $output );
	}

	/**
	 * The completion step renders a primary CTA to publish the user's first
	 * post — the natural forward push after the wizard finishes. The label
	 * embeds the post type's `singular_name` as-is (no forced lowercasing)
	 * so locale-specific capitalization rules — e.g. German nouns — survive.
	 */
	public function test_render_complete_step_renders_publish_cta(): void {
		Onboarding_Wizard::mark_complete();

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Publish your first Post', $output );
		$this->assertMatchesRegularExpression(
			'/<a[^>]*href="[^"]*post-new\.php[^"]*"[^>]*>\s*Publish your first Post/i',
			$output,
			'The publish CTA must link to post-new.php.'
		);
	}

	/**
	 * The publish CTA's helper paragraph reflects the selected destinations
	 * when Bluesky has not been connected yet.
	 */
	public function test_render_complete_step_cta_uses_destination_specific_language(): void {
		Onboarding_Wizard::mark_complete();

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Your sharing setup is ready.', $output );
		$this->assertStringContainsString( 'Connect Bluesky to share there too.', $output );
		$this->assertStringNotContainsString( 'Your post can reach people on Fediverse apps like Mastodon.', $output );
	}

	/**
	 * The publish CTA helper reflects active Bluesky publishing even when the
	 * latest wizard run selected Fediverse-only onboarding.
	 */
	public function test_render_complete_step_cta_mentions_connected_bluesky_for_fediverse_only_destination(): void {
		Onboarding_Wizard::mark_complete();
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Bluesky sharing is ready too.', $output );
		$this->assertStringNotContainsString( 'Your post can reach people on Fediverse apps like Mastodon', $output );
	}

	/**
	 * A connected Bluesky account with auto-publishing disabled should not make
	 * the publish CTA helper promise that the next post will reach Bluesky.
	 */
	public function test_render_complete_step_cta_explains_disabled_bluesky_auto_publish(): void {
		Onboarding_Wizard::mark_complete();
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );
		update_option( 'atmosphere_auto_publish', '0' );
		$this->seed_bluesky_connection( 'alice.bsky.social', 'did:plc:alice123' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'automatic sharing is off.', $output );
		$this->assertStringNotContainsString( 'Your post can reach people on Fediverse apps like Mastodon', $output );
	}

	/**
	 * When the wizard's content step selected only `page` (or any non-`post`
	 * type), the publish CTA deep-links to that post type's new-post screen
	 * and the label adapts. Otherwise the user lands at the default `post`
	 * editor, which produces content that won't be federated.
	 */
	public function test_render_complete_step_cta_deep_links_selected_post_type(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_support_post_types', array( 'page' ) );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertMatchesRegularExpression(
			'/<a[^>]*href="[^"]*post-new\.php\?[^"]*post_type=page[^"]*"/i',
			$output,
			'Publish CTA must deep-link to post-new.php?post_type=page when only page is federated.'
		);
		$this->assertStringContainsString( 'Publish your first Page', $output );
		$this->assertStringNotContainsString( 'Publish your first Post', $output );
	}

	/**
	 * Default selection (which includes `post`) produces the un-parameterized
	 * `post-new.php` URL — so the existing default-test assertion stays
	 * meaningful and the URL stays clean for the most common case.
	 */
	public function test_render_complete_step_cta_omits_post_type_param_when_post_selected(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_support_post_types', array( 'post', 'page' ) );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertMatchesRegularExpression(
			'/<a[^>]*href="[^"]*post-new\.php"[^>]*>/i',
			$output,
			'Publish CTA URL must not include post_type=post when post is among the selected types.'
		);
		$this->assertStringContainsString( 'Publish your first Post', $output );
	}

	/**
	 * Empty / fully-invalid `activitypub_support_post_types` is technically
	 * possible after wizard completion (AP's own settings page can clear
	 * the list). The completion CTA must not pretend to deep-link a
	 * federated editor in that state — it routes to FOSSE Setup instead.
	 */
	public function test_render_complete_step_cta_routes_to_setup_when_no_federated_types(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_support_post_types', array() );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Set up sharing', $output );
		$this->assertStringNotContainsString( 'Publish your first', $output );
		$this->assertMatchesRegularExpression(
			'/<a[^>]*href="[^"]*page=fosse[^"]*"/i',
			$output,
			'Empty post-type list must route the CTA to the FOSSE Setup page.'
		);
	}

	/**
	 * Non-public post types (revisions, nav menu items) are filtered out
	 * even if some external code wrote them to the option. They wouldn't
	 * federate anyway and the editor isn't user-reachable, so the CTA
	 * degrades to the same "Set up sharing" branch as the empty case.
	 */
	public function test_render_complete_step_cta_filters_out_non_public_types(): void {
		Onboarding_Wizard::mark_complete();
		update_option( 'activitypub_support_post_types', array( 'revision', 'nav_menu_item' ) );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Set up sharing', $output );
		$this->assertStringNotContainsString( 'Publish your first', $output );
	}

	// --- audit hook: handler cap/nonce failures (parameterized) ---

	/**
	 * Each handler fires fosse_wizard_unauthorized before wp_die() on
	 * capability or nonce failure, and preserves handler-specific state.
	 *
	 * @dataProvider unauthorized_handler_provider
	 *
	 * @param string $handler      Handler method name.
	 * @param string $action       Wizard action passed to the audit hook.
	 * @param string $nonce_action Nonce action used by the handler.
	 * @param string $reason       'capability' or 'nonce'.
	 */
	#[DataProvider( 'unauthorized_handler_provider' )]
	public function test_unauthorized_handler_fires_audit_and_dies(
		string $handler,
		string $action,
		string $nonce_action,
		string $reason
	): void {
		// Reset preserves: handle_reset preserves the completed flag,
		// so seed it before the request runs.
		if ( 'handle_reset' === $handler ) {
			Onboarding_Wizard::mark_complete();
		}

		if ( 'capability' === $reason ) {
			$this->become_subscriber();
		} else {
			$this->become_admin();
		}

		$nonce_value = 'capability' === $reason
			? wp_create_nonce( $nonce_action )
			: 'invalid';

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$post = array(
			'action'   => $action,
			'_wpnonce' => $nonce_value,
		);
		if ( 'handle_save' === $handler ) {
			$post['fosse_wizard_step']      = 'appearance';
			$post['activitypub_actor_mode'] = 'blog';
		}
		$_POST    = $post;
		$_REQUEST = $post;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$captured = array();
		$this->hook_audit_capture( $captured );
		$this->arm_die_trap();

		$this->expectException( \Exception::class );
		try {
			Onboarding_Wizard::$handler();
		} finally {
			$this->assertCount( 1, $captured, 'audit hook should fire exactly once' );
			$this->assertSame( $action, $captured[0][0] );
			$this->assertSame( $reason, $captured[0][2] );

			switch ( $handler ) {
				case 'handle_save':
					$this->assertFalse( get_option( 'activitypub_actor_mode' ) );
					break;
				case 'handle_skip':
				case 'handle_complete':
					$this->assertFalse( Onboarding_Wizard::is_complete() );
					break;
				case 'handle_reset':
					$this->assertTrue( Onboarding_Wizard::is_complete() );
					break;
			}
		}
	}

	/**
	 * Data provider for the unauthorized-handler test.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function unauthorized_handler_provider(): array {
		return array(
			'save: capability'     => array( 'handle_save', 'fosse_wizard_save', 'fosse_wizard', 'capability' ),
			'save: nonce'          => array( 'handle_save', 'fosse_wizard_save', 'fosse_wizard', 'nonce' ),
			'skip: capability'     => array( 'handle_skip', 'fosse_wizard_skip', 'fosse_wizard_skip', 'capability' ),
			'skip: nonce'          => array( 'handle_skip', 'fosse_wizard_skip', 'fosse_wizard_skip', 'nonce' ),
			'complete: capability' => array( 'handle_complete', 'fosse_wizard_complete', 'fosse_wizard_complete', 'capability' ),
			'complete: nonce'      => array( 'handle_complete', 'fosse_wizard_complete', 'fosse_wizard_complete', 'nonce' ),
			'reset: capability'    => array( 'handle_reset', 'fosse_wizard_reset', 'fosse_wizard_reset', 'capability' ),
			'reset: nonce'         => array( 'handle_reset', 'fosse_wizard_reset', 'fosse_wizard_reset', 'nonce' ),
		);
	}

	// --- normalize_handle_preview ---

	/**
	 * Empty input returns empty string.
	 */
	public function test_normalize_handle_preview_blank_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( '' ) );
	}

	/**
	 * Single `@` (no local-part, no domain) returns empty string.
	 */
	public function test_normalize_handle_preview_at_only_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( '@' ) );
	}

	/**
	 * `@host` (missing local-part) returns empty string.
	 */
	public function test_normalize_handle_preview_at_host_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( '@host.example' ) );
	}

	/**
	 * `user@` (missing domain) returns empty string.
	 */
	public function test_normalize_handle_preview_user_at_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( 'user@' ) );
	}

	/**
	 * Plain `host` (no `@`) returns empty string.
	 */
	public function test_normalize_handle_preview_no_at_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( 'host.example' ) );
	}

	/**
	 * Multiple `@`s past the leading one (malformed) return empty string.
	 */
	public function test_normalize_handle_preview_multi_at_returns_empty(): void {
		$this->assertSame( '', $this->call_normalize( 'user@host@extra' ) );
	}

	/**
	 * Bare `user@host` is normalized to `@user@host`.
	 */
	public function test_normalize_handle_preview_user_host_prepends_at(): void {
		$this->assertSame( '@user@host.example', $this->call_normalize( 'user@host.example' ) );
	}

	/**
	 * `@user@host` keeps the leading `@` and is returned unchanged.
	 */
	public function test_normalize_handle_preview_at_user_host_passes_through(): void {
		$this->assertSame( '@user@host.example', $this->call_normalize( '@user@host.example' ) );
	}

	// --- helpers ---

	/**
	 * Run a callback with an isolated `$wp_settings_errors` global.
	 *
	 * Snapshots the prior value (or unset state) before the callback runs and
	 * restores it afterwards, so tests that seed a settings_error fixture
	 * don't leak it into adjacent tests — the suite's `tear_down_state()`
	 * doesn't reset this global, and `add_settings_error()` does not require
	 * `register_setting()` so it would otherwise persist across tests.
	 *
	 * @param callable $callback Callback returning the rendered output.
	 * @return string Whatever the callback returns.
	 */
	private function with_isolated_settings_errors( callable $callback ): string {
		global $wp_settings_errors;

		$had_prior = isset( $wp_settings_errors );
		$prior     = $had_prior ? $wp_settings_errors : null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolating settings-error state for the callback.
		$wp_settings_errors = array();

		try {
			return (string) $callback();
		} finally {
			if ( $had_prior ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring snapshot taken above.
				$wp_settings_errors = $prior;
			} else {
				unset( $wp_settings_errors );
			}
		}
	}

	/**
	 * Authenticate as a non-administrator (subscriber).
	 */
	private function become_subscriber(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_sub_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'subscriber',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );
	}

	/**
	 * Authenticate as an administrator.
	 */
	private function become_admin(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_admin_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );
	}

	/**
	 * Replace the wp_die handler with one that throws so cap/nonce
	 * failures can be observed without exiting the test process.
	 */
	private function arm_die_trap(): void {
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new \Exception( 'wp_die: ' . wp_strip_all_tags( (string) $message ) );
				};
			}
		);
	}

	/**
	 * Hook fosse_wizard_unauthorized and append the firing args to the
	 * caller's array each time it fires. Pass the bucket by reference
	 * so the caller can inspect it after the request runs.
	 *
	 * @param array<int, array<int, mixed>> $captured Bucket to populate.
	 */
	private function hook_audit_capture( array &$captured ): void {
		add_action(
			'fosse_wizard_unauthorized',
			static function () use ( &$captured ): void {
				$captured[] = func_get_args();
			},
			10,
			3
		);
	}

	/**
	 * Invoke the private normalize_handle_preview() helper via reflection.
	 *
	 * @param string $handle Raw handle.
	 * @return string
	 */
	private function call_normalize( string $handle ): string {
		$method = new ReflectionMethod( Onboarding_Wizard::class, 'normalize_handle_preview' );
		return (string) $method->invoke( null, $handle );
	}

	/**
	 * Invoke the private format_mode_label() helper via reflection.
	 *
	 * @param string $mode        Actor mode value.
	 * @param string $user_handle Normalized `@user@host` for the current user, or empty.
	 * @param string $blog_handle Normalized `@blog@host` for the site, or empty.
	 * @return string
	 */
	private function call_format_mode_label( string $mode, string $user_handle, string $blog_handle ): string {
		$method = new ReflectionMethod( Onboarding_Wizard::class, 'format_mode_label' );
		return (string) $method->invoke( null, $mode, $user_handle, $blog_handle );
	}

	/**
	 * Render the wizard at a specific step and return the captured markup.
	 *
	 * @param string $step Step slug.
	 * @return string
	 */
	private function render_wizard_step( string $step ): string {
		$this->become_admin();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array( 'page' => 'fosse-wizard' );
		if ( '' !== $step ) {
			$_GET['step'] = $step;
		}

		ob_start();
		try {
			Onboarding_Wizard::render();
		} finally {
			$output = ob_get_clean();
		}

		return (string) $output;
	}

	/**
	 * Seed Atmosphere's connected account option.
	 *
	 * @param string $handle Connected Bluesky handle.
	 * @param string $did    Connected Bluesky DID.
	 * @return void
	 */
	private function seed_bluesky_connection( string $handle, string $did ): void {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => $did,
				'handle'       => $handle,
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);
	}

	/**
	 * Fail the test if the value is a WP_Error.
	 *
	 * @param mixed $value Value to check.
	 */
	private function assertNotWPError( $value ): void {
		if ( is_wp_error( $value ) ) {
			$this->fail( 'Unexpected WP_Error: ' . $value->get_error_message() );
		}
		$this->assertFalse( is_wp_error( $value ) );
	}

	/**
	 * Simulate a wizard save POST request.
	 *
	 * @param string               $step      Step slug.
	 * @param array<string, mixed> $post_data Additional POST data.
	 * @return void
	 */
	private function simulate_save_request( string $step, array $post_data = array() ): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );

		$defaults = array(
			'action'            => 'fosse_wizard_save',
			'_wpnonce'          => wp_create_nonce( 'fosse_wizard' ),
			'fosse_wizard_step' => $step,
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array_merge( $defaults, $post_data );
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);
	}

	/**
	 * Simulate a wizard skip request.
	 *
	 * @return void
	 */
	private function simulate_skip_request(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_skip',
			'_wpnonce' => wp_create_nonce( 'fosse_wizard_skip' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);
	}

	/**
	 * Simulate a wizard complete request.
	 *
	 * @return void
	 */
	private function simulate_complete_request(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_complete',
			'_wpnonce' => wp_create_nonce( 'fosse_wizard_complete' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);
	}

	/**
	 * Simulate a wizard reset request.
	 *
	 * @return void
	 */
	private function simulate_reset_request(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_wiz_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		$this->assertNotWPError( $user_id );
		wp_set_current_user( $user_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup.
		$_POST    = array(
			'action'   => 'fosse_wizard_reset',
			'_wpnonce' => wp_create_nonce( 'fosse_wizard_reset' ),
		);
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_redirect',
			static function () {
				throw new RedirectFired( 'redirect' );
			}
		);
	}
}
