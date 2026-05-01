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
	 * Setup section carries the fragment target id used by the Status-page
	 * "Manage ActivityPub settings" deep link. Renaming the id without
	 * updating the link would silently break navigation.
	 */
	public function test_render_setup_section_has_anchor_id() {
		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="fosse-provider-activitypub"', $output );
	}

	/**
	 * Status card deep-links back to the ActivityPub setup section. The
	 * fragment must match the id rendered by render_setup_section().
	 */
	public function test_render_status_card_has_manage_settings_link() {
		ob_start();
		$this->provider->render_status_card();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Manage ActivityPub settings', $output );
		$this->assertStringContainsString( '#fosse-provider-activitypub', $output );
	}

	/**
	 * Setup UI explains the available actor modes and links to blog profile settings.
	 */
	public function test_render_setup_section_explains_actor_modes() {
		ob_start();
		$this->provider->render_setup_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Each WordPress author publishes from their own fediverse profile.', $output );
		$this->assertStringContainsString( 'One site-wide profile publishes every post, regardless of author.', $output );
		$this->assertStringContainsString( 'Authors keep individual profiles, and the site also has its own blog profile.', $output );
		$this->assertStringContainsString( 'Changing modes does not move followers between profiles.', $output );
		$this->assertStringContainsString( 'Configure the site-wide blog profile name, image, and description', $output );
		$this->assertStringNotContainsString( '<fieldset aria-describedby="fosse-activitypub-actor-mode-note">', $output );
		$this->assertStringContainsString( '<legend class="screen-reader-text">Actor Mode</legend>', $output );
		$this->assertMatchesRegularExpression(
			'~<input[^>]+id="fosse-activitypub-actor-mode-actor"[^>]+aria-describedby="fosse-activitypub-actor-mode-actor-desc fosse-activitypub-actor-mode-note"[^>]+/>~',
			$output
		);
		$this->assertMatchesRegularExpression(
			'~<input[^>]+id="fosse-activitypub-actor-mode-blog"[^>]+aria-describedby="fosse-activitypub-actor-mode-blog-desc fosse-activitypub-actor-mode-note"[^>]+/>~',
			$output
		);
		$this->assertMatchesRegularExpression(
			'~<input[^>]+id="fosse-activitypub-actor-mode-actor-blog"[^>]+aria-describedby="fosse-activitypub-actor-mode-actor-blog-desc fosse-activitypub-actor-mode-note"[^>]+/>~',
			$output
		);
		$this->assertStringContainsString( '<p id="fosse-activitypub-actor-mode-note" class="description">', $output );
		$this->assertMatchesRegularExpression(
			'~<a href="[^"]*options-general\.php\?page=activitypub(?:&#038;|&amp;)tab=blog-profile">Blog profile settings</a>~',
			$output
		);
	}

	/**
	 * Setup UI selects the stored actor mode.
	 */
	public function test_render_setup_section_checks_selected_actor_mode() {
		$modes = array( 'actor', 'blog', 'actor_blog' );

		foreach ( $modes as $selected_mode ) {
			update_option( 'activitypub_actor_mode', $selected_mode );

			ob_start();
			$this->provider->render_setup_section();
			$output = ob_get_clean();

			foreach ( $modes as $mode ) {
				$input = $this->get_actor_mode_input_markup( $output, $mode );

				if ( $selected_mode === $mode ) {
					$this->assertStringContainsString( "checked='checked'", $input );
				} else {
					$this->assertStringNotContainsString( "checked='checked'", $input );
				}
			}
		}
	}

	/**
	 * Get the rendered radio input for an ActivityPub actor mode.
	 *
	 * @param string $output Rendered setup section markup.
	 * @param string $mode   Actor mode value.
	 * @return string
	 */
	private function get_actor_mode_input_markup( string $output, string $mode ): string {
		preg_match(
			'~<input\b(?=[^>]*\bname="activitypub_actor_mode")(?=[^>]*\bvalue="' . preg_quote( $mode, '~' ) . '")[^>]*>~s',
			$output,
			$matches
		);

		$this->assertNotEmpty( $matches, 'Expected actor mode input to be present.' );

		return $matches[0];
	}

	// --- handle_save tests ---------------------------------------------------

	/**
	 * Create an admin user and set up a simulated save request.
	 *
	 * @param array<string, mixed> $post_data POST data to merge in.
	 * @return void
	 */
	private function simulate_save_request( array $post_data = array() ): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'fosse_admin_' . uniqid( '', true ),
				'user_pass'  => 'test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$defaults = array(
			'action'                         => 'fosse_save_ap_settings',
			'_wpnonce'                       => wp_create_nonce( 'fosse_save_ap_settings' ),
			'activitypub_actor_mode'         => 'blog',
			'activitypub_support_post_types' => array( 'post' ),
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- test setup, nonce is in the data.
		$_POST    = array_merge( $defaults, $post_data );
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Catch the redirect so exit doesn't kill the test.
		add_filter(
			'wp_redirect',
			static function () {
				throw new \Exception( 'redirect' );
			}
		);
	}

	/**
	 * Valid save stores the actor mode option.
	 */
	public function test_handle_save_stores_actor_mode() {
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'actor_blog' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor_blog', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Valid save stores the post types option.
	 */
	public function test_handle_save_stores_post_types() {
		$this->simulate_save_request( array( 'activitypub_support_post_types' => array( 'post', 'page' ) ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( array( 'post', 'page' ), get_option( 'activitypub_support_post_types' ) );
	}

	/**
	 * Invalid actor mode is rejected — option is not updated.
	 */
	public function test_handle_save_rejects_invalid_actor_mode() {
		update_option( 'activitypub_actor_mode', 'actor' );
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'evil_mode' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'actor', get_option( 'activitypub_actor_mode' ) );
	}

	/**
	 * Invalid actor mode produces an error notice, not a success notice.
	 */
	public function test_handle_save_error_notice_on_invalid_mode() {
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'evil_mode' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$errors = get_settings_errors( 'fosse' );
		$codes  = array_column( $errors, 'code' );
		$this->assertContains( 'fosse_invalid_mode', $codes );
		$this->assertNotContains( 'fosse_saved', $codes );
	}

	/**
	 * Valid save notifies AP's scheduler so federation propagates the mode
	 * change. WordPress fires add_option_<name> on first save and
	 * update_option_<name> on subsequent value changes; AP hooks both.
	 */
	public function test_handle_save_notifies_ap_actor_mode_scheduler() {
		$fired = false;
		$mark  = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'add_option_activitypub_actor_mode', $mark );
		add_action( 'update_option_activitypub_actor_mode', $mark );

		// First save (option does not yet exist) fires add_option_*.
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'blog' ) );
		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}
		$this->assertTrue( $fired, 'add_option_activitypub_actor_mode should fire on first save.' );

		// Second save (value change) fires update_option_*.
		$fired = false;
		$this->simulate_save_request( array( 'activitypub_actor_mode' => 'actor_blog' ) );
		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}
		$this->assertTrue( $fired, 'update_option_activitypub_actor_mode should fire on value change.' );
	}

	/**
	 * Invalid post types are filtered out.
	 */
	public function test_handle_save_filters_invalid_post_types() {
		$this->simulate_save_request(
			array( 'activitypub_support_post_types' => array( 'post', 'nonexistent_type', 'page' ) )
		);

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$saved = get_option( 'activitypub_support_post_types' );
		$this->assertContains( 'post', $saved );
		$this->assertContains( 'page', $saved );
		$this->assertNotContains( 'nonexistent_type', $saved );
	}

	/**
	 * Non-array post types input is safely handled.
	 */
	public function test_handle_save_handles_non_array_post_types() {
		$this->simulate_save_request( array( 'activitypub_support_post_types' => 'not_an_array' ) );

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertIsArray( get_option( 'activitypub_support_post_types' ) );
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

	// --- Site Handle persistence --------------------------------------------

	/**
	 * Saving a site handle persists it through AP's sanitizer so the same
	 * value is stored regardless of which surface accepted the input.
	 */
	public function test_handle_save_persists_blog_identifier() {
		$this->simulate_save_request(
			array(
				'activitypub_actor_mode'      => 'blog',
				'activitypub_blog_identifier' => 'my-fosse-site',
			)
		);

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

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
	public function test_handle_save_blog_identifier_runs_through_ap_sanitizer() {
		$this->simulate_save_request(
			array(
				'activitypub_actor_mode'      => 'blog',
				'activitypub_blog_identifier' => 'Has Spaces & Caps',
			)
		);

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'has-spaces-caps', get_option( 'activitypub_blog_identifier' ) );
	}

	/**
	 * An empty submitted handle leaves any prior value intact rather than
	 * stomping the option with an empty string.
	 */
	public function test_handle_save_empty_blog_identifier_does_not_clobber_existing_value() {
		update_option( 'activitypub_blog_identifier', 'preserved-handle' );

		$this->simulate_save_request(
			array(
				'activitypub_actor_mode'      => 'blog',
				'activitypub_blog_identifier' => '',
			)
		);

		try {
			$this->provider->handle_save();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'preserved-handle', get_option( 'activitypub_blog_identifier' ) );
	}

	/**
	 * The Site Handle field renders in `blog` mode with the current saved
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
		$this->assertStringContainsString( 'Site Handle', $output );
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
	}
}
