<?php
/**
 * Tests for AP_Provider.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\AP_Provider;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies AP_Provider metadata, status shape, and save handling.
 */
class AP_ProviderTest extends BaseTestCase {

	/**
	 * Provider instance under test.
	 *
	 * @var AP_Provider
	 */
	private AP_Provider $provider;

	/**
	 * Set up a fresh provider and clean option state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_provider(): void {
		$this->provider = new AP_Provider();

		delete_option( 'activitypub_actor_mode' );
		delete_option( 'activitypub_support_post_types' );
		delete_option( 'activitypub_blog_identifier' );

		// Clear stale settings errors from prior tests.
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core global reset for testing.

		// Clear stale AP filter state so register_hooks() doesn't double-register.
		remove_all_filters( 'activitypub_default_blog_username' );
		remove_all_filters( 'sanitize_option_activitypub_blog_identifier' );

		// AP registers its sanitize callback during `admin_init`, which the
		// test bootstrap never fires. Wire the filter manually so tests
		// exercise the same path production hits when `update_option` runs.
		if ( class_exists( '\Activitypub\Sanitize' ) ) {
			add_filter(
				'sanitize_option_activitypub_blog_identifier',
				array( '\Activitypub\Sanitize', 'blog_identifier' )
			);
		}

		$this->provider->register_hooks();
	}

	/**
	 * Drop AP filter registrations between tests so re-running the test
	 * suite (or PHPUnit's data providers) doesn't accumulate stacked
	 * callbacks that race the bundled AP defaults.
	 *
	 * @after
	 */
	#[\PHPUnit\Framework\Attributes\After]
	public function tear_down_provider(): void {
		remove_all_filters( 'activitypub_default_blog_username' );
		remove_all_filters( 'sanitize_option_activitypub_blog_identifier' );
	}

	/**
	 * Slug is 'activitypub'.
	 */
	public function test_slug() {
		$this->assertSame( 'activitypub', $this->provider->get_slug() );
	}

	/**
	 * Display name is 'ActivityPub'.
	 */
	public function test_name() {
		$this->assertSame( 'ActivityPub', $this->provider->get_name() );
	}

	/**
	 * Status array contains the expected keys.
	 */
	public function test_status_has_expected_shape() {
		$status = $this->provider->get_status();

		$this->assertArrayHasKey( 'connected', $status );
		$this->assertArrayHasKey( 'actor_mode', $status );
		$this->assertArrayHasKey( 'post_types', $status );
		$this->assertArrayHasKey( 'address', $status );
	}

	/**
	 * AP is always "connected" when the plugin is loaded.
	 */
	public function test_status_always_connected() {
		$this->assertTrue( $this->provider->get_status()['connected'] );
	}

	/**
	 * Default actor mode is 'actor'.
	 */
	public function test_status_default_actor_mode() {
		$this->assertSame( 'actor', $this->provider->get_status()['actor_mode'] );
	}

	/**
	 * Default post types is array('post').
	 */
	public function test_status_default_post_types() {
		$this->assertSame( array( 'post' ), $this->provider->get_status()['post_types'] );
	}

	/**
	 * Status reflects the stored actor mode.
	 */
	public function test_status_reflects_stored_actor_mode() {
		update_option( 'activitypub_actor_mode', 'actor_blog' );

		$this->assertSame( 'actor_blog', $this->provider->get_status()['actor_mode'] );
	}

	/**
	 * Status reflects the stored post types.
	 */
	public function test_status_reflects_stored_post_types() {
		update_option( 'activitypub_support_post_types', array( 'page' ) );

		$this->assertSame( array( 'page' ), $this->provider->get_status()['post_types'] );
	}

	/**
	 * Setup section carries the fragment target id used by the wizard CTA
	 * and any in-page anchor link. Renaming the id without updating those
	 * call sites would silently break navigation.
	 */
	public function test_render_setup_section_has_anchor_id() {
		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="fosse-provider-activitypub"', $output );
	}

