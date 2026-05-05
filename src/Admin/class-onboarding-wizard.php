<?php
/**
 * First-run onboarding wizard.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Renders and handles the multi-step onboarding wizard shown on first activation.
 *
 * Steps:
 *  1. Destinations - destination intent selection
 *  2. Appearance - actor mode selection (blog / actor / actor_blog)
 *  3. Content - post type selection
 *  4. Bluesky - optional OAuth connection
 *  5. Review - summary and handoff to Setup/Status pages
 */
class Onboarding_Wizard {

	/**
	 * Option key tracking wizard completion.
	 *
	 * @var string
	 */
	public const COMPLETED_OPTION = 'fosse_onboarding_completed';

	/**
	 * Option key storing the wizard's destination intent.
	 *
	 * This controls onboarding flow and Review-summary wording only. It does
	 * not enable or disable publishing destinations.
	 *
	 * @var string
	 */
	public const DESTINATION_OPTION = 'fosse_onboarding_destination';

	/**
	 * Option key for the one-shot activation redirect signal.
	 *
	 * Stored with autoload `false` so the option only hits the DB when
	 * an activation actually wrote it; consumed and deleted on the
	 * first qualifying admin request.
	 *
	 * @var string
	 */
	public const REDIRECT_OPTION = 'fosse_activation_redirect';

	/**
	 * Legacy transient key for the activation redirect.
	 *
	 * Earlier installs of FOSSE used a 30-second transient. Kept as an
	 * alias so {@see Menu::maybe_redirect_to_wizard()} can migrate any
	 * lingering transient into the new option-backed signal.
	 *
	 * @deprecated Use {@see self::REDIRECT_OPTION} instead.
	 * @var string
	 */
	public const REDIRECT_TRANSIENT = self::REDIRECT_OPTION;

	/**
	 * Valid step slugs in order.
	 *
	 * @var string[]
	 */
	private const STEPS = array( 'destinations', 'appearance', 'content', 'bluesky', 'complete' );

	/**
	 * Destination intent that includes the Bluesky connection step.
	 *
	 * @var string
	 */
	private const DESTINATION_FEDIVERSE_BLUESKY = 'fediverse_bluesky';

	/**
	 * Destination intent that skips the Bluesky connection step.
	 *
	 * @var string
	 */
	private const DESTINATION_FEDIVERSE_ONLY = 'fediverse_only';

	/**
	 * Valid destination values.
	 *
	 * @var string[]
	 */
	private const DESTINATIONS = array(
		self::DESTINATION_FEDIVERSE_BLUESKY,
		self::DESTINATION_FEDIVERSE_ONLY,
	);

	/**
	 * Allowed actor mode values.
	 *
	 * @var string[]
	 */
	private const ACTOR_MODES = array( 'actor', 'blog', 'actor_blog' );

	/**
	 * Whether the wizard has been completed.
	 *
	 * @return bool
	 */
	public static function is_complete(): bool {
		return (bool) get_option( self::COMPLETED_OPTION );
	}

	/**
	 * Whether a registered ActivityPub provider is currently available.
	 *
	 * Gates the activation redirect and the wizard render. If neither
	 * the bundled nor a standalone AP install is loaded, the wizard
	 * has no actor data to walk users through, so we degrade to a
	 * notice rather than rendering broken steps.
	 *
	 * @return bool
	 */
	public static function is_activitypub_available(): bool {
		return null !== Connection_Provider_Registry::get_provider( 'activitypub' );
	}

	/**
	 * Mark the wizard as complete.
	 *
	 * @return void
	 */
	public static function mark_complete(): void {
		update_option( self::COMPLETED_OPTION, 1, false );
	}

