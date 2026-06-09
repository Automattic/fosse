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
 *  3. Bluesky - optional OAuth connection
 *  4. Sharing - post type selection
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
	private const STEPS = array( 'destinations', 'appearance', 'bluesky', 'content', 'complete' );

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
	 * Per-user dedup flag for `fosse_wizard_started` emission.
	 *
	 * Stored as user_meta so the event fires once per user per wizard
	 * lifecycle, not once per page load. Cleared when the wizard is
	 * reset so a reset-then-start cycle re-emits.
	 *
	 * @var string
	 */
	private const USER_META_STARTED_EMITTED = '_fosse_wizard_started_emitted';

	/**
	 * Allowed `entry` property values for `fosse_wizard_started`.
	 *
	 * - `auto`         — post-activation auto-redirect via REDIRECT_OPTION.
	 * - `admin_notice` — link from a FOSSE admin notice.
	 * - `menu`         — direct nav to the FOSSE menu item (default fallback).
	 *
	 * @var string[]
	 */
	private const WIZARD_ENTRIES = array( 'auto', 'admin_notice', 'menu' );

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
		// Current wizard UI completes through the Sharing/content save handler. Keep
		// this endpoint for stale nonced links rendered by earlier wizard
		// versions or an already-open admin page during an upgrade.
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
		<div class="wrap fosse-wizard fosse-admin-shell">
		<?php
		self::render_lizard_toggle();

		if ( ! self::is_activitypub_available() ) {
			self::render_unavailable_notice();
			?>
			</div>
			<?php
			return;
		}

		$step = self::get_render_step( self::get_current_step() );

		// Emit `fosse_wizard_started` the first time a user reaches step 1.
		// Per-user dedup keeps a noisy refresh from inflating the funnel
		// entry count.
		if ( 'destinations' === $step ) {
			self::record_wizard_started();
		}

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
	 * Render the hidden wizard style toggle.
	 *
	 * @return void
	 */
	private static function render_lizard_toggle(): void {
		?>
		<button
			type="button"
			class="fosse-wizard__lizard"
			data-fosse-lizard-toggle
			aria-label="<?php esc_attr_e( 'Toggle wizard theme', 'fosse' ); ?>"
			aria-pressed="false"
		>
			<span aria-hidden="true">&#x1F98E;</span>
		</button>
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
			// existing stored value (matches AP_Provider::save_settings). AP's
			// `sanitize_option_activitypub_blog_identifier` filter handles
			// collision rejection at update_option time.
			$ap_provider              = Connection_Provider_Registry::get_provider( 'activitypub' );
			$blog_identifier_rejected = false;
			// Gate the write on whether the selected mode actually includes the
			// blog actor. The Site Handle input is hidden client-side in
			// author-only mode, but a hidden field still submits its value — so
			// without this server-side gate a stale handle from a mode the user
			// switched away from would be written (and a collision in that
			// invisible field would bounce them back to a field they cannot
			// see). The browser also disables the input when hidden; this gate
			// is the defense-in-depth for clients with JS off or tampered POSTs.
			$mode_includes_blog = $ap_provider instanceof AP_Provider && $ap_provider->mode_includes_blog( $mode );
			if ( $mode_includes_blog && array_key_exists( 'activitypub_blog_identifier', $_POST ) ) {
				$raw_input = is_string( $_POST['activitypub_blog_identifier'] )
					? sanitize_text_field( wp_unslash( $_POST['activitypub_blog_identifier'] ) )
					: '';
				$raw       = trim( $raw_input );
				if ( '' !== $raw ) {
					// Pre-check the collision before writing. AP's sanitizer
					// silently swaps a colliding handle for the default, which
					// `update_option` then PERSISTS over the previously saved
					// handle (breaking existing blog-actor followers). Guard the
					// write so a rejected handle leaves the stored value intact
					// while still surfacing the error. Mirrors
					// AP_Provider::save_settings.
					if ( $ap_provider->blog_identifier_collides( $raw ) ) {
						$blog_identifier_rejected = true;
						add_settings_error(
							'fosse',
							'activitypub_blog_identifier',
							__( 'That site handle matches an existing author login or nickname. Your previous handle was kept.', 'fosse' ),
							'error'
						);
					} elseif ( $ap_provider->blog_identifier_canonicalizes_empty( $raw ) ) {
						// Input that canonicalizes to nothing (e.g. `!!!`)
						// hits AP's `empty()` branch, which swaps in the
						// default username WITHOUT raising a settings error —
						// so neither the collision pre-check nor the error
						// re-tag below would catch it, and `update_option`
						// would silently clobber the saved handle. Mirrors
						// AP_Provider::save_settings.
						$blog_identifier_rejected = true;
						add_settings_error(
							'fosse',
							'activitypub_blog_identifier',
							__( 'That site handle contains no usable characters. Your previous handle was kept.', 'fosse' ),
							'error'
						);
					} else {
						// Snapshot the queue length, not the codes — AP's
						// sanitizer reuses a constant code
						// (`activitypub_blog_identifier`) for every collision
						// rejection, so a code-only check would mask a fresh
						// rejection if any error with that code already sat on
						// the queue. Mirrors AP_Provider::save_settings.
						$ap_error_count_before = count( get_settings_errors( 'activitypub_blog_identifier' ) );

						update_option( 'activitypub_blog_identifier', $raw );

						// Re-tag any fresh AP errors under our own group so the
						// appearance step's `settings_errors( 'fosse' )` render
						// surfaces them — without re-tagging the user would land
						// back on the wizard with no feedback at all. Catches
						// any collision the pre-check missed.
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
			}

			// On rejection, persist the surfaced errors via the per-user
			// notice transient and bounce back to the appearance step so the
			// user can correct the input. Without this the wizard would
			// silently advance to Sharing with no feedback and no way to fix
			// the colliding handle.
			if ( $blog_identifier_rejected ) {
				User_Notices::persist();
				self::redirect_to_step( 'appearance', array( 'settings-updated' => 'true' ) );
			}

			self::redirect_to_step( self::destination_includes_bluesky() ? 'bluesky' : 'content' );
		}

		if ( 'content' === $step ) {
			// Filter to strings before `sanitize_text_field` so a crafted POST
			// submitting nested arrays for an element can't trip the function's
			// array-to-string warning (which `phpunit.xml.dist` promotes to a
			// hard failure via `failOnWarning`, and which would otherwise emit
			// a notice in production). Mirrors {@see Setup_Page::save_general_settings()}.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map below; sniffer does not recognize the wrapping pattern.
			$raw       = wp_unslash( (array) ( $_POST['activitypub_support_post_types'] ?? array() ) );
			$submitted = array_map( 'sanitize_text_field', array_filter( $raw, 'is_string' ) );
			$existing  = (array) get_option( 'activitypub_support_post_types', array( 'post' ) );

			// Reconcile against the chooser's managed set so a user can't
			// submit `attachment` (the chooser doesn't render it), and an
			// upstream-enabled `attachment` survives a FOSSE save.
			$post_types = Post_Type_Chooser::reconcile_submission( $submitted, $existing );

			// Empty *managed* selection would silently disable post/page
			// federation. Bounce back with an error rather than overwrite
			// the chooser-managed slice with []. Preserved upstream values
			// (e.g. `attachment`) are not enough on their own — the wizard
			// step is explicitly about picking content types to share.
			// Check the raw submission against the chooser's managed set
			// directly rather than re-deriving from `$post_types`, which
			// has already merged in preserved-non-managed values.
			$managed_selected = array_intersect( $submitted, Post_Type_Chooser::names() );
			if ( empty( $managed_selected ) ) {
				self::redirect_to_step( 'content', array( 'error' => 'empty_post_types' ) );
			}

			update_option( 'activitypub_support_post_types', $post_types );

			// The wizard ends here without passing through `handle_complete`,
			// so emit the funnel event directly. Without this, users are
			// invisible to the started → completed funnel.
			if ( ! self::is_complete() ) {
				self::record_wizard_completed();
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

		// Guard against duplicate emits when the user re-submits the
		// completion link (nonces are valid for 12-24h, so a back-button
		// or refresh after completion would otherwise re-fire the event).
		if ( ! self::is_complete() ) {
			self::record_wizard_completed();
		}

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

		// Clear the per-user `fosse_wizard_started` dedup so a re-run
		// after reset emits the started event again. Scoped to the
		// current user — other users with their own dedup flags continue
		// to suppress duplicates within their own wizard lifecycle.
		\delete_user_meta( \get_current_user_id(), self::USER_META_STARTED_EMITTED );

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
	 * Normalize the requested step for read-only rendering.
	 *
	 * Admin page callbacks run after WordPress has already printed part of the
	 * admin shell, so render-only navigation cannot safely redirect. Keep
	 * redirects in admin-post handlers and degrade crafted or stale wizard URLs
	 * to the nearest useful step.
	 *
	 * @param string $step Requested step.
	 * @return string
	 */
	private static function get_render_step( string $step ): string {
		if ( 'complete' === $step && ! self::is_complete() ) {
			return self::resolve_pre_completion_step();
		}

		if ( 'bluesky' === $step && ! self::destination_includes_bluesky() ) {
			return 'content';
		}

		return $step;
	}

	/**
	 * Pick the most useful step to substitute for a pre-completion `?step=complete` URL.
	 *
	 * A user who lands on `?step=complete` before `is_complete()` is true has
	 * either crafted the URL by hand, hit browser-back from a fresh
	 * `mark_complete()` -> `delete_option()` cycle, or bookmarked the link
	 * after a wizard reset. Sending all of those cases back to step 1 throws
	 * away whatever progress they had — pick the latest in-flow step instead
	 * so a partially-finished run resumes near where it left off.
	 *
	 * Use the raw stored option (with `false` as the un-saved sentinel) rather
	 * than `get_destination()`, which always returns the recommended default
	 * and would mask a brand-new wizard run as Fediverse + Bluesky.
	 *
	 * @return string
	 */
	private static function resolve_pre_completion_step(): string {
		if ( false === get_option( self::DESTINATION_OPTION, false ) ) {
			return 'destinations';
		}

		return 'content';
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
	 * Format the fediverse identity summary value for the completion step.
	 *
	 * Embeds the resolved fediverse handle(s) so users see the actual
	 * identity they just stood up rather than just the bare host. Falls
	 * back gracefully when a handle is missing (AP not loaded, user
	 * actor unavailable) — never shows an `@` with no local-part.
	 *
	 * Returns an HTML string. Single-identity branches inline the handle
	 * after a space; the multi-identity `actor_blog` branch uses structured
	 * rows so labels and handles can sit inline on wide screens and stack on
	 * narrow screens. The consumer escapes via `wp_kses`.
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
						. ' ' . self::format_complete_identity_token( $user_handle, 'ap-address' );
				}
				return esc_html__( 'As you (author profiles)', 'fosse' );

			case 'blog':
				if ( '' !== $blog_handle ) {
					return esc_html__( 'As your site', 'fosse' )
						. ' ' . self::format_complete_identity_token( $blog_handle, 'ap-address' );
				}
				$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
				return esc_html__( 'As your site', 'fosse' )
					. ' ' . self::format_complete_identity_token( $site_host ? $site_host : 'yoursite.com', 'host' );

			case 'actor_blog':
				$lines = array(
					sprintf(
						'<span class="fosse-complete-identity__mode">%s</span>',
						esc_html__( 'Both (site + authors)', 'fosse' )
					),
				);
				if ( '' !== $user_handle ) {
					$lines[] = self::format_complete_identity_row(
						esc_html__( 'As you:', 'fosse' ),
						self::format_complete_identity_token( $user_handle, 'ap-address' )
					);
				}
				if ( '' !== $blog_handle ) {
					$lines[] = self::format_complete_identity_row(
						esc_html__( 'As your site:', 'fosse' ),
						self::format_complete_identity_token( $blog_handle, 'ap-address' )
					);
				}
				return '<span class="fosse-complete-identity">' . implode( '', $lines ) . '</span>';
		}

		return esc_html( $mode );
	}

	/**
	 * Format a multi-identity completion row.
	 *
	 * @param string $label Label text.
	 * @param string $token Token HTML.
	 * @return string
	 */
	private static function format_complete_identity_row( string $label, string $token ): string {
		return sprintf(
			'<span class="fosse-complete-identity__row"><span class="fosse-complete-identity__label">%1$s</span> %2$s</span>',
			$label,
			$token
		);
	}

	/**
	 * Format a completion-summary identity token using shared admin token styles.
	 *
	 * The `host` and `handle` types both produce a domain-shaped token, but the
	 * `host` variant omits the leading `@` so a bare site host (e.g.
	 * `example.org`) isn't mistaken for a Fediverse address with no local-part.
	 *
	 * @param string $value Raw handle or fediverse address.
	 * @param string $type  Token type: `ap-address`, `handle`, or `host`. Unknown
	 *                      types fall back to the `handle` shape.
	 * @return string Escaped HTML safe for `wp_kses`.
	 */
	private static function format_complete_identity_token( string $value, string $type ): string {
		$classes = array( 'fosse-token', 'fosse-admin-token' );

		switch ( $type ) {
			case 'ap-address':
				$classes[] = 'fosse-token--ap-address';
				$classes[] = 'fosse-admin-token--ap-address';
				$content   = Status_Formatter::ap_address( $value );
				break;

			case 'host':
				$classes[] = 'fosse-token--host';
				$classes[] = 'fosse-admin-token--host';
				$content   = Status_Formatter::handle( ltrim( $value, '@' ) );
				break;

			case 'handle':
			default:
				$classes[] = 'fosse-token--handle';
				$classes[] = 'fosse-admin-token--handle';
				$content   = '@' . Status_Formatter::handle( ltrim( $value, '@' ) );
				break;
		}

		return sprintf(
			'<code class="%1$s">%2$s</code>',
			esc_attr( implode( ' ', $classes ) ),
			$content
		);
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

		// `auto_publish` no longer rides along with `get_status()` (the
		// Settings toggle that read it was removed). The wizard's
		// complete-step CTA still needs the value to phrase its
		// messaging accurately — "your post will reach Bluesky" vs.
		// "Bluesky is connected but auto-publish is off" — so we read
		// it through the provider's centralized helper, which encodes
		// the absent-defaults-to-enabled rule alongside the recovery
		// handler's `update_option` writes that depend on the same
		// default.
		$auto_publish = Bluesky_Provider::is_auto_publish_enabled();

		$defaults = array(
			'available'    => false,
			'connected'    => false,
			'handle'       => '',
			'did'          => '',
			'pds_endpoint' => '',
			'auto_publish' => $auto_publish,
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
			'bluesky'      => __( 'Bluesky', 'fosse' ),
			'content'      => __( 'Sharing', 'fosse' ),
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
					<span class="fosse-wizard__progress-label"><?php echo esc_html( $labels[ $key ] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Render a wizard card header.
	 *
	 * @param string $title       Header title.
	 * @param string $description Header description.
	 * @return void
	 */
	private static function render_step_card_header( string $title, string $description ): void {
		?>
		<div class="fosse-card-header fosse-wizard__card-header">
			<div class="fosse-wizard__card-heading">
				<h1 class="fosse-wizard__title"><?php echo esc_html( $title ); ?></h1>
				<p class="fosse-wizard__description">
					<?php echo esc_html( $description ); ?>
				</p>
			</div>
		</div>
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
				'class' => 'fosse-destination-card--fediverse-bluesky',
				'icon'  => 'dashicons-star-filled',
				'badge' => __( 'Recommended', 'fosse' ),
				'title' => __( 'Fediverse + Bluesky', 'fosse' ),
				'desc'  => __( 'Create a fediverse profile at your site\'s domain and connect an existing Bluesky account.', 'fosse' ),
			),
			self::DESTINATION_FEDIVERSE_ONLY    => array(
				'class' => 'fosse-destination-card--fediverse-only',
				'icon'  => 'dashicons-networking',
				'badge' => __( 'Simple setup', 'fosse' ),
				'title' => __( 'Fediverse only', 'fosse' ),
				'desc'  => __( 'Create a fediverse profile at your site\'s domain. You can connect Bluesky later.', 'fosse' ),
			),
		);
		?>
		<form class="fosse-wizard__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fosse_wizard_save" />
			<input type="hidden" name="fosse_wizard_step" value="destinations" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<div class="fosse-wizard__card fosse-admin-card">
				<?php
				self::render_step_card_header(
					__( 'Where should your WordPress posts appear?', 'fosse' ),
					__( 'Fediverse publishing creates a profile at your site\'s domain. Bluesky connects an existing account.', 'fosse' )
				);
				?>
				<div class="fosse-card-body">
				<fieldset class="fosse-destination-cards">
					<legend class="screen-reader-text">
						<?php esc_html_e( 'Where to publish', 'fosse' ); ?>
					</legend>
					<?php foreach ( $destinations as $value => $destination ) : ?>
						<label class="fosse-choice-card fosse-destination-card <?php echo esc_attr( $destination['class'] ); ?>">
							<input
								type="radio"
								name="fosse_onboarding_destination"
								value="<?php echo esc_attr( $value ); ?>"
								class="fosse-destination-card__input"
								<?php checked( $value, $current_destination ); ?>
							/>
							<span class="fosse-destination-card__header">
								<span class="fosse-destination-card__icon" aria-hidden="true">
									<span class="dashicons <?php echo esc_attr( $destination['icon'] ); ?>"></span>
								</span>
								<span class="fosse-destination-card__badge"><?php echo esc_html( $destination['badge'] ); ?></span>
							</span>
							<span class="fosse-destination-card__title"><?php echo esc_html( $destination['title'] ); ?></span>
							<span class="fosse-destination-card__desc"><?php echo esc_html( $destination['desc'] ); ?></span>
							<span class="fosse-destination-card__check" aria-hidden="true">
								<span class="dashicons dashicons-yes"></span>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>
				</div>

				<div class="fosse-card-footer">
					<div class="fosse-wizard__actions fosse-wizard__actions--center">
						<div class="fosse-wizard__actions-primary">
							<a href="<?php echo esc_url( self::get_skip_url() ); ?>" class="fosse-wizard__skip">
								<?php esc_html_e( 'Skip setup', 'fosse' ); ?>
							</a>
							<?php submit_button( __( 'Continue', 'fosse' ), 'primary large', 'submit', false ); ?>
						</div>
					</div>
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
		// When constants force a mode, drive every downstream rendering
		// decision (preview visibility, blog handle visibility) off the
		// forced mode so the locked label can't disagree with the rest
		// of the step if the stored option ever drifts.
		if ( Actor_Mode_Lock::is_locked() ) {
			$current_mode = Actor_Mode_Lock::forced_mode();
		}
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( in_array( $site_host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			$site_host = '';
		}
		$nonce = wp_create_nonce( 'fosse_wizard' );

		$modes = array(
			'actor'      => array(
				'icon'  => 'dashicons-admin-users',
				'title' => __( 'As you', 'fosse' ),
				'desc'  => sprintf(
					/* translators: 1: opening <strong> tag, 2: closing </strong> tag */
					__( 'People follow %1$syou%2$s, and posts show your author name. Best for personal sites.', 'fosse' ),
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
						__( 'People follow %1$s%3$s%2$s, and posts show your site name. Best for blogs and publications.', 'fosse' ),
						'<strong>',
						'</strong>',
						esc_html( $site_host )
					)
					: __( 'People follow your site, and posts show your site name. Best for blogs and publications.', 'fosse' ),
			),
			'actor_blog' => array(
				'icon'  => 'dashicons-groups',
				'title' => __( 'Both', 'fosse' ),
				'desc'  => sprintf(
					/* translators: 1: opening <strong> tag, 2: closing </strong> tag */
					__( 'People can follow your site %1$sor%2$s individual authors separately. Best for sites with several writers.', 'fosse' ),
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
		<form class="fosse-wizard__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fosse_wizard_save" />
			<input type="hidden" name="fosse_wizard_step" value="appearance" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<div class="fosse-wizard__card fosse-admin-card">
				<?php
				self::render_step_card_header(
					__( 'Who should people follow?', 'fosse' ),
					__( 'Choose the fediverse identity people can follow when FOSSE shares your selected content types.', 'fosse' )
				);
				?>
				<div class="fosse-card-body">
				<?php settings_errors( 'fosse' ); ?>
				<?php if ( Actor_Mode_Lock::is_locked() ) : ?>
					<?php $forced_mode = Actor_Mode_Lock::forced_mode(); ?>
					<div class="fosse-wizard__locked-mode">
						<p>
							<strong><?php echo esc_html( $modes[ $forced_mode ]['title'] ); ?></strong>
						</p>
						<p class="description">
							<?php echo wp_kses( $modes[ $forced_mode ]['desc'], array( 'strong' => array() ) ); ?>
						</p>
						<p class="description">
							<?php echo esc_html( Actor_Mode_Lock::locked_notice() ); ?>
						</p>
					</div>
					<input type="hidden" name="activitypub_actor_mode" value="<?php echo esc_attr( $forced_mode ); ?>" />
				<?php else : ?>
					<fieldset class="fosse-mode-cards">
						<legend class="screen-reader-text">
							<?php esc_html_e( 'How posts appear', 'fosse' ); ?>
						</legend>
						<?php foreach ( $modes as $value => $mode ) : ?>
							<label class="fosse-choice-card fosse-mode-card">
								<input
									type="radio"
									name="activitypub_actor_mode"
									value="<?php echo esc_attr( $value ); ?>"
									class="fosse-mode-card__input"
									<?php checked( $value, $current_mode ); ?>
								/>
								<div class="fosse-mode-card__icon" aria-hidden="true">
									<span class="dashicons <?php echo esc_attr( $mode['icon'] ); ?>"></span>
								</div>
								<div class="fosse-mode-card__content">
									<div class="fosse-mode-card__title"><?php echo esc_html( $mode['title'] ); ?></div>
									<div class="fosse-mode-card__desc"><?php echo wp_kses( $mode['desc'], array( 'strong' => array() ) ); ?></div>
								</div>
								<div class="fosse-mode-card__check" aria-hidden="true">
									<span class="dashicons dashicons-yes"></span>
								</div>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>

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
						<?php esc_html_e( 'Site handle', 'fosse' ); ?>
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
						<?php esc_html_e( 'The username people use to follow your site in fediverse apps. It cannot match an existing author login or nicename.', 'fosse' ); ?>
					</p>
				</div>
				</div>

				<div class="fosse-card-footer">
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
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Render Sharing step (post types).
	 *
	 * @return void
	 */
	private static function render_step_content(): void {
		self::render_progress( 'content' );

		$post_types     = get_option( 'activitypub_support_post_types', array( 'post' ) );
		$all_post_types = Post_Type_Chooser::types();
		$nonce          = wp_create_nonce( 'fosse_wizard' );
		$back_step      = self::destination_includes_bluesky() ? 'bluesky' : 'appearance';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check on a redirect-back error code.
		$has_empty_error = isset( $_GET['error'] ) && 'empty_post_types' === $_GET['error'];

		?>
		<form class="fosse-wizard__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fosse_wizard_save" />
			<input type="hidden" name="fosse_wizard_step" value="content" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<div class="fosse-wizard__card fosse-admin-card">
				<?php
				self::render_step_card_header(
					__( 'What do you want to share?', 'fosse' ),
					__( 'Choose what FOSSE should share to your selected destinations.', 'fosse' )
				);
				?>
				<div class="fosse-card-body">
				<?php if ( $has_empty_error ) : ?>
					<div class="notice notice-error inline">
						<p><?php esc_html_e( 'Pick at least one content type to share.', 'fosse' ); ?></p>
					</div>
				<?php endif; ?>
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
						<fieldset class="fosse-post-types__group">
							<legend class="fosse-post-types__group-label"><?php echo esc_html( $group['label'] ); ?></legend>
							<?php foreach ( $group['types'] as $pt ) : ?>
								<label class="fosse-choice-card fosse-post-type-item">
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
						</fieldset>
					<?php endforeach; ?>
				</div>

				<div class="fosse-wizard__hint">
					<p><?php esc_html_e( 'Only new posts will be shared. Existing content will not be sent to followers.', 'fosse' ); ?></p>
				</div>
				</div>

				<div class="fosse-card-footer">
					<div class="fosse-wizard__actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard&step=' . $back_step ) ); ?>" class="button">
							&larr; <?php esc_html_e( 'Back', 'fosse' ); ?>
						</a>
						<div class="fosse-wizard__actions-primary">
							<a href="<?php echo esc_url( self::get_skip_url() ); ?>" class="fosse-wizard__skip">
								<?php esc_html_e( 'Skip setup', 'fosse' ); ?>
							</a>
							<?php submit_button( __( 'Continue', 'fosse' ), 'primary', 'submit', false ); ?>
						</div>
					</div>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Render Bluesky step.
	 *
	 * Three states drive the rendered markup:
	 *  - Unavailable (provider not registered or not is_available): info notice.
	 *  - Connected: confirmation summary including the resolved fediverse identity.
	 *  - Disconnected: OAuth-start form posting to admin-post.php, with a sign-up affordance.
	 *
	 * @return void
	 */
	private static function render_step_bluesky(): void {
		self::render_progress( 'bluesky' );
		$status = self::get_bluesky_status();

		// When the OAuth handoff has already completed, the user is on the
		// post-connect view. Suppress the "you can connect later" copy so it
		// doesn't contradict the success state they're looking at.
		$is_connected = (bool) $status['connected'];

		$title = $is_connected
			? __( 'Review Bluesky connection', 'fosse' )
			: __( 'Connect to Bluesky', 'fosse' );

		$description = $is_connected
			? __( 'Review the connected account below. Next, you will choose what FOSSE can share.', 'fosse' )
			: __( 'Connect your Bluesky account now. Next, you will choose what FOSSE can share.', 'fosse' );
		?>
		<div class="fosse-wizard__card fosse-admin-card">
			<?php self::render_step_card_header( $title, $description ); ?>
			<div class="fosse-card-body">
		<?php
		// Atmosphere posts a settings_error after every OAuth round-trip — a
		// "Successfully connected" success notice on the happy path, and an
		// error notice on failure. The wizard's in-card connected state
		// already speaks for the success case, so rendering the top success
		// notice would double-up the confirmation. Surface only error/warning
		// here so a failed connect doesn't go silent on the Bluesky step —
		// EXCEPT for FOSSE's own domain-handle notices, which describe a
		// separate explicit action (the confirm button) and need their own
		// confirmation/error feedback regardless of type.
		foreach ( get_settings_errors( 'atmosphere' ) as $atmosphere_notice ) {
			$notice_type = isset( $atmosphere_notice['type'] ) ? (string) $atmosphere_notice['type'] : 'error';
			$notice_code = isset( $atmosphere_notice['code'] ) ? (string) $atmosphere_notice['code'] : '';

			$is_domain_handle_notice = Bluesky_Domain_Handle::NOTICE_CODE === $notice_code;

			if ( ! $is_domain_handle_notice && in_array( $notice_type, array( 'success', 'updated', 'info' ), true ) ) {
				continue;
			}

			// FOSSE's own notices are stored pre-escaped:
			// Bluesky_Provider::redirect_with_notice() and
			// Bluesky_Domain_Handle::add_settings_notice() both run the
			// message through `esc_html()` at the `add_settings_error()`
			// storage site, so escaping those again here would double-encode
			// entities (e.g. apostrophes rendering as `&#039;`). But bundled
			// Atmosphere ALSO writes to this group without escaping —
			// `Handle::add_settings_notice()` stores raw `WP_Error` text
			// straight off the PDS response — so only messages carrying a
			// FOSSE-owned code may skip the `esc_html()`; everything else is
			// escaped as untrusted plain text.
			$is_pre_escaped_fosse_notice = in_array(
				$notice_code,
				array( Bluesky_Provider::NOTICE_CODE, Bluesky_Domain_Handle::NOTICE_CODE ),
				true
			);
			$notice_message              = isset( $atmosphere_notice['message'] ) ? (string) $atmosphere_notice['message'] : '';

			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				esc_attr( $notice_type ),
				$is_pre_escaped_fosse_notice ? wp_kses_post( $notice_message ) : esc_html( $notice_message ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FOSSE-owned notices are pre-escaped via esc_html() at the add_settings_error() storage site (re-escaping would double-encode entities) and pass wp_kses_post() as belt and braces; all other writers' messages go through esc_html() here.
			);
		}
		?>

			<?php if ( ! $status['available'] ) : ?>
				<div class="notice notice-info inline fosse-wizard__notice">
					<p>
						<strong><?php esc_html_e( 'Bluesky setup is unavailable.', 'fosse' ); ?></strong>
						<?php esc_html_e( 'You can continue setup now and connect from FOSSE Settings later.', 'fosse' ); ?>
					</p>
				</div>
			<?php elseif ( $is_connected ) : ?>
				<?php $handle_previews = self::get_handle_previews( get_option( 'activitypub_actor_mode', 'actor' ) ); ?>
				<div class="notice notice-success inline fosse-wizard__notice">
					<p>
						<strong><?php esc_html_e( 'Bluesky is connected.', 'fosse' ); ?></strong>
						<?php esc_html_e( 'FOSSE can share eligible posts with this account.', 'fosse' ); ?>
					</p>
				</div>

				<dl class="fosse-detail-list">
					<?php if ( $status['handle'] ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'Handle', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description"><?php echo esc_html( $status['handle'] ); ?></dd>
					<?php endif; ?>
					<?php if ( $status['did'] ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'Account ID', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description"><code><?php echo esc_html( $status['did'] ); ?></code></dd>
					<?php endif; ?>
					<?php if ( ! empty( $handle_previews['user'] ) ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'Your fediverse address', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description"><code><?php echo esc_html( $handle_previews['user'] ); ?></code></dd>
					<?php endif; ?>
					<?php if ( ! empty( $handle_previews['blog'] ) ) : ?>
						<dt class="fosse-detail-list__term"><?php esc_html_e( 'Site fediverse address', 'fosse' ); ?></dt>
						<dd class="fosse-detail-list__description"><code><?php echo esc_html( $handle_previews['blog'] ); ?></code></dd>
					<?php endif; ?>
				</dl>

				<?php
				// Connected-state confirm button: replace the user's Bluesky
				// handle with the site domain. Always requires this explicit
				// click — Bluesky_Domain_Handle::should_offer() short-circuits
				// when the handle already matches, when the install is in a
				// subdirectory, or when the feature is disabled.
				if ( Bluesky_Domain_Handle::should_offer( $status ) ) :
					$target_host = Bluesky_Domain_Handle::get_target_handle();
					$is_drift    = Bluesky_Domain_Handle::is_drift( $status );
					?>
					<div class="fosse-wizard__domain-handle">
						<h3>
							<?php
							if ( $is_drift ) {
								esc_html_e( 'Realign your Bluesky handle with this site', 'fosse' );
							} else {
								esc_html_e( 'Use your domain as your Bluesky handle', 'fosse' );
							}
							?>
						</h3>
						<p>
							<?php
							if ( $is_drift ) {
								// Either the site domain changed since FOSSE
								// set the handle, or the user changed it on
								// bsky.app directly. Server-side we can't
								// tell which, so the copy stays neutral.
								echo esc_html(
									sprintf(
										/* translators: 1: current Bluesky handle (e.g. example.com); 2: target handle = site host (e.g. newdomain.com). */
										__( 'FOSSE previously set your Bluesky handle, but it no longer matches this site. Your handle on Bluesky is %1$s; this site is %2$s. Set it again to align them.', 'fosse' ),
										(string) $status['handle'],
										$target_host
									)
								);
							} else {
								echo esc_html(
									sprintf(
										/* translators: 1: current Bluesky handle (e.g. alice.bsky.social); 2: target handle = site host (e.g. example.com). */
										__( 'Your handle is currently %1$s. Replace it with %2$s so people can find you on Bluesky by your site\'s domain.', 'fosse' ),
										(string) $status['handle'],
										$target_host
									)
								);
							}
							?>
						</p>
						<?php Bluesky_Domain_Handle::render_destructive_warning_notice(); ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="fosse_set_bluesky_domain_handle" />
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_set_bluesky_domain_handle' ) ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( Bluesky_Provider::RETURN_CONTEXT_FIELD ); ?>" value="<?php echo esc_attr( Bluesky_Provider::RETURN_CONTEXT_WIZARD ); ?>" />
							<?php
							submit_button(
								sprintf(
									/* translators: %s: target handle = site host (e.g. example.com). */
									__( 'Use %s as my Bluesky handle', 'fosse' ),
									$target_host
								),
								'secondary',
								'submit',
								false
							);
							?>
						</form>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<form id="fosse-wizard-bluesky-connect-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fosse_connect_bluesky" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'fosse_connect_bluesky' ) ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( Bluesky_Provider::RETURN_CONTEXT_FIELD ); ?>" value="<?php echo esc_attr( Bluesky_Provider::RETURN_CONTEXT_WIZARD ); ?>" />

					<div class="fosse-bluesky-form">
						<label for="fosse-bsky-handle" class="fosse-bluesky-form__label">
							<?php esc_html_e( 'Bluesky handle', 'fosse' ); ?>
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
							<?php esc_html_e( 'Enter your Bluesky handle, such as yourname.bsky.social. If you use your own domain as your handle, enter that.', 'fosse' ); ?>
						</p>
					</div>
				</form>

				<div class="fosse-wizard__hint fosse-bluesky-signup">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: opening Bluesky signup anchor tag, 2: closing anchor tag, 3: opening domain-handle help anchor tag, 4: closing anchor tag. */
								__( 'Need a Bluesky account? %1$sCreate one%2$s, or %3$slearn how to use your own domain as your handle%4$s.', 'fosse' ),
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

		<?php
		$content_url       = admin_url( 'admin.php?page=fosse-wizard&step=content' );
		$is_connect_prompt = $status['available'] && ! $is_connected;
		$continue_class    = $is_connect_prompt ? 'button' : 'button button-primary';
		$continue_label    = $is_connect_prompt ? __( 'Skip Bluesky for now', 'fosse' ) : __( 'Continue', 'fosse' );
		?>
			<div class="fosse-card-footer">
				<div class="fosse-wizard__actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard&step=appearance' ) ); ?>" class="button">
						&larr; <?php esc_html_e( 'Back', 'fosse' ); ?>
					</a>
					<div class="fosse-wizard__actions-primary">
						<a href="<?php echo esc_url( $content_url ); ?>" class="<?php echo esc_attr( $continue_class ); ?>">
							<?php echo esc_html( $continue_label ); ?>
						</a>
						<?php if ( $is_connect_prompt ) : ?>
							<button type="submit" form="fosse-wizard-bluesky-connect-form" class="button button-primary">
								<?php esc_html_e( 'Connect Bluesky', 'fosse' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
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
			$bluesky_handle     = $bluesky['handle'];
			$bluesky_normalized = ltrim( $bluesky_handle, '@' );

			if ( '' !== $bluesky_normalized ) {
				$bluesky_link = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( 'https://bsky.app/profile/' . rawurlencode( $bluesky_normalized ) ),
					self::format_complete_identity_token( $bluesky_handle, 'handle' )
				);

				$bluesky_summary = sprintf(
					/* translators: %s: linked Bluesky handle. */
					__( 'Connected as %s', 'fosse' ),
					$bluesky_link
				);
			} else {
				$bluesky_summary = __( 'Connected', 'fosse' );
			}
		} elseif ( ! $bluesky['available'] && $includes_bluesky ) {
			$bluesky_summary = __( 'Unavailable', 'fosse' );
		} else {
			$bluesky_summary = $includes_bluesky ? __( 'Not connected', 'fosse' ) : __( 'Skipped', 'fosse' );
		}

		$cta                   = self::resolve_publish_cta( $post_types );
		$next_steps_publishing = self::get_next_steps_publishing_copy( $bluesky, $publishes_bluesky );

		?>
		<div class="fosse-wizard__card fosse-admin-card fosse-wizard__complete-card">
			<div class="fosse-card-header fosse-wizard__complete-header">
				<div class="fosse-complete-icon">
					<span class="dashicons dashicons-yes"></span>
				</div>
				<div class="fosse-wizard__complete-message">
					<h1 class="fosse-wizard__title"><?php esc_html_e( 'You\'re all set!', 'fosse' ); ?></h1>
					<p class="fosse-wizard__description">
						<?php esc_html_e( 'Review your setup below, then publish from WordPress when you are ready.', 'fosse' ); ?>
					</p>
				</div>
			</div>

			<div class="fosse-card-body">
				<dl class="fosse-detail-list">
					<dt class="fosse-detail-list__term"><?php esc_html_e( 'Destinations', 'fosse' ); ?></dt>
					<dd class="fosse-detail-list__description"><?php echo esc_html( $destination_label ); ?></dd>
					<dt class="fosse-detail-list__term"><?php esc_html_e( 'Fediverse identity', 'fosse' ); ?></dt>
					<dd class="fosse-detail-list__description">
						<?php
						echo wp_kses(
							$mode_label,
							array(
								'code' => array(
									'class' => array(),
								),
								'br'   => array(),
								'span' => array(
									'class' => array(),
								),
								'wbr'  => array(),
							)
						);
						?>
					</dd>
					<dt class="fosse-detail-list__term"><?php esc_html_e( 'Bluesky', 'fosse' ); ?></dt>
					<dd class="fosse-detail-list__description<?php echo $bluesky['connected'] ? '' : ' fosse-detail-list__description--muted'; ?>">
						<?php
						echo wp_kses(
							$bluesky_summary,
							array(
								'a'    => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
								'code' => array(
									'class' => array(),
								),
								'wbr'  => array(),
							)
						);
						?>
					</dd>
					<dt class="fosse-detail-list__term"><?php esc_html_e( 'Sharing', 'fosse' ); ?></dt>
					<dd class="fosse-detail-list__description"><?php echo esc_html( implode( ', ', $type_labels ) ); ?></dd>
				</dl>

				<section class="fosse-wizard__next-steps" aria-labelledby="fosse-wizard-next-steps-title">
					<h2 id="fosse-wizard-next-steps-title"><?php esc_html_e( 'What happens next', 'fosse' ); ?></h2>
					<ul>
						<li>
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Publish in WordPress as usual.', 'fosse' ); ?></span>
						</li>
						<li>
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<span><?php echo esc_html( $next_steps_publishing ); ?></span>
						</li>
						<li>
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<span><?php esc_html_e( 'People follow your fediverse address to receive updates.', 'fosse' ); ?></span>
						</li>
					</ul>
				</section>
			</div>
			<div class="fosse-card-footer fosse-wizard__completion-footer">
				<div class="fosse-wizard__completion-actions">
					<div class="fosse-wizard__completion-secondary">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-status' ) ); ?>" class="button">
							<?php esc_html_e( 'View Status Dashboard', 'fosse' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse' ) ); ?>" class="button">
							<?php esc_html_e( 'Go to Settings', 'fosse' ); ?>
						</a>
					</div>
					<a href="<?php echo esc_url( $cta['url'] ); ?>" class="button button-primary fosse-wizard__cta-publish">
						<?php echo esc_html( $cta['label'] ); ?>
					</a>
				</div>
			</div>
		</div>
		<p class="fosse-wizard__reset">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fosse_wizard_reset' ), 'fosse_wizard_reset' ) ); ?>">
				<?php esc_html_e( 'Run wizard again', 'fosse' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Resolve the publishing item shown in the completion next-steps list.
	 *
	 * @param array<string, mixed> $bluesky           Bluesky status.
	 * @param bool                 $publishes_bluesky Whether new eligible content will currently publish to Bluesky.
	 * @return string
	 */
	private static function get_next_steps_publishing_copy( array $bluesky, bool $publishes_bluesky ): string {
		if ( $publishes_bluesky ) {
			return __( 'FOSSE shares eligible new public content to the fediverse and Bluesky automatically.', 'fosse' );
		}

		if ( $bluesky['connected'] ) {
			return __( 'FOSSE shares eligible new public content to the fediverse automatically. Bluesky is connected, but automatic sharing is off.', 'fosse' );
		}

		return __( 'FOSSE shares eligible new public content to the fediverse automatically.', 'fosse' );
	}

	/**
	 * Emit `fosse_wizard_started` once per user.
	 *
	 * Per-user dedup via `USER_META_STARTED_EMITTED` so that page-load
	 * noise (refresh on step 1) doesn't inflate the funnel-entry count.
	 * `entry` is read from `?fosse_entry` if present and recognized,
	 * otherwise defaults to `'menu'`.
	 *
	 * @return void
	 */
	private static function record_wizard_started(): void {
		if ( ! \class_exists( \Automattic\Fosse\Metrics\Recorder::class ) ) {
			return;
		}

		$user_id = \get_current_user_id();
		if ( 0 === $user_id ) {
			return;
		}

		if ( '' !== (string) \get_user_meta( $user_id, self::USER_META_STARTED_EMITTED, true ) ) {
			return;
		}

		// Skip the emit if the dedup flag fails to persist — emitting
		// without persistence inflates the started count by one per
		// page load until the write eventually succeeds.
		if ( false === \update_user_meta( $user_id, self::USER_META_STARTED_EMITTED, '1' ) ) {
			return;
		}

		\Automattic\Fosse\Metrics\Recorder::record(
			'fosse_wizard_started',
			array( 'entry' => self::derive_wizard_entry() )
		);
	}

	/**
	 * Emit `fosse_wizard_completed` with destination / actor / post-types / bluesky state.
	 *
	 * Called immediately before `mark_complete()` from two sites that
	 * have already verified capability and nonce: the Sharing branch of
	 * `handle_save()` (the normal completion path for every destination)
	 * and the legacy `handle_complete()` endpoint kept for stale nonced
	 * links from pre-reorder wizard renders.
	 *
	 * @return void
	 */
	private static function record_wizard_completed(): void {
		if ( ! \class_exists( \Automattic\Fosse\Metrics\Recorder::class ) ) {
			return;
		}

		$post_types       = (array) \get_option( 'activitypub_support_post_types', array( 'post' ) );
		$post_types_count = \Automattic\Fosse\Metrics\Buckets::post_types_count( \count( $post_types ) );

		\Automattic\Fosse\Metrics\Recorder::record(
			'fosse_wizard_completed',
			array(
				'destination'             => self::get_destination(),
				'actor_mode'              => (string) \get_option( 'activitypub_actor_mode', 'actor' ),
				'post_types_count_bucket' => $post_types_count,
				'bluesky_state'           => self::derive_bluesky_state(),
			)
		);
	}

	/**
	 * Resolve the `entry` source for the wizard-started event.
	 *
	 * Reads `?fosse_entry` and validates against `WIZARD_ENTRIES`.
	 * Default `'menu'` covers the legacy direct-nav case where no entry
	 * caller has been retrofitted with the GET param yet.
	 *
	 * @return string One of `WIZARD_ENTRIES`.
	 */
	private static function derive_wizard_entry(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- no state mutation; entry source is a tracking-only signal.
		$raw = isset( $_GET['fosse_entry'] ) ? \sanitize_text_field( \wp_unslash( (string) $_GET['fosse_entry'] ) ) : '';

		return \in_array( $raw, self::WIZARD_ENTRIES, true ) ? $raw : 'menu';
	}

	/**
	 * Resolve the Bluesky-state property for the wizard-completed event.
	 *
	 * - `'connected'`   — `\Atmosphere\is_connected()` returns true.
	 * - `'unavailable'` — Atmosphere isn't loaded (the wizard let the
	 *                    user complete without ever seeing the Bluesky
	 *                    step).
	 * - `'skipped'`     — Atmosphere is loaded but no connection.
	 *
	 * @return string `'connected'|'skipped'|'unavailable'`.
	 */
	private static function derive_bluesky_state(): string {
		if ( ! \function_exists( '\\Atmosphere\\is_connected' ) ) {
			return 'unavailable';
		}
		return \Atmosphere\is_connected() ? 'connected' : 'skipped';
	}
}