	/**
	 * AP-specific render_setup_section produces only AP-specific rows —
	 * no opening `<form>` tag and no submit button. The unified Settings
	 * form wraps every provider's fields together.
	 */
	public function test_render_setup_section_is_fields_only() {
		update_option( 'activitypub_actor_mode', 'blog' );

		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<form', $output );
		$this->assertStringNotContainsString( 'name="action"', $output );
		$this->assertStringNotContainsString( 'Save ActivityPub Settings', $output );
		$this->assertStringNotContainsString( 'name="activitypub_actor_mode"', $output );
		$this->assertStringNotContainsString( 'name="activitypub_support_post_types', $output );
	}

	/**
	 * ActivityPub has no OAuth flow, so the Settings page describes it as an
	 * active site profile instead of an account connection.
	 */
	public function test_render_connection_actions_explains_active_site_profile(): void {
		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="fosse-provider-activitypub-connection"', $output );
		$this->assertStringContainsString( 'ActivityPub', $output );
		$this->assertStringContainsString( 'Fediverse profile active', $output );
		$this->assertStringContainsString( 'Your WordPress site creates its own ActivityPub profile', $output );
		$this->assertStringNotContainsString( 'Connected automatically', $output );
		$this->assertStringNotContainsString( '<form', $output );
	}

	/**
	 * AP's render_setup_section preserves the Site handle field in
	 * `blog` mode and the secondary ActivityPub settings link.
	 */
	public function test_render_setup_section_renders_blog_mode_fields() {
		update_option( 'activitypub_actor_mode', 'blog' );
		update_option( 'activitypub_blog_identifier', 'my-site' );

		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="activitypub_blog_identifier"', $output );
		$this->assertStringContainsString( 'value="my-site"', $output );
		$this->assertStringContainsString( 'Site handle', $output );
		$this->assertStringContainsString( 'Site fediverse address', $output );
		$this->assertStringContainsString( 'Advanced ActivityPub settings', $output );
		$this->assertStringContainsString( 'options-general.php?page=activitypub', $output );
		$this->assertStringNotContainsString( 'class="form-table"', $output );
	}

	/**
	 * Status card exposes ActivityPub connection details and no longer renders
	 * a "Manage ActivityPub settings" deep link (issue #74) — the sidebar
	 * Settings menu entry replaces those per-card links.
	 */
	public function test_render_status_card_omits_manage_settings_link() {
		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<h2', $output );
		$this->assertStringContainsString( 'ActivityPub', $output );
		$this->assertStringContainsString( 'Active', $output );
		$this->assertStringContainsString( 'Content types', $output );
		$this->assertStringNotContainsString( '<table', $output );
		$this->assertStringNotContainsString( 'Manage ActivityPub settings', $output );
	}

	// --- save_settings tests ---------------------------------------------------

	/**
	 * Stable POST defaults that mirror a clean unified-save submission.
	 *
	 * @param array<string, mixed> $overrides POST overrides.
	 * @return array<string, mixed>
	 */
	private function build_post( array $overrides = array() ): array {
		return array_merge(
			array(
				'activitypub_actor_mode'         => 'blog',
				'activitypub_support_post_types' => array( 'post' ),
			),
			$overrides
		);
	}