	/**
	 * Register hooks for the wizard.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_fosse_wizard_save', array( static::class, 'handle_save' ) );
		add_action( 'admin_post_fosse_wizard_skip', array( static::class, 'handle_skip' ) );
		add_action( 'admin_post_fosse_wizard_complete', array( static::class, 'handle_complete' ) );
		add_action( 'admin_post_fosse_wizard_reset', array( static::class, 'handle_reset' ) );
	}

	/**
	 * Render the wizard page.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_capability(
			'fosse_wizard_render',
			__( 'You do not have permission to access this page.', 'fosse' )
		);

		?>
		<div class="wrap fosse-wizard">
		<?php

		if ( ! self::is_activitypub_available() ) {
			self::render_unavailable_notice();
			?>
			</div>
			<?php
			return;
		}

		$step = self::get_current_step();

		switch ( $step ) {
			case 'destinations':
				self::render_step_destinations();
				break;
			case 'appearance':
				self::render_step_appearance();
				break;
			case 'content':
				self::render_step_content();
				break;
			case 'bluesky':
				self::render_step_bluesky();
				break;
			case 'complete':
				self::render_step_complete();
				break;
			default:
				self::render_step_destinations();
				break;
		}

		?>
		</div>
		<?php
	}

	/**
	 * Render the blocking notice shown when ActivityPub is unavailable.
	 *
	 * The wizard's appearance and content steps depend on AP's actor
	 * models and option keys. If AP isn't loaded (bundled load failed
	 * and no standalone install is present), there's nothing for the
	 * wizard to walk a user through — show a clear notice instead of
	 * rendering broken steps.
	 *
	 * @return void
	 */
	private static function render_unavailable_notice(): void {
		?>
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'Setup is unavailable', 'fosse' ); ?></h1>
		<div class="notice notice-error inline">
			<p>
				<?php esc_html_e( 'FOSSE could not find the ActivityPub plugin. The setup wizard needs ActivityPub to be active before it can configure your site.', 'fosse' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Reactivate FOSSE, or install and activate ActivityPub, then return to this page.', 'fosse' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle form submissions from wizard steps.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		self::require_capability(
			'fosse_wizard_save',
			__( 'You do not have permission to save wizard settings.', 'fosse' )
		);
		self::require_nonce( 'fosse_wizard_save', 'fosse_wizard' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by self::require_nonce() above.
		$step = sanitize_text_field( wp_unslash( $_POST['fosse_wizard_step'] ?? '' ) );

		if ( 'destinations' === $step ) {
			$destination = sanitize_text_field( wp_unslash( $_POST['fosse_onboarding_destination'] ?? '' ) );
			if ( ! in_array( $destination, self::DESTINATIONS, true ) ) {
				$destination = self::DESTINATION_FEDIVERSE_BLUESKY;
			}

			update_option( self::DESTINATION_OPTION, $destination, false );
			self::redirect_to_step( 'appearance' );
		}

		if ( 'appearance' === $step ) {
			$mode = sanitize_text_field( wp_unslash( $_POST['activitypub_actor_mode'] ?? '' ) );
			if ( in_array( $mode, self::ACTOR_MODES, true ) ) {
				update_option( 'activitypub_actor_mode', $mode );
			}

			// Persist the inline Site Handle when submitted. Only write when
			// the field arrived non-empty so the no-touch path preserves any
			// existing stored value (matches AP_Provider::handle_save). AP's
			// `sanitize_option_activitypub_blog_identifier` filter handles
			// collision rejection at update_option time.
			$blog_identifier_rejected = false;
			if ( array_key_exists( 'activitypub_blog_identifier', $_POST ) ) {
				$raw_input = is_string( $_POST['activitypub_blog_identifier'] )
					? sanitize_text_field( wp_unslash( $_POST['activitypub_blog_identifier'] ) )
					: '';
				$raw       = trim( $raw_input );
				if ( '' !== $raw ) {
					// Snapshot the queue length, not the codes — AP's sanitizer
					// reuses a constant code (`activitypub_blog_identifier`) for
					// every collision rejection, so a code-only check would mask
					// a fresh rejection if any error with that code already sat
					// on the queue. Mirrors AP_Provider::handle_save().
					$ap_error_count_before = count( get_settings_errors( 'activitypub_blog_identifier' ) );

					update_option( 'activitypub_blog_identifier', $raw );

					// Re-tag any fresh AP errors under our own group so the
					// appearance step's `settings_errors( 'fosse' )` render
					// surfaces them — without re-tagging the user would land
					// back on the wizard with no feedback at all.
					$ap_errors_after = get_settings_errors( 'activitypub_blog_identifier' );
					$new_ap_errors   = array_slice( $ap_errors_after, $ap_error_count_before );
					foreach ( $new_ap_errors as $ap_error ) {
						$blog_identifier_rejected = true;
						add_settings_error(
							'fosse',
							$ap_error['code'],
							$ap_error['message'],
							$ap_error['type']
						);
					}
				}
			}

			// On rejection, persist the surfaced errors via the
			// `settings_errors` transient and bounce back to the appearance
			// step so the user can correct the input. Without this the wizard
			// would silently advance to Content with no feedback and no way
			// to fix the colliding handle.
			if ( $blog_identifier_rejected ) {
				set_transient( 'settings_errors', get_settings_errors(), 30 );
				self::redirect_to_step( 'appearance', array( 'settings-updated' => 'true' ) );
			}

			self::redirect_to_step( 'content' );
		}

		if ( 'content' === $step ) {
			$submitted   = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['activitypub_support_post_types'] ?? array() ) ) );
			$valid_types = get_post_types( array( 'public' => true ) );
			$post_types  = array_values( array_intersect( $submitted, $valid_types ) );

			// Empty selection would silently disable federation. Bounce back
			// with an error rather than overwrite the option with [].
			if ( empty( $post_types ) ) {
				self::redirect_to_step( 'content', array( 'error' => 'empty_post_types' ) );
			}

			update_option( 'activitypub_support_post_types', $post_types );
			if ( self::destination_includes_bluesky() ) {
				self::redirect_to_step( 'bluesky' );
			}

			self::mark_complete();
			self::redirect_to_step( 'complete' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Fallback: redirect to next logical step.
		self::redirect_to_step( 'destinations' );
	}

	/**
	 * Handle the "Skip setup" action.
	 *
	 * @return void
	 */
	public static function handle_skip(): void {
		self::require_capability(
			'fosse_wizard_skip',
			__( 'You do not have permission to skip the wizard.', 'fosse' )
		);
		self::require_nonce( 'fosse_wizard_skip', 'fosse_wizard_skip' );

		self::mark_complete();

		wp_safe_redirect( admin_url( 'admin.php?page=fosse' ) );
		exit;
	}

	/**
	 * Handle wizard completion.
	 *
	 * Marks the wizard as complete via a nonced `admin-post.php` request
	 * (reached from a `wp_nonce_url()` link), then redirects to the
	 * completion view. Capability + nonce verification ensure completion
	 * requires explicit user intent and cannot be triggered via CSRF.
	 *
	 * @return void
	 */
	public static function handle_complete(): void {
		self::require_capability(
			'fosse_wizard_complete',
			__( 'You do not have permission to complete the wizard.', 'fosse' )
		);
		self::require_nonce( 'fosse_wizard_complete', 'fosse_wizard_complete' );

		self::mark_complete();

		wp_safe_redirect( admin_url( 'admin.php?page=fosse-wizard&step=complete' ) );
		exit;
	}

	/**
	 * Reset the wizard so it can be run again.
	 *
	 * @return void
	 */
	public static function handle_reset(): void {
		self::require_capability(
			'fosse_wizard_reset',
			__( 'You do not have permission to reset the wizard.', 'fosse' )
		);
		self::require_nonce( 'fosse_wizard_reset', 'fosse_wizard_reset' );

		delete_option( self::COMPLETED_OPTION );
		delete_option( self::DESTINATION_OPTION );

		wp_safe_redirect( admin_url( 'admin.php?page=fosse-wizard' ) );
		exit;
	}

	/**
	 * Enforce manage_options or fail loudly.
	 *
	 * Fires the `fosse_wizard_unauthorized` action so site owners can audit
	 * unauthorized wizard requests before the request is killed.
	 *
	 * @param string $action  Wizard action being attempted (e.g. `fosse_wizard_save`).
	 * @param string $message Message shown via `wp_die()` on failure.
	 * @return void
	 */
	private static function require_capability( string $action, string $message ): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		/**
		 * Fires before the wizard kills an unauthorized request.
		 *
		 * @param string $action  Wizard action that was attempted.
		 * @param int    $user_id Current user ID (0 for logged-out).
		 * @param string $reason  Why the request was rejected (`capability` or `nonce`).
		 */
		do_action( 'fosse_wizard_unauthorized', $action, get_current_user_id(), 'capability' );

		wp_die(
			esc_html( $message ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Verify the request nonce or fail loudly.
	 *
	 * Replaces direct `check_admin_referer()` calls so the audit hook fires
	 * before the request is killed.
	 *
	 * @param string $action       Wizard action being attempted (e.g. `fosse_wizard_save`).
	 * @param string $nonce_action Nonce action name (e.g. `fosse_wizard`).
	 * @return void
	 */
	private static function require_nonce( string $action, string $nonce_action ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this IS the nonce verification.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( wp_verify_nonce( $nonce, $nonce_action ) ) {
			return;
		}

		/** This action is documented in src/Admin/class-onboarding-wizard.php */
		do_action( 'fosse_wizard_unauthorized', $action, get_current_user_id(), 'nonce' );

		wp_die(
			esc_html__( 'The link you followed has expired. Please try again.', 'fosse' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Get the current step from the query string, validated against known steps.
	 *
	 * @return string
	 */
	private static function get_current_step(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation, no state change.
		$step = sanitize_text_field( wp_unslash( $_GET['step'] ?? 'destinations' ) );

		if ( 'welcome' === $step ) {
			return 'destinations';
		}

		if ( in_array( $step, self::STEPS, true ) ) {
			return $step;
		}

		return 'destinations';
	}

	/**
	 * Get the saved destination intent, falling back to the recommended path.
	 *
	 * @return string
	 */
	private static function get_destination(): string {
		$destination = (string) get_option( self::DESTINATION_OPTION, self::DESTINATION_FEDIVERSE_BLUESKY );

		return in_array( $destination, self::DESTINATIONS, true )
			? $destination
			: self::DESTINATION_FEDIVERSE_BLUESKY;
	}

	/**
	 * Whether the saved destination intent includes Bluesky setup.
	 *
	 * @return bool
	 */
	private static function destination_includes_bluesky(): bool {
		return self::DESTINATION_FEDIVERSE_BLUESKY === self::get_destination();
	}

	/**
	 * Human label for the saved destination intent.
	 *
	 * @param string $destination Destination value.
	 * @return string
	 */
	private static function get_destination_label( string $destination ): string {
		return self::DESTINATION_FEDIVERSE_ONLY === $destination
			? __( 'Fediverse only', 'fosse' )
			: __( 'Fediverse + Bluesky', 'fosse' );
	}

	/**
	 * Redirect to a specific wizard step.
	 *
	 * @param string                $step       Step slug.
	 * @param array<string, string> $extra_args Optional extra query args (e.g. an error code).
	 * @return void
	 */
	private static function redirect_to_step( string $step, array $extra_args = array() ): void {
		$args = array_merge(
			array(
				'page' => 'fosse-wizard',
				'step' => $step,
			),
			$extra_args
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Get the URL for the skip-setup action.
	 *
	 * @return string
	 */
	private static function get_skip_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=fosse_wizard_skip' ),
			'fosse_wizard_skip'
		);
	}

	/**
	 * Build fediverse handle previews for the selected actor mode.
	 *
	 * Defers to the ActivityPub provider's helpers so the preview matches
	 * the webfinger that Mastodon-style clients will actually resolve
	 * (blog `preferred_username@host`, user nicename, etc.). Returns an
	 * associative array keyed by `'user'` and `'blog'`, with each value
	 * either a normalized `@user@host` string or an empty string when
	 * the actor can't be resolved or the upstream value is malformed.
	 * Modes that do not surface a given identity simply omit it (e.g.
	 * `actor` mode never returns a `'blog'` entry).
	 *
	 * @param string $mode Selected actor mode (`actor`, `blog`, `actor_blog`).
	 * @return array{user?: string, blog?: string}
	 */
	private static function get_handle_previews( string $mode ): array {
		$provider = Connection_Provider_Registry::get_provider( 'activitypub' );
		if ( ! $provider instanceof AP_Provider ) {
			return array();
		}

		$previews = array();

		if ( $provider->mode_includes_user( $mode ) ) {
			$previews['user'] = self::normalize_handle_preview( $provider->get_user_address() );
		}

		if ( $provider->mode_includes_blog( $mode ) ) {
			$previews['blog'] = self::normalize_handle_preview( $provider->get_blog_address() );
		}

		return $previews;
	}

	/**
	 * Format the "Site appears as" summary label for the completion step.
	 *
	 * Embeds the resolved fediverse handle(s) so users see the actual
	 * identity they just stood up rather than just the bare host. Falls
	 * back gracefully when a handle is missing (AP not loaded, user
	 * actor unavailable) — never shows an `@` with no local-part.
	 *
	 * Returns an HTML string; the consumer escapes via `wp_kses` with
	 * `code` and `br` allowed.
	 *
	 * @param string $mode        Actor mode value.
	 * @param string $user_handle Normalized `@user@host` for the current user, or empty.
	 * @param string $blog_handle Normalized `@blog@host` for the site, or empty.
	 * @return string
	 */
	private static function format_mode_label( string $mode, string $user_handle, string $blog_handle ): string {
		switch ( $mode ) {
			case 'actor':
				if ( '' !== $user_handle ) {
					return esc_html__( 'As you', 'fosse' )
						. '<br /><code>' . esc_html( $user_handle ) . '</code>';
				}
				return esc_html__( 'As you (author profiles)', 'fosse' );

			case 'blog':
				if ( '' !== $blog_handle ) {
					return esc_html__( 'As your site', 'fosse' )
						. '<br /><code>' . esc_html( $blog_handle ) . '</code>';
				}
				$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
				return esc_html__( 'As your site', 'fosse' )
					. '<br /><code>' . esc_html( $site_host ? $site_host : 'yoursite.com' ) . '</code>';

			case 'actor_blog':
				$lines = array( esc_html__( 'Both (site + authors)', 'fosse' ) );
				if ( '' !== $user_handle ) {
					$lines[] = esc_html__( 'As you:', 'fosse' )
						. '<br /><code>' . esc_html( $user_handle ) . '</code>';
				}
				if ( '' !== $blog_handle ) {
					$lines[] = esc_html__( 'As your site:', 'fosse' )
						. '<br /><code>' . esc_html( $blog_handle ) . '</code>';
				}
				return implode( '<br />', $lines );
		}

		return esc_html( $mode );
	}

	/**
	 * Normalize a fediverse handle for preview display.
	 *
	 * Accepts the upstream `user@host` shape (with or without a leading
	 * `@`) and returns `@user@host`. Returns an empty string for any
	 * input that lacks a non-empty local-part and domain — e.g. `@host`,
	 * `user@`, plain `host`, or empty input — so the caller can hide
	 * the preview row instead of rendering a synthetic placeholder.
	 *
	 * AP models return `user@host`; FOSSE renderers prepend `@`. Kept
	 * inline rather than shared with `AP_Provider::get_fediverse_address()`
	 * to avoid changing the AP provider's output shape (which would risk
	 * `@@user@host` at downstream call sites).
	 *
	 * @param string $handle Raw handle, e.g. `user@example.com` or `@user@example.com`.
	 * @return string Normalized `@user@host`, or empty string if invalid.
	 */
	private static function normalize_handle_preview( string $handle ): string {
		$trimmed = ltrim( $handle, '@' );
		if ( '' === $trimmed ) {
			return '';
		}

		$parts = explode( '@', $trimmed );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		[ $local, $domain ] = $parts;
		if ( '' === $local || '' === $domain ) {
			return '';
		}

		return '@' . $local . '@' . $domain;
	}

	/**
	 * Resolve the completion-step "Publish your first ..." CTA.
	 *
	 * Deep-links the new-post screen at the post type the user actually
	 * federates, so a wizard run that selected only `page` (or a custom
	 * type) doesn't drop the user at the default `post` editor where
	 * their content wouldn't reach the social web. Prefers `post` when
	 * it's in the selection — most users think of `post` as the default
	 * — and falls back to the first valid public type otherwise.
	 *
	 * Empty / fully-invalid input degrades to a "Set up sharing" CTA
	 * that routes back to the Setup page, instead of pretending to
	 * deep-link a federated editor that won't actually federate (the
	 * wizard's content step blocks empty submissions, but the option
	 * can be cleared later via AP's settings page).
	 *
	 * The label embeds the post type's `singular_name` as-is so the
	 * locale's preferred casing wins. Forcing lowercase would break
	 * locales like German where nouns are always capitalized.
	 *
	 * @param array<int, string> $post_types Federated post types from
	 *                                       `activitypub_support_post_types`.
	 * @return array{url: string, label: string}
	 */
	private static function resolve_publish_cta( array $post_types ): array {
		// Require `public` so the CTA can't deep-link an internal type
		// (revisions, nav menu items, etc.) — federation is meaningless
		// there and the editor wouldn't be reachable anyway. Matches the
		// constraint the wizard's content step applies when saving.
		$valid_types = array_values(
			array_filter(
				$post_types,
				static function ( $type ) {
					if ( ! is_string( $type ) ) {
						return false;
					}
					$obj = get_post_type_object( $type );
					return $obj && ! empty( $obj->public );
				}
			)
		);

		if ( empty( $valid_types ) ) {
			return array(
				'url'   => admin_url( 'admin.php?page=fosse' ),
				'label' => __( 'Set up sharing', 'fosse' ),
			);
		}

		$selected = in_array( 'post', $valid_types, true ) ? 'post' : $valid_types[0];

		$url = 'post' === $selected
			? admin_url( 'post-new.php' )
			: add_query_arg( 'post_type', $selected, admin_url( 'post-new.php' ) );

		$pt_object = get_post_type_object( $selected );
		$singular  = $pt_object && isset( $pt_object->labels->singular_name )
			? (string) $pt_object->labels->singular_name
			: __( 'post', 'fosse' );

		$label = sprintf(
			/* translators: %s: post type singular name (e.g. "Post", "Page"). */
			__( 'Publish your first %s', 'fosse' ),
			$singular
		);

		return array(
			'url'   => $url,
			'label' => $label,
		);
	}

	/**
	 * Read Bluesky connection status from the registered provider.
	 *
	 * Reads strictly through the registry. If the provider isn't registered
	 * (registration hook never fired, third-party removed it, etc.) the
	 * wizard renders the unavailable notice rather than instantiating a
	 * provider whose connect form would post to an unhooked admin-post
	 * action and silently 404.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_bluesky_status(): array {
		$provider = Connection_Provider_Registry::get_provider( 'bluesky' );

		$defaults = array(
			'available'    => false,
			'connected'    => false,
			'handle'       => '',
			'did'          => '',
			'pds_endpoint' => '',
			'auto_publish' => false,
			'token_error'  => null,
		);

		if ( null === $provider || ! $provider->is_available() ) {
			return $defaults;
		}

		return array_merge(
			$defaults,
			$provider->get_status(),
			array( 'available' => true )
		);
	}

	/**
	 * Render the progress indicator.
	 *
	 * @param string $current_step Current step slug.
	 * @return void
	 */
	private static function render_progress( string $current_step ): void {
		$labels    = array(
			'destinations' => __( 'Destinations', 'fosse' ),
			'appearance'   => __( 'Identity', 'fosse' ),
			'content'      => __( 'Content', 'fosse' ),
			'bluesky'      => __( 'Bluesky', 'fosse' ),
			'complete'     => __( 'Review', 'fosse' ),
		);
		$step_keys = array_keys( $labels );
		if ( ! self::destination_includes_bluesky() ) {
			$step_keys = array_values(
				array_filter(
					$step_keys,
					static function ( string $step ): bool {
						return 'bluesky' !== $step;
					}
				)
			);
		}

		$current_i = array_search( $current_step, $step_keys, true );

		if ( false === $current_i ) {
			return;
		}

		?>
		<ol class="fosse-wizard__progress" aria-label="<?php esc_attr_e( 'Setup progress', 'fosse' ); ?>">
			<?php foreach ( $step_keys as $i => $key ) : ?>
				<?php
				$is_complete = $i < $current_i;
				$is_active   = $i === $current_i;
				$classes     = 'fosse-wizard__progress-step';
				if ( $is_complete ) {
					$classes .= ' is-complete';
				}
				if ( $is_active ) {
					$classes .= ' is-active';
				}
				?>
				<?php if ( $i > 0 ) : ?>
					<li class="fosse-wizard__progress-line<?php echo $is_complete ? ' is-complete' : ''; ?>" aria-hidden="true"></li>
				<?php endif; ?>
				<li class="<?php echo esc_attr( $classes ); ?>"<?php echo $is_active ? ' aria-current="step"' : ''; ?>>
					<span class="fosse-wizard__progress-dot" aria-hidden="true"></span>
					<?php echo esc_html( $labels[ $key ] ); ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Render Step 1: Destinations.
	 *
	 * @return void
	 */
	private static function render_step_destinations(): void {
		self::render_progress( 'destinations' );

		$current_destination = self::get_destination();
		$nonce               = wp_create_nonce( 'fosse_wizard' );

		$destinations = array(
			self::DESTINATION_FEDIVERSE_BLUESKY => array(
				'badge' => __( 'Recommended', 'fosse' ),
				'title' => __( 'Fediverse + Bluesky', 'fosse' ),
				'desc'  => __( 'Let people follow your site from Mastodon-compatible apps and publish eligible posts to Bluesky.', 'fosse' ),
			),
			self::DESTINATION_FEDIVERSE_ONLY    => array(
				'badge' => __( 'Simple setup', 'fosse' ),
				'title' => __( 'Fediverse only', 'fosse' ),
				'desc'  => __( 'Let people follow your site from Mastodon-compatible apps without setting up Bluesky in this wizard.', 'fosse' ),
			),
		);
		?>
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'Where should your WordPress posts appear?', 'fosse' ); ?></h1>
		<p class="fosse-wizard__description">
			<?php esc_html_e( 'Choose where FOSSE should share your posts. You can connect Bluesky or change these settings later in FOSSE Settings.', 'fosse' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fosse_wizard_save" />
			<input type="hidden" name="fosse_wizard_step" value="destinations" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<div class="fosse-wizard__card">
				<div class="fosse-destination-cards">
					<?php foreach ( $destinations as $value => $destination ) : ?>
						<label class="fosse-destination-card">
							<input
								type="radio"
								name="fosse_onboarding_destination"
								value="<?php echo esc_attr( $value ); ?>"
								class="fosse-destination-card__input"
								<?php checked( $value, $current_destination ); ?>
							/>
							<span class="fosse-destination-card__badge"><?php echo esc_html( $destination['badge'] ); ?></span>
							<span class="fosse-destination-card__title"><?php echo esc_html( $destination['title'] ); ?></span>
							<span class="fosse-destination-card__desc"><?php echo esc_html( $destination['desc'] ); ?></span>
							<span class="fosse-destination-card__check">
								<span class="dashicons dashicons-yes-alt"></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="fosse-wizard__actions fosse-wizard__actions--center">
				<div class="fosse-wizard__actions-column">
					<?php submit_button( __( 'Continue', 'fosse' ), 'primary large', 'submit', false ); ?>
					<a href="<?php echo esc_url( self::get_skip_url() ); ?>" class="fosse-wizard__skip">
						<?php esc_html_e( 'Skip setup', 'fosse' ); ?>
					</a>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Render Step 2: Appearance (actor mode).
	 *
	 * @return void
	 */
	private static function render_step_appearance(): void {
		self::render_progress( 'appearance' );

		$current_mode = get_option( 'activitypub_actor_mode', 'actor' );
		$site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$nonce        = wp_create_nonce( 'fosse_wizard' );

		$modes = array(
			'actor'      => array(
				'icon'  => 'dashicons-admin-users',
				'title' => __( 'As you', 'fosse' ),
				'desc'  => sprintf(
					/* translators: 1: opening <strong> tag, 2: closing </strong> tag */
					__( 'People follow %1$syou%2$s personally. Posts appear under your author name. Best for personal sites.', 'fosse' ),
					'<strong>',
					'</strong>'
				),
			),
			'blog'       => array(
				'icon'  => 'dashicons-admin-site',
				'title' => __( 'As your site', 'fosse' ),
				'desc'  => $site_host
					? sprintf(
						/* translators: 1: opening <strong> tag, 2: closing </strong> tag, 3: site domain. */
						__( 'People follow %1$s%3$s%2$s. All posts appear from your site\'s name. Best for blogs and publications.', 'fosse' ),
						'<strong>',
						'</strong>',
						esc_html( $site_host )
					)
					: __( 'People follow your site. All posts appear from your site\'s name. Best for blogs and publications.', 'fosse' ),
			),
			'actor_blog' => array(
				'icon'  => 'dashicons-groups',
				'title' => __( 'Both', 'fosse' ),
				'desc'  => sprintf(
					/* translators: 1: opening <strong> tag, 2: closing </strong> tag */
					__( 'People can follow your site %1$sor%2$s individual authors separately. Best for multi-author sites.', 'fosse' ),
					'<strong>',
					'</strong>'
				),
			),
		);

		// Resolve handles for every mode up front so all three preview
		// containers can be rendered server-side. JS then toggles which is
		// visible on radio change; the no-JS fallback keeps only the saved
		// mode's container visible (matches the pre-#68 behavior).
		$ap_provider = Connection_Provider_Registry::get_provider( 'activitypub' );
		$user_handle = $ap_provider instanceof AP_Provider
			? self::normalize_handle_preview( $ap_provider->get_user_address() )
			: '';
		$blog_handle = $ap_provider instanceof AP_Provider
			? self::normalize_handle_preview( $ap_provider->get_blog_address() )
			: '';

		$blog_identifier             = (string) get_option( 'activitypub_blog_identifier', '' );
		$blog_identifier_placeholder = '';
		if ( '' === $blog_identifier && class_exists( '\Activitypub\Model\Blog' ) ) {
			$blog_identifier_placeholder = (string) \Activitypub\Model\Blog::get_default_username();
		}

		?>
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'Who should people follow?', 'fosse' ); ?></h1>
		<p class="fosse-wizard__description">
			<?php esc_html_e( 'Choose the identity people follow from social apps. This affects who appears as the publisher when your selected content is shared.', 'fosse' ); ?>
		</p>

		<?php settings_errors( 'fosse' ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fosse_wizard_save" />
			<input type="hidden" name="fosse_wizard_step" value="appearance" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<div class="fosse-wizard__card">
				<div class="fosse-mode-cards">
					<?php foreach ( $modes as $value => $mode ) : ?>
						<label class="fosse-mode-card">
							<input
								type="radio"
								name="activitypub_actor_mode"
								value="<?php echo esc_attr( $value ); ?>"
								class="fosse-mode-card__input"
								<?php checked( $value, $current_mode ); ?>
							/>
							<div class="fosse-mode-card__icon">
								<span class="dashicons <?php echo esc_attr( $mode['icon'] ); ?>"></span>
							</div>
							<div class="fosse-mode-card__content">
								<div class="fosse-mode-card__title"><?php echo esc_html( $mode['title'] ); ?></div>
								<div class="fosse-mode-card__desc"><?php echo wp_kses( $mode['desc'], array( 'strong' => array() ) ); ?></div>
							</div>
							<div class="fosse-mode-card__check">
								<span class="dashicons dashicons-yes-alt"></span>
							</div>
						</label>
					<?php endforeach; ?>
				</div>

				<?php
				// Render preview containers for every mode that has content
				// to show. Skip empty modes entirely so the active container
				// never renders as an empty styled grey box (`get_user_address`
				// can legitimately return '' when the current user can't have
				// an actor; the same applies to the blog handle when AP isn't
				// fully configured). Inactive containers carry `is-hidden`; a
				// small JS helper swaps that class on radio change. With JS
				// off the page still surfaces the active mode's preview.
				$preview_modes       = array( 'actor', 'blog', 'actor_blog' );
				$preview_has_content = array(
					'actor'      => '' !== $user_handle,
					'blog'       => '' !== $blog_handle,
					'actor_blog' => '' !== $user_handle || '' !== $blog_handle,
				);
				foreach ( $preview_modes as $preview_mode ) :
					if ( ! $preview_has_content[ $preview_mode ] ) {
						continue;
					}
					$preview_classes = 'fosse-address-preview';
					if ( $preview_mode !== $current_mode ) {
						$preview_classes .= ' is-hidden';
					}
					?>
					<div class="<?php echo esc_attr( $preview_classes ); ?>" data-fosse-mode="<?php echo esc_attr( $preview_mode ); ?>">
						<?php if ( 'actor_blog' === $preview_mode ) : ?>
							<?php if ( '' !== $user_handle ) : ?>
								<div class="fosse-address-preview__row">
									<span class="fosse-address-preview__label"><?php esc_html_e( 'As you:', 'fosse' ); ?></span>
									<code class="fosse-address-preview__address"><?php echo esc_html( $user_handle ); ?></code>
								</div>
							<?php endif; ?>
							<?php if ( '' !== $blog_handle ) : ?>
								<div class="fosse-address-preview__row">
									<span class="fosse-address-preview__label"><?php esc_html_e( 'As your site:', 'fosse' ); ?></span>
									<code class="fosse-address-preview__address"><?php echo esc_html( $blog_handle ); ?></code>
								</div>
							<?php endif; ?>
						<?php elseif ( 'actor' === $preview_mode && '' !== $user_handle ) : ?>
							<span class="fosse-address-preview__label"><?php esc_html_e( 'Your fediverse address:', 'fosse' ); ?></span>
							<code class="fosse-address-preview__address"><?php echo esc_html( $user_handle ); ?></code>
						<?php elseif ( 'blog' === $preview_mode && '' !== $blog_handle ) : ?>
							<span class="fosse-address-preview__label"><?php esc_html_e( 'Site fediverse address:', 'fosse' ); ?></span>
							<code class="fosse-address-preview__address"><?php echo esc_html( $blog_handle ); ?></code>
						<?php endif; ?>
					</div>
					<?php
				endforeach;

				$blog_handle_classes = 'fosse-wizard__blog-handle';
				if ( 'blog' !== $current_mode && 'actor_blog' !== $current_mode ) {
					$blog_handle_classes .= ' is-hidden';
				}
				?>
				<div class="<?php echo esc_attr( $blog_handle_classes ); ?>" data-fosse-when="includes-blog">
					<label for="fosse-wizard-blog-identifier" class="fosse-wizard__blog-handle-label">
						<?php esc_html_e( 'Site Handle', 'fosse' ); ?>
					</label>
					<input
						type="text"
						id="fosse-wizard-blog-identifier"
						name="activitypub_blog_identifier"
						class="regular-text"
						value="<?php echo esc_attr( $blog_identifier ); ?>"
						placeholder="<?php echo esc_attr( $blog_identifier_placeholder ); ?>"
						aria-describedby="fosse-wizard-blog-identifier-desc"
					/>
					<p id="fosse-wizard-blog-identifier-desc" class="description">
						<?php esc_html_e( 'The username people use to follow your site from the fediverse. Cannot match an existing author login or nicename.', 'fosse' ); ?>
					</p>
				</div>
			</div>

			<div class="fosse-wizard__actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard&step=destinations' ) ); ?>" class="button">
					&larr; <?php esc_html_e( 'Back', 'fosse' ); ?>
				</a>
				<div class="fosse-wizard__actions-primary">
					<a href="<?php echo esc_url( self::get_skip_url() ); ?>" class="fosse-wizard__skip">
						<?php esc_html_e( 'Skip setup', 'fosse' ); ?>
					</a>
					<?php submit_button( __( 'Continue', 'fosse' ), 'primary', 'submit', false ); ?>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Render Step 3: Content (post types).
	 *
	 * @return void
	 */
	private static function render_step_content(): void {
		self::render_progress( 'content' );

		$post_types     = get_option( 'activitypub_support_post_types', array( 'post' ) );
		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$nonce          = wp_create_nonce( 'fosse_wizard' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check on a redirect-back error code.
		$has_empty_error = isset( $_GET['error'] ) && 'empty_post_types' === $_GET['error'];

		?>
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'What do you want to share?', 'fosse' ); ?></h1>
		<p class="fosse-wizard__description">
			<?php esc_html_e( 'Choose which types of content appear in people\'s feeds when they follow you. You can change this anytime.', 'fosse' ); ?>
		</p>

		<?php if ( $has_empty_error ) : ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'Pick at least one content type so federated followers have something to receive.', 'fosse' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fosse_wizard_save" />
			<input type="hidden" name="fosse_wizard_step" value="content" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<div class="fosse-wizard__card">
				<div class="fosse-post-types">
					<?php
					$primary_order = array( 'post', 'page' );
					$primary_types = array();
					$other_types   = $all_post_types;
					foreach ( $primary_order as $type_name ) {
						if ( isset( $all_post_types[ $type_name ] ) ) {
							$primary_types[ $type_name ] = $all_post_types[ $type_name ];
							unset( $other_types[ $type_name ] );
						}
					}

					$groups = array(
						'primary' => array(
							'label' => __( 'Common content types', 'fosse' ),
							'types' => $primary_types,
						),
						'other'   => array(
							'label' => __( 'Other content types', 'fosse' ),
							'types' => $other_types,
						),
					);
					foreach ( $groups as $group ) :
						if ( empty( $group['types'] ) ) {
							continue;
						}
						?>
						<div class="fosse-post-types__group">
							<div class="fosse-post-types__group-label"><?php echo esc_html( $group['label'] ); ?></div>
							<?php foreach ( $group['types'] as $pt ) : ?>
								<label class="fosse-post-type-item">
									<input
										type="checkbox"
										name="activitypub_support_post_types[]"
										value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, $post_types, true ) ); ?>
									/>
									<span class="fosse-post-type-item__label">
										<?php echo esc_html( $pt->label ); ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="fosse-wizard__hint">
					<p><?php esc_html_e( 'Only future posts will be shared. Existing content won\'t be sent to anyone\'s feed retroactively.', 'fosse' ); ?></p>
				</div>
			</div>

			<div class="fosse-wizard__actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard&step=appearance' ) ); ?>" class="button">
					&larr; <?php esc_html_e( 'Back', 'fosse' ); ?>
				</a>
				<div class="fosse-wizard__actions-primary">
					<a href="<?php echo esc_url( self::get_skip_url() ); ?>" class="fosse-wizard__skip">
						<?php esc_html_e( 'Skip setup', 'fosse' ); ?>
					</a>
					<?php submit_button( __( 'Continue', 'fosse' ), 'primary', 'submit', false ); ?>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Render Step 4: Bluesky.
	 *
	 * Three states drive the rendered markup:
	 *  - Unavailable (provider not registered or not is_available): info notice.
	 *  - Connected: confirmation summary including the resolved fediverse identity.
	 *  - Disconnected: OAuth-start form posting to admin-post.php, with a sign-up affordance.
	 *
	 * @return void
	 */
	private static function render_step_bluesky(): void {
		if ( ! self::destination_includes_bluesky() ) {
			self::redirect_to_step( 'content' );
			return;
		}

		self::render_progress( 'bluesky' );
		$status = self::get_bluesky_status();

		// When the OAuth handoff has already completed, the user is on the
		// post-connect view. Suppress the "you can connect later" copy so it
		// doesn't contradict the success state they're looking at.
		$is_connected = (bool) $status['connected'];

		$title = $is_connected
			? __( 'Bluesky is connected', 'fosse' )
			: __( 'Connect to Bluesky', 'fosse' );

		$description = $is_connected
			? __( 'Your posts will also appear on Bluesky. Review the details below before finishing setup.', 'fosse' )
			: __( 'Link your Bluesky account so your posts also appear on Bluesky. This step is optional. You can always connect later from the FOSSE Settings page.', 'fosse' );
		?>
		<h1 class="fosse-wizard__title"><?php echo esc_html( $title ); ?></h1>
		<p class="fosse-wizard__description">
			<?php echo esc_html( $description ); ?>
		</p>

		<?php
		// Atmosphere posts a settings_error after every OAuth round-trip — a
		// "Successfully connected" success notice on the happy path, and an
		// error notice on failure. The wizard's in-card connected state
		// already speaks for the success case, so rendering the top success
		// notice would double-up the confirmation. Surface only error/warning
		// here so a failed connect doesn't go silent on the Bluesky step.
		foreach ( get_settings_errors( 'atmosphere' ) as $atmosphere_notice ) {
			$notice_type = isset( $atmosphere_notice['type'] ) ? (string) $atmosphere_notice['type'] : 'error';
			if ( in_array( $notice_type, array( 'success', 'updated', 'info' ), true ) ) {
				continue;
			}

			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				esc_attr( $notice_type ),
				esc_html( isset( $atmosphere_notice['message'] ) ? (string) $atmosphere_notice['message'] : '' )
			);
		}
		?>

		<div class="fosse-wizard__card">
			<?php if ( ! $status['available'] ) : ?>
				<div class="notice notice-info inline fosse-wizard__notice">
					<p>
						<strong><?php esc_html_e( 'Bluesky setup is unavailable.', 'fosse' ); ?></strong>
						<?php esc_html_e( 'Skip this step for now and connect from the FOSSE Settings page once Bluesky support is available.', 'fosse' ); ?>
					</p>
				</div>
			<?php elseif ( $is_connected ) : ?>
				<?php $handle_previews = self::get_handle_previews( get_option( 'activitypub_actor_mode', 'actor' ) ); ?>
				<div class="notice notice-success inline fosse-wizard__notice">
					<p>
						<strong><?php esc_html_e( 'Bluesky is connected.', 'fosse' ); ?></strong>
						<?php esc_html_e( 'FOSSE can publish eligible posts to this account.', 'fosse' ); ?>
					</p>
				</div>

				<table class="fosse-summary">
					<?php if ( $status['handle'] ) : ?>
						<tr>
							<td class="fosse-summary__label"><?php esc_html_e( 'Handle', 'fosse' ); ?></td>
							<td class="fosse-summary__value"><?php echo esc_html( $status['handle'] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( $status['did'] ) : ?>
						<tr>
							<td class="fosse-summary__label"><?php esc_html_e( 'DID', 'fosse' ); ?></td>
							<td class="fosse-summary__value"><code><?php echo esc_html( $status['did'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<td class="fosse-summary__label"><?php esc_html_e( 'Auto Publish', 'fosse' ); ?></td>
						<td class="fosse-summary__value"><?php echo esc_html( $status['auto_publish'] ? __( 'Enabled', 'fosse' ) : __( 'Disabled', 'fosse' ) ); ?></td>
					</tr>
					<?php if ( ! empty( $handle_previews['user'] ) ) : ?>
						<tr>
							<td class="fosse-summary__label"><?php esc_html_e( 'Your fediverse address', 'fosse' ); ?></td>
							<td class="fosse-summary__value"><code><?php echo esc_html( $handle_previews['user'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $handle_previews['blog'] ) ) : ?>
						<tr>
							<td class="fosse-summary__label"><?php esc_html_e( 'Site fediverse address', 'fosse' ); ?></td>
							<td class="fosse-summary__value"><code><?php echo esc_html( $handle_previews['blog'] ); ?></code></td>
						</tr>
					<?php endif; ?>
				</table>
			<?php else : ?>
				<form id="fosse-wizard-bluesky-connect-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fosse_connect_bluesky" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_connect_bluesky' ) ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( Bluesky_Provider::RETURN_CONTEXT_FIELD ); ?>" value="<?php echo esc_attr( Bluesky_Provider::RETURN_CONTEXT_WIZARD ); ?>" />

					<div class="fosse-bluesky-form">
						<label for="fosse-bsky-handle" class="fosse-bluesky-form__label">
							<?php esc_html_e( 'Bluesky or AT Protocol handle', 'fosse' ); ?>
						</label>
						<div class="fosse-bluesky-form__controls">
							<input
								type="text"
								id="fosse-bsky-handle"
								name="bluesky_handle"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'yourname.bsky.social', 'fosse' ); ?>"
								aria-describedby="fosse-bsky-handle-description"
							/>
						</div>
						<p id="fosse-bsky-handle-description" class="description">
							<?php esc_html_e( 'Use your Bluesky handle, or a custom domain handle if you have one.', 'fosse' ); ?>
						</p>
					</div>
				</form>

				<div class="fosse-wizard__hint fosse-bluesky-signup">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: opening Bluesky signup anchor tag, 2: closing anchor tag, 3: opening domain-handle help anchor tag, 4: closing anchor tag. */
								__( 'Need help getting started? %1$sCreate a Bluesky account%2$s, or %3$slearn how to use your domain as your handle%4$s.', 'fosse' ),
								'<a href="' . esc_url( 'https://bsky.app/' ) . '" target="_blank" rel="noopener noreferrer" class="fosse-bluesky-signup__link">',
								'</a>',
								'<a href="' . esc_url( 'https://bsky.social/about/blog/4-28-2023-domain-handle-tutorial' ) . '" target="_blank" rel="noopener noreferrer">',
								'</a>'
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<?php $complete_url = wp_nonce_url( admin_url( 'admin-post.php?action=fosse_wizard_complete' ), 'fosse_wizard_complete' ); ?>
		<div class="fosse-wizard__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard&step=content' ) ); ?>" class="button">
				&larr; <?php esc_html_e( 'Back', 'fosse' ); ?>
			</a>
			<div class="fosse-wizard__actions-primary">
				<?php if ( $status['available'] && ! $is_connected ) : ?>
					<a href="<?php echo esc_url( $complete_url ); ?>" class="button">
						<?php esc_html_e( 'Skip Bluesky for now', 'fosse' ); ?>
					</a>
					<button type="submit" form="fosse-wizard-bluesky-connect-form" class="button button-primary">
						<?php esc_html_e( 'Connect Bluesky', 'fosse' ); ?>
					</button>
				<?php else : ?>
					<a href="<?php echo esc_url( $complete_url ); ?>" class="button button-primary">
						<?php echo esc_html( $is_connected ? __( 'Finish setup', 'fosse' ) : __( 'Skip for now', 'fosse' ) ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Step 5: Review.
	 *
	 * @return void
	 */
	private static function render_step_complete(): void {
		// Direct GET to ?step=complete (URL crafting, browser back/forward) would
		// otherwise render the success screen without ever marking the wizard
		// complete via handle_complete(), leaving the Setup notice nagging.
		if ( ! self::is_complete() ) {
			self::redirect_to_step( 'destinations' );
			return;
		}

		self::render_progress( 'complete' );

		$actor_mode        = get_option( 'activitypub_actor_mode', 'actor' );
		$post_types        = get_option( 'activitypub_support_post_types', array( 'post' ) );
		$bluesky           = self::get_bluesky_status();
		$destination       = self::get_destination();
		$includes_bluesky  = self::DESTINATION_FEDIVERSE_BLUESKY === $destination;
		$destination_label = self::get_destination_label( $destination );
		$publishes_bluesky = $bluesky['connected'] && $bluesky['auto_publish'];

		$handles     = self::get_handle_previews( $actor_mode );
		$user_handle = $handles['user'] ?? '';
		$blog_handle = $handles['blog'] ?? '';
		$mode_label  = self::format_mode_label( $actor_mode, $user_handle, $blog_handle );

		$type_labels = array_map(
			static function ( $pt_name ) {
				$pt = get_post_type_object( $pt_name );
				return $pt ? $pt->label : $pt_name;
			},
			$post_types
		);

		if ( $bluesky['connected'] ) {
			$bluesky_summary = $bluesky['handle']
				? sprintf(
					/* translators: %s: Bluesky handle. */
					__( 'Connected as %s', 'fosse' ),
					$bluesky['handle']
				)
				: __( 'Connected', 'fosse' );
		} elseif ( ! $bluesky['available'] && $includes_bluesky ) {
			$bluesky_summary = __( 'Unavailable', 'fosse' );
		} else {
			$bluesky_summary = $includes_bluesky ? __( 'Not connected', 'fosse' ) : __( 'Skipped', 'fosse' );
		}

		?>
		<div class="fosse-wizard__complete-header">
			<div class="fosse-complete-icon">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<h1 class="fosse-wizard__title"><?php esc_html_e( 'You\'re all set!', 'fosse' ); ?></h1>
			<p class="fosse-wizard__description">
				<?php esc_html_e( 'Review your setup, then publish from WordPress when you are ready.', 'fosse' ); ?>
			</p>
		</div>

		<div class="fosse-wizard__card">
			<table class="fosse-summary">
				<tr>
					<td class="fosse-summary__label"><?php esc_html_e( 'Destinations', 'fosse' ); ?></td>
					<td class="fosse-summary__value"><?php echo esc_html( $destination_label ); ?></td>
				</tr>
				<tr>
					<td class="fosse-summary__label"><?php esc_html_e( 'Site appears as', 'fosse' ); ?></td>
					<td class="fosse-summary__value">
						<?php
						echo wp_kses(
							$mode_label,
							array(
								'code' => array(),
								'br'   => array(),
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<td class="fosse-summary__label"><?php esc_html_e( 'Sharing', 'fosse' ); ?></td>
					<td class="fosse-summary__value"><?php echo esc_html( implode( ', ', $type_labels ) ); ?></td>
				</tr>
				<tr>
					<td class="fosse-summary__label"><?php esc_html_e( 'Bluesky', 'fosse' ); ?></td>
					<td class="fosse-summary__value<?php echo $bluesky['connected'] ? '' : ' fosse-summary__value--muted'; ?>"><?php echo esc_html( $bluesky_summary ); ?></td>
				</tr>
			</table>

			<div class="fosse-wizard__hint">
				<p><?php esc_html_e( 'You can change any of these settings from the FOSSE Settings page at any time.', 'fosse' ); ?></p>
			</div>
		</div>

		<?php
		$cta = self::resolve_publish_cta( $post_types );
		if ( $publishes_bluesky ) {
			$cta_help = __( 'Your post will reach followers across Mastodon-compatible apps and Bluesky.', 'fosse' );
		} elseif ( $bluesky['connected'] ) {
			$cta_help = __( 'Your post will reach followers across Mastodon-compatible apps. Bluesky is connected, but automatic publishing is off.', 'fosse' );
		} elseif ( $includes_bluesky ) {
			$cta_help = __( 'Your post will reach followers across Mastodon-compatible apps and Bluesky if connected.', 'fosse' );
		} else {
			$cta_help = __( 'Your post will reach followers across Mastodon-compatible apps.', 'fosse' );
		}
		?>
		<div class="fosse-wizard__actions fosse-wizard__actions--center">
			<a href="<?php echo esc_url( $cta['url'] ); ?>" class="button button-primary button-hero fosse-wizard__cta-publish">
				<?php echo esc_html( $cta['label'] ); ?>
			</a>
		</div>

		<p class="fosse-wizard__cta-help">
			<?php echo esc_html( $cta_help ); ?>
		</p>

		<div class="fosse-wizard__actions fosse-wizard__actions--center fosse-wizard__actions--secondary">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-status' ) ); ?>" class="button">
				<?php esc_html_e( 'View Status Dashboard', 'fosse' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse' ) ); ?>" class="button">
				<?php esc_html_e( 'Go to Settings', 'fosse' ); ?>
			</a>
		</div>

		<p class="fosse-wizard__reset">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fosse_wizard_reset' ), 'fosse_wizard_reset' ) ); ?>">
				<?php esc_html_e( 'Run wizard again', 'fosse' ); ?>
			</a>
		</p>
		<?php
	}
}