	/**
	 * Valid save stores the actor mode option.
	 */
	public function test_save_settings_stores_actor_mode() {
		$this->assertTrue( $this->provider->save_settings( $this->build_post( array( 'activitypub_actor_mode' => 'actor_blog' ) ) ) );

		$this->assertSame( 'actor_blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Invalid actor mode is rejected — option is not updated.
	 */
	public function test_save_settings_rejects_invalid_actor_mode() {
		update_option( 'activitypub_actor_mode', 'actor' );

		$ok = $this->provider->save_settings( $this->build_post( array( 'activitypub_actor_mode' => 'evil_mode' ) ) );

		$this->assertFalse( $ok );
		$this->assertSame( 'actor', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Invalid actor mode produces an error notice on the FOSSE group.
	 */
	public function test_save_settings_error_notice_on_invalid_mode() {
		$this->provider->save_settings( $this->build_post( array( 'activitypub_actor_mode' => 'evil_mode' ) ) );

		$codes = array_column( get_settings_errors( 'fosse' ), 'code' );
		$this->assertContains( 'fosse_invalid_mode', $codes );
	}

	/**
	 * Valid save notifies AP's scheduler so federation propagates the mode
	 * change. WordPress fires add_option_<name> on first save and
	 * update_option_<name> on subsequent value changes; AP hooks both.
	 */
	public function test_save_settings_notifies_ap_actor_mode_scheduler() {
		$fired = false;
		$mark  = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'add_option_activitypub_actor_mode', $mark );
		add_action( 'update_option_activitypub_actor_mode', $mark );

		// First save (option does not yet exist) fires add_option_*.
		$this->provider->save_settings( $this->build_post( array( 'activitypub_actor_mode' => 'blog' ) ) );
		$this->assertTrue( $fired, 'add_option_activitypub_actor_mode should fire on first save.' );

		// Second save (value change) fires update_option_*.
		$fired = false;
		$this->provider->save_settings( $this->build_post( array( 'activitypub_actor_mode' => 'actor_blog' ) ) );
		$this->assertTrue( $fired, 'update_option_activitypub_actor_mode should fire on value change.' );
	}

	// --- Default blog username filter ---------------------------------------

	/**
	 * Default username strips multi-label hosts down to the first label so
	 * Jurassic-Ninja-style hostnames stop bleeding into the site handle.
	 */
	public function test_filter_default_blog_username_uses_first_host_label() {
		$this->assertSame(
			'increasing-king-tuna',
			AP_Provider::filter_default_blog_username( 'increasing-king-tuna.jurassic.ninja' )
		);
	}

	/**
	 * A two-label host still yields its first label, not the FQDN.
	 */
	public function test_filter_default_blog_username_two_label_host() {
		$this->assertSame( 'example', AP_Provider::filter_default_blog_username( 'example.com' ) );
	}

	/**
	 * Hosts that already lack dots (single-label `localhost`-style installs)
	 * pass through unchanged so AP keeps a usable default.
	 */
	public function test_filter_default_blog_username_single_label_host_passthrough() {
		$this->assertSame( 'localhost', AP_Provider::filter_default_blog_username( 'localhost' ) );
	}

	/**
	 * Empty input returns an empty string rather than mangling AP's fallback.
	 */
	public function test_filter_default_blog_username_empty_passthrough() {
		$this->assertSame( '', AP_Provider::filter_default_blog_username( '' ) );
	}

	/**
	 * Non-string input (rare, but filters can receive arbitrary types) is
	 * coerced to a string without throwing.
	 */
	public function test_filter_default_blog_username_non_string_input() {
		$this->assertSame( '', AP_Provider::filter_default_blog_username( null ) );
	}

	/**
	 * When the candidate collides with an existing user_login, the filter
	 * appends a numeric suffix until it finds a free slot.
	 */
	public function test_filter_default_blog_username_collides_with_user_login() {
		wp_insert_user(
			array(
				'user_login' => 'example',
				'user_email' => 'example@example.test',
				'user_pass'  => 'test-pass',
			)
		);

		$this->assertSame(
			'example-1',
			AP_Provider::filter_default_blog_username( 'example.com' )
		);
	}

	/**
	 * Collisions stack: with `example` and `example-1` both taken, the
	 * filter keeps walking until it finds an unused suffix.
	 */
	public function test_filter_default_blog_username_walks_collision_suffixes() {
		wp_insert_user(
			array(
				'user_login' => 'example',
				'user_email' => 'example@example.test',
				'user_pass'  => 'test-pass',
			)
		);
		wp_insert_user(
			array(
				'user_login' => 'example-1',
				'user_email' => 'example-1@example.test',
				'user_pass'  => 'test-pass',
			)
		);

		$this->assertSame(
			'example-2',
			AP_Provider::filter_default_blog_username( 'example.com' )
		);
	}

	/**
	 * Collisions with `user_nicename` (the slug) are also avoided.
	 */
	public function test_filter_default_blog_username_collides_with_user_nicename() {
		wp_insert_user(
			array(
				'user_login'    => 'unrelated_login_aaa',
				'user_nicename' => 'example',
				'user_email'    => 'aaa@example.test',
				'user_pass'     => 'test-pass',
			)
		);

		$this->assertSame(
			'example-1',
			AP_Provider::filter_default_blog_username( 'example.com' )
		);
	}

	/**
	 * Registering hooks attaches the default-username filter so AP's
	 * `Blog::get_default_username()` invocations pick up our shortened
	 * default end-to-end.
	 */
	public function test_register_hooks_attaches_default_blog_username_filter() {
		// register_hooks() already ran in set_up_provider(); make sure the
		// filter actually fires when AP applies it.
		$result = apply_filters( 'activitypub_default_blog_username', 'foo.example.com' );
		$this->assertSame( 'foo', $result );
	}

	// --- User / blog address helpers ----------------------------------------

	/**
	 * `get_user_address()` resolves to the current user's webfinger when an
	 * AP-eligible user is signed in. AP's `user_can_activitypub` filter is
	 * stubbed because WorDBless doesn't fire AP's activation, so the
	 * `activitypub` capability isn't granted to admins by default.
	 */
	public function test_get_user_address_returns_webfinger_for_current_user() {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_address_user',
				'user_email' => 'address@example.test',
				'user_pass'  => 'test-pass',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		add_filter( 'activitypub_user_can_activitypub', '__return_true' );
		$address = $this->provider->get_user_address();
		remove_filter( 'activitypub_user_can_activitypub', '__return_true' );

		$this->assertNotSame( '', $address );
		$this->assertStringContainsString( '@', $address );
	}

	/**
	 * `get_blog_address()` returns the blog webfinger regardless of the
	 * stored actor mode — the helper is mode-agnostic so callers can
	 * combine it freely.
	 */
	public function test_get_blog_address_returns_blog_webfinger_in_actor_mode() {
		update_option( 'activitypub_actor_mode', 'actor' );

		$address = $this->provider->get_blog_address();

		$this->assertNotSame( '', $address );
		$this->assertStringContainsString( '@', $address );
	}

	/**
	 * Status exposes both `user_address` and `blog_address` keys so the UI
	 * can render the dual identity in `actor_blog` mode.
	 */
	public function test_status_exposes_separate_user_and_blog_addresses() {
		$status = $this->provider->get_status();

		$this->assertArrayHasKey( 'user_address', $status );
		$this->assertArrayHasKey( 'blog_address', $status );
	}

	/**
	 * `get_status()` only resolves the handle for the active mode. Building
	 * AP's actor models dispatches `activitypub_construct_model_actor` and
	 * runs whatever third-party code is hooked there — paying that cost for
	 * a handle the caller can't render in the current mode is wasteful.
	 */
	public function test_status_does_not_resolve_blog_address_in_actor_mode() {
		update_option( 'activitypub_actor_mode', 'actor' );

		$blog_constructed = false;
		add_action(
			'activitypub_construct_model_actor',
			static function ( $actor ) use ( &$blog_constructed ) {
				if ( $actor instanceof \Activitypub\Model\Blog ) {
					$blog_constructed = true;
				}
			}
		);

		$status = $this->provider->get_status();

		remove_all_actions( 'activitypub_construct_model_actor' );

		$this->assertSame( '', $status['blog_address'] );
		$this->assertFalse( $blog_constructed, 'Blog actor should not be constructed in actor mode.' );
	}

	/**
	 * Mirror of the above for the user actor in `blog` mode.
	 */
	public function test_status_does_not_resolve_user_address_in_blog_mode() {
		update_option( 'activitypub_actor_mode', 'blog' );

		$user_constructed = false;
		add_action(
			'activitypub_construct_model_actor',
			static function ( $actor ) use ( &$user_constructed ) {
				if ( $actor instanceof \Activitypub\Model\User ) {
					$user_constructed = true;
				}
			}
		);

		$status = $this->provider->get_status();

		remove_all_actions( 'activitypub_construct_model_actor' );

		$this->assertSame( '', $status['user_address'] );
		$this->assertFalse( $user_constructed, 'User actor should not be constructed in blog mode.' );
	}

	/**
	 * In `blog` mode, the legacy `address` key prefers the blog handle so
	 * existing callers keep their current behavior.
	 */
	public function test_status_legacy_address_in_blog_mode_uses_blog_handle() {
		update_option( 'activitypub_actor_mode', 'blog' );

		$status = $this->provider->get_status();

		$this->assertNotEmpty( $status['blog_address'] );
		$this->assertSame( $status['blog_address'], $status['address'] );
	}

	// --- Mode helpers --------------------------------------------------------

	/**
	 * Mode helpers correctly classify `actor`, `blog`, and `actor_blog`.
	 */
	public function test_mode_helpers_classify_modes() {
		$this->assertTrue( $this->provider->mode_includes_user( 'actor' ) );
		$this->assertFalse( $this->provider->mode_includes_blog( 'actor' ) );

		$this->assertFalse( $this->provider->mode_includes_user( 'blog' ) );
		$this->assertTrue( $this->provider->mode_includes_blog( 'blog' ) );

		$this->assertTrue( $this->provider->mode_includes_user( 'actor_blog' ) );
		$this->assertTrue( $this->provider->mode_includes_blog( 'actor_blog' ) );
	}

	/**
	 * `get_status()` memoizes its return value within the request so the
	 * Status page (which renders each provider's status twice — once to
	 * filter on `connected`, once via `render_status_card()`) doesn't pay
	 * AP's actor-handle resolution cost twice. The cache survives the
	 * full request because every mutation handler ends in `wp_safe_redirect();exit`,
	 * so re-reading after a setting change is a fresh-request concern,
	 * not a same-request concern.
	 *
	 * Verifies the memo by counting `pre_option_activitypub_actor_mode`
	 * fires: every `get_option( 'activitypub_actor_mode', ... )` call
	 * dispatches that filter exactly once, and `get_status()` reads it
	 * unconditionally on the uncached path. A working cache means the
	 * second `get_status()` call must not trigger another fire.
	 */
	public function test_get_status_memoizes_within_request(): void {
		update_option( 'activitypub_actor_mode', 'actor' );

		$fires   = 0;
		$counter = function ( $pre ) use ( &$fires ) {
			++$fires;
			return $pre;
		};
		add_filter( 'pre_option_activitypub_actor_mode', $counter );

		try {
			$first         = $this->provider->get_status();
			$fires_after_1 = $fires;
			$second        = $this->provider->get_status();
			$fires_after_2 = $fires;
		} finally {
			remove_filter( 'pre_option_activitypub_actor_mode', $counter );
		}

		$this->assertSame( $first, $second, 'Cached status payload must be identical to the first call.' );
		$this->assertGreaterThan(
			0,
			$fires_after_1,
			'Sanity check: the first call should hit the activitypub_actor_mode filter at least once (uncached path).'
		);
		$this->assertSame(
			$fires_after_1,
			$fires_after_2,
			'The second get_status() call must add zero activitypub_actor_mode reads; it should hit the in-memory cache and bail before any get_option() runs.'
		);
	}

	// --- Site Handle persistence --------------------------------------------

	/**
	 * Saving a site handle persists it through AP's sanitizer so the same
	 * value is stored regardless of which surface accepted the input.
	 */
	public function test_save_settings_persists_blog_identifier() {
		$this->provider->save_settings(
			$this->build_post(
				array(
					'activitypub_actor_mode'      => 'blog',
					'activitypub_blog_identifier' => 'my-fosse-site',
				)
			)
		);

		$this->assertSame( 'my-fosse-site', get_option( 'activitypub_blog_identifier' ) );
	}

	/**
	 * Submitted handles run through AP's `Sanitize::blog_identifier` so the
	 * same canonicalization rules apply as on AP's native settings page.
	 * Underscores in the raw input become dashes on the way through
	 * `sanitize_title`, which proves the upstream sanitizer ran rather than
	 * us storing the input verbatim.
	 *
	 * Collision rejection uses `WP_User_Query`'s LIKE search internally,
	 * which WorDBless's dbless engine can't satisfy — so collision behavior
	 * itself is asserted via the unit-tested
	 * `filter_default_blog_username` collision tests above.
	 */
	public function test_save_settings_blog_identifier_runs_through_ap_sanitizer() {
		$this->provider->save_settings(
			$this->build_post(
				array(
					'activitypub_actor_mode'      => 'blog',
					'activitypub_blog_identifier' => 'Has Spaces & Caps',
				)
			)
		);

		$this->assertSame( 'has-spaces-caps', get_option( 'activitypub_blog_identifier' ) );
	}

	/**
	 * AP's sanitizer adds settings errors under `activitypub_blog_identifier`
	 * when the input collides with an existing user. The FOSSE Settings page
	 * only renders `settings_errors('fosse')`, so we re-tag fresh AP errors
	 * into our group so users actually see why their handle was rejected.
	 *
	 * The test forces the colliding-input branch directly via AP's filter
	 * (WorDBless's dbless engine doesn't satisfy `WP_User_Query`'s LIKE
	 * search, so seeding a real colliding user wouldn't trigger AP's
	 * collision path).
	 */
	public function test_save_settings_rewires_ap_settings_errors_to_fosse_group() {
		add_filter(
			'sanitize_option_activitypub_blog_identifier',
			static function ( $value ) {
				add_settings_error(
					'activitypub_blog_identifier',
					'collision_test',
					'Collision test error.',
					'error'
				);
				return $value;
			},
			11 // After AP's own callback.
		);

		$this->provider->save_settings(
			$this->build_post(
				array(
					'activitypub_actor_mode'      => 'blog',
					'activitypub_blog_identifier' => 'whatever',
				)
			)
		);

		$fosse_codes = array_column( get_settings_errors( 'fosse' ), 'code' );
		$this->assertContains( 'collision_test', $fosse_codes );
	}

	/**
	 * Fresh rejections are captured by queue-position even when an error
	 * with the same `activitypub_blog_identifier` code already sits on the
	 * queue from earlier in the request. AP's sanitizer reuses a constant
	 * code for every rejection, so a code-only snapshot would mask the
	 * fresh entry.
	 */
	public function test_save_settings_captures_fresh_collision_when_code_already_queued() {
		// Pre-seed an unrelated error using AP's constant code.
		add_settings_error(
			'activitypub_blog_identifier',
			'activitypub_blog_identifier',
			'Pre-existing unrelated error.',
			'error'
		);

		add_filter(
			'sanitize_option_activitypub_blog_identifier',
			static function ( $value ) {
				add_settings_error(
					'activitypub_blog_identifier',
					'activitypub_blog_identifier',
					'Fresh collision rejection.',
					'error'
				);
				return $value;
			},
			11
		);

		$this->provider->save_settings(
			$this->build_post(
				array(
					'activitypub_actor_mode'      => 'blog',
					'activitypub_blog_identifier' => 'whatever',
				)
			)
		);

		$fosse_messages = array_column( get_settings_errors( 'fosse' ), 'message' );
		$this->assertContains( 'Fresh collision rejection.', $fosse_messages );
		$this->assertNotContains( 'Pre-existing unrelated error.', $fosse_messages );
	}

	/**
	 * `save_settings()` returns false when AP's sanitizer rejected the
	 * Site Handle, so the unified Settings handler can suppress the
	 * blanket "settings saved" success notice. Mode and post-type writes
	 * still landed; the AP error explains what didn't.
	 */
	public function test_save_settings_returns_false_on_blog_identifier_rejection() {
		add_filter(
			'sanitize_option_activitypub_blog_identifier',
			static function ( $value ) {
				add_settings_error(
					'activitypub_blog_identifier',
					'rejection_test',
					'Rejected.',
					'error'
				);
				return $value;
			},
			11
		);

		$ok = $this->provider->save_settings(
			$this->build_post(
				array(
					'activitypub_actor_mode'      => 'blog',
					'activitypub_blog_identifier' => 'whatever',
				)
			)
		);

		$this->assertFalse( $ok );
		$this->assertContains(
			'rejection_test',
			array_column( get_settings_errors( 'fosse' ), 'code' )
		);
	}

	/**
	 * Array-shaped POST input for the blog identifier is rejected silently
	 * rather than tripping `sanitize_text_field`'s array-to-string warning
	 * (which `phpunit.xml.dist` promotes to a failure via `failOnWarning`).
	 */
	public function test_save_settings_array_blog_identifier_is_rejected_safely() {
		update_option( 'activitypub_blog_identifier', 'preserved-handle' );

		$this->provider->save_settings(
			$this->build_post(
				array(
					'activitypub_actor_mode'      => 'blog',
					'activitypub_blog_identifier' => array( 'malicious', 'array' ),
				)
			)
		);

		$this->assertSame( 'preserved-handle', get_option( 'activitypub_blog_identifier' ) );
	}

	/**
	 * An empty submitted handle leaves any prior value intact rather than
	 * stomping the option with an empty string.
	 */
	public function test_save_settings_empty_blog_identifier_does_not_clobber_existing_value() {
		update_option( 'activitypub_blog_identifier', 'preserved-handle' );

		$this->provider->save_settings(
			$this->build_post(
				array(
					'activitypub_actor_mode'      => 'blog',
					'activitypub_blog_identifier' => '',
				)
			)
		);

		$this->assertSame( 'preserved-handle', get_option( 'activitypub_blog_identifier' ) );
	}

	/**
	 * The Site handle field renders in `blog` mode with the current saved
	 * value pre-filled so site owners can edit it from FOSSE's surface.
	 */
	public function test_render_setup_section_shows_site_handle_field_in_blog_mode() {
		update_option( 'activitypub_actor_mode', 'blog' );
		update_option( 'activitypub_blog_identifier', 'my-site' );

		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="activitypub_blog_identifier"', $output );
		$this->assertStringContainsString( 'value="my-site"', $output );
		$this->assertStringContainsString( 'Site handle', $output );
	}

	/**
	 * When the option is empty, the dynamic default appears as a
	 * `placeholder` rather than a `value`. Pre-filling `value` would
	 * freeze the proposed default into the option as soon as the user
	 * saved any other field — breaking AP's "unset → recompute on read"
	 * contract.
	 */
	public function test_render_setup_section_uses_placeholder_for_unset_blog_identifier() {
		update_option( 'activitypub_actor_mode', 'blog' );
		delete_option( 'activitypub_blog_identifier' );

		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="activitypub_blog_identifier"', $output );
		$this->assertStringContainsString( 'value=""', $output );
		$this->assertMatchesRegularExpression(
			'~placeholder="[^"]+"~',
			$output,
			'Setup field should expose the dynamic default as a placeholder when no value is stored.'
		);
	}

	/**
	 * The Site Handle field is hidden in `actor` mode where there is no
	 * site identity to configure.
	 */
	public function test_render_setup_section_hides_site_handle_field_in_actor_mode() {
		update_option( 'activitypub_actor_mode', 'actor' );

		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'name="activitypub_blog_identifier"', $output );
	}

	/**
	 * In `actor_blog` mode the setup section surfaces both the user handle
	 * and the site handle row so neither identity is hidden by the UI.
	 */
	public function test_render_setup_section_in_actor_blog_mode_shows_both_addresses() {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_dual_user',
				'user_email' => 'dual@example.test',
				'user_pass'  => 'test-pass',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		update_option( 'activitypub_actor_mode', 'actor_blog' );

		add_filter( 'activitypub_user_can_activitypub', '__return_true' );
		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();
		remove_filter( 'activitypub_user_can_activitypub', '__return_true' );

		$this->assertStringContainsString( 'Your fediverse address', $output );
		$this->assertStringContainsString( 'Site fediverse address', $output );
		$this->assertStringContainsString( 'name="activitypub_blog_identifier"', $output );

		// CSS-contract: the AP-address tokens emitted here must match the
		// modifier classes asserted in Admin_CSS_Test.
		$this->assertStringContainsString( 'fosse-token--ap-address', $output );
		$this->assertStringContainsString( 'fosse-admin-token--ap-address', $output );
	}

	/**
	 * Status card renders label/value pairs as a semantic definition list
	 * rather than the legacy table layout.
	 */
	public function test_render_status_card_uses_definition_list_label_value_pairs() {
		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<dl', $output );
		$this->assertStringContainsString( '<dt', $output );
		$this->assertStringContainsString( '<dd', $output );
		$this->assertStringContainsString( 'ActivityPub profile', $output );
		$this->assertStringNotContainsString( 'widefat striped', $output );
	}

	/**
	 * Status card emits the AP-address token with the
	 * `fosse-status-card__token--ap-address` modifier so the CSS rule
	 * in `admin.css` targets a real DOM node.
	 */
	public function test_render_status_card_emits_status_card_ap_address_token() {
		update_option( 'activitypub_actor_mode', 'blog' );
		update_option( 'activitypub_blog_identifier', 'my-site' );

		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fosse-status-card__token--ap-address', $output );
	}
}
