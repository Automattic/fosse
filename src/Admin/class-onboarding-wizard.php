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
 *  1. Welcome - value prop overview
 *  2. Appearance - actor mode selection (blog / actor / actor_blog)
 *  3. Content - post type selection
 *  4. Bluesky - placeholder until Bluesky_Provider ships
 *  5. Complete - summary and handoff to Setup/Status pages
 */
class Onboarding_Wizard {

	/**
	 * Option key tracking wizard completion.
	 *
	 * @var string
	 */
	public const COMPLETED_OPTION = 'fosse_onboarding_completed';

	/**
	 * Transient key for activation redirect.
	 *
	 * @var string
	 */
	public const REDIRECT_TRANSIENT = 'fosse_activation_redirect';

	/**
	 * Valid step slugs in order.
	 *
	 * @var string[]
	 */
	private const STEPS = array( 'welcome', 'appearance', 'content', 'bluesky', 'complete' );

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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$step = self::get_current_step();

		?>
		<div class="wrap fosse-wizard">
			<?php
			switch ( $step ) {
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
					self::render_step_welcome();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Handle form submissions from wizard steps.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_wizard' );

		$step = sanitize_text_field( wp_unslash( $_POST['fosse_wizard_step'] ?? '' ) );

		if ( 'appearance' === $step ) {
			$mode = sanitize_text_field( wp_unslash( $_POST['activitypub_actor_mode'] ?? '' ) );
			if ( in_array( $mode, self::ACTOR_MODES, true ) ) {
				update_option( 'activitypub_actor_mode', $mode );
			}
			self::redirect_to_step( 'content' );
		}

		if ( 'content' === $step ) {
			$submitted   = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['activitypub_support_post_types'] ?? array() ) ) );
			$valid_types = get_post_types( array( 'public' => true ) );
			$post_types  = array_values( array_intersect( $submitted, $valid_types ) );
			update_option( 'activitypub_support_post_types', $post_types );
			self::redirect_to_step( 'bluesky' );
		}

		// Fallback: redirect to next logical step.
		self::redirect_to_step( 'welcome' );
	}

	/**
	 * Handle the "Skip setup" action.
	 *
	 * @return void
	 */
	public static function handle_skip(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_wizard_skip' );

		self::mark_complete();

		wp_safe_redirect( admin_url( 'admin.php?page=fosse' ) );
		exit;
	}

	/**
	 * Handle wizard completion.
	 *
	 * Marks the wizard as complete via a nonced POST action, then redirects
	 * to the completion view. This ensures completion requires explicit user
	 * intent and cannot be triggered via CSRF.
	 *
	 * @return void
	 */
	public static function handle_complete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_wizard_complete' );

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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fosse' ) );
		}

		check_admin_referer( 'fosse_wizard_reset' );

		delete_option( self::COMPLETED_OPTION );

		wp_safe_redirect( admin_url( 'admin.php?page=fosse-wizard' ) );
		exit;
	}

	/**
	 * Get the current step from the query string, validated against known steps.
	 *
	 * @return string
	 */
	private static function get_current_step(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation, no state change.
		$step = sanitize_text_field( wp_unslash( $_GET['step'] ?? 'welcome' ) );

		if ( in_array( $step, self::STEPS, true ) ) {
			return $step;
		}

		return 'welcome';
	}

	/**
	 * Redirect to a specific wizard step.
	 *
	 * @param string $step Step slug.
	 * @return void
	 */
	private static function redirect_to_step( string $step ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=fosse-wizard&step=' . $step ) );
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
	 * Render the progress indicator.
	 *
	 * @param string $current_step Current step slug.
	 * @return void
	 */
	private static function render_progress( string $current_step ): void {
		$labels    = array(
			'welcome'    => __( 'Welcome', 'fosse' ),
			'appearance' => __( 'Appearance', 'fosse' ),
			'content'    => __( 'Content', 'fosse' ),
			'bluesky'    => __( 'Bluesky', 'fosse' ),
		);
		$step_keys = array_keys( $labels );
		$current_i = array_search( $current_step, $step_keys, true );

		if ( false === $current_i ) {
			return;
		}

		?>
		<div class="fosse-wizard__progress">
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
					<div class="fosse-wizard__progress-line<?php echo $is_complete ? ' is-complete' : ''; ?>"></div>
				<?php endif; ?>
				<div class="<?php echo esc_attr( $classes ); ?>">
					<span class="fosse-wizard__progress-dot"></span>
					<?php echo esc_html( $labels[ $key ] ); ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render Step 1: Welcome.
	 *
	 * @return void
	 */
	private static function render_step_welcome(): void {
		self::render_progress( 'welcome' );
		?>
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'Welcome to FOSSE 🦎', 'fosse' ); ?></h1>
		<p class="fosse-wizard__description">
			<?php esc_html_e( 'FOSSE connects your WordPress site to the social web. People can follow your site and see your posts in their feeds, whether they use Mastodon, Bluesky, or any other compatible app.', 'fosse' ); ?>
		</p>

		<div class="fosse-wizard__card">
			<div class="fosse-welcome-features">
				<div class="fosse-welcome-feature">
					<div class="fosse-welcome-feature__icon">
						<span class="dashicons dashicons-admin-site-alt3"></span>
					</div>
					<div class="fosse-welcome-feature__text">
						<strong><?php esc_html_e( 'Reach new audiences', 'fosse' ); ?></strong><br>
						<?php esc_html_e( 'Your posts appear across the social web, including Mastodon, Bluesky, and more.', 'fosse' ); ?>
					</div>
				</div>
				<div class="fosse-welcome-feature">
					<div class="fosse-welcome-feature__icon">
						<span class="dashicons dashicons-admin-home"></span>
					</div>
					<div class="fosse-welcome-feature__text">
						<strong><?php esc_html_e( 'Your site, your home', 'fosse' ); ?></strong><br>
						<?php esc_html_e( 'Everything lives on your WordPress site. You own your content.', 'fosse' ); ?>
					</div>
				</div>
				<div class="fosse-welcome-feature">
					<div class="fosse-welcome-feature__icon">
						<span class="dashicons dashicons-groups"></span>
					</div>
					<div class="fosse-welcome-feature__text">
						<strong><?php esc_html_e( 'Get followers', 'fosse' ); ?></strong><br>
						<?php esc_html_e( 'People follow you from their favorite app. No account needed on your site.', 'fosse' ); ?>
					</div>
				</div>
				<div class="fosse-welcome-feature">
					<div class="fosse-welcome-feature__icon">
						<span class="dashicons dashicons-share"></span>
					</div>
					<div class="fosse-welcome-feature__text">
						<strong><?php esc_html_e( 'Publish once', 'fosse' ); ?></strong><br>
						<?php esc_html_e( 'Write in WordPress, reach everywhere. No copy-pasting between platforms.', 'fosse' ); ?>
					</div>
				</div>
			</div>
		</div>

		<div class="fosse-wizard__actions fosse-wizard__actions--center">
			<div class="fosse-wizard__actions-column">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard&step=appearance' ) ); ?>" class="button button-primary button-hero">
					<?php esc_html_e( 'Get Started', 'fosse' ); ?>
				</a>
				<a href="<?php echo esc_url( self::get_skip_url() ); ?>" class="fosse-wizard__skip">
					<?php esc_html_e( 'Skip setup', 'fosse' ); ?>
				</a>
			</div>
		</div>
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
		$site_url     = wp_parse_url( home_url(), PHP_URL_HOST ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : 'yoursite.com';
		$nonce        = wp_create_nonce( 'fosse_wizard' );

		$modes = array(
			'blog'       => array(
				'icon'  => 'dashicons-admin-site',
				'title' => __( 'As your site', 'fosse' ),
				'desc'  => sprintf(
					/* translators: %s: site domain name */
					__( 'People follow %s. All posts appear from your site\'s name. Best for blogs and publications.', 'fosse' ),
					'<strong>' . esc_html( $site_url ) . '</strong>'
				),
			),
			'actor'      => array(
				'icon'  => 'dashicons-admin-users',
				'title' => __( 'As you', 'fosse' ),
				'desc'  => __( 'People follow <strong>you</strong> personally. Posts appear under your author name. Best for personal sites.', 'fosse' ),
			),
			'actor_blog' => array(
				'icon'  => 'dashicons-groups',
				'title' => __( 'Both', 'fosse' ),
				'desc'  => __( 'People can follow your site <strong>or</strong> individual authors separately. Best for multi-author sites.', 'fosse' ),
			),
		);

		?>
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'How should your site appear?', 'fosse' ); ?></h1>
		<p class="fosse-wizard__description">
			<?php esc_html_e( 'Choose how people on the social web will see your site. This affects who they follow and how your posts appear in their feeds.', 'fosse' ); ?>
		</p>

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

				<div class="fosse-address-preview">
					<span class="fosse-address-preview__label"><?php esc_html_e( 'Your fediverse address:', 'fosse' ); ?></span>
					<code class="fosse-address-preview__address">@<?php echo esc_html( $site_url . '@' . $site_url ); ?></code>
				</div>
			</div>

			<div class="fosse-wizard__actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard&step=welcome' ) ); ?>" class="button">
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

		?>
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'What do you want to share?', 'fosse' ); ?></h1>
		<p class="fosse-wizard__description">
			<?php esc_html_e( 'Choose which types of content appear in people\'s feeds when they follow you. You can change this anytime.', 'fosse' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fosse_wizard_save" />
			<input type="hidden" name="fosse_wizard_step" value="content" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<div class="fosse-wizard__card">
				<div class="fosse-post-types">
					<?php foreach ( $all_post_types as $pt ) : ?>
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
	 * Render Step 4: Bluesky (placeholder).
	 *
	 * @return void
	 */
	private static function render_step_bluesky(): void {
		self::render_progress( 'bluesky' );
		?>
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'Connect to Bluesky', 'fosse' ); ?></h1>
		<p class="fosse-wizard__description">
			<?php esc_html_e( 'Link your Bluesky account so your posts also appear on Bluesky. This step is optional. You can always connect later from the FOSSE Setup page.', 'fosse' ); ?>
		</p>

		<div class="fosse-wizard__card">
			<div class="notice notice-warning inline fosse-wizard__notice">
				<p>
					<strong><?php esc_html_e( 'Coming Soon', 'fosse' ); ?></strong>
					<?php esc_html_e( 'Bluesky connection is being finalized. Skip this step for now and connect once it\'s ready.', 'fosse' ); ?>
				</p>
			</div>

			<div class="fosse-bluesky-placeholder">
				<label for="fosse-bsky-handle" class="fosse-bluesky-placeholder__label">
					<?php esc_html_e( 'Bluesky Handle', 'fosse' ); ?>
				</label>
				<div class="fosse-bluesky-connect">
					<input
						type="text"
						id="fosse-bsky-handle"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'yourname.bsky.social', 'fosse' ); ?>"
						disabled
					/>
					<button type="button" class="button" disabled>
						<?php esc_html_e( 'Connect', 'fosse' ); ?>
					</button>
				</div>
			</div>

			<div class="fosse-wizard__hint">
				<p>
					<?php
					printf(
						/* translators: %s: opening and closing anchor tag for help link */
						esc_html__( 'You\'ll need an existing Bluesky account. If you want to use your domain as your Bluesky handle, %1$slearn how to set that up%2$s.', 'fosse' ),
						'<a href="https://bsky.social/about/blog/4-28-2023-domain-handle-tutorial" target="_blank" rel="noopener noreferrer">',
						'</a>'
					);
					?>
				</p>
			</div>
		</div>

		<div class="fosse-wizard__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard&step=content' ) ); ?>" class="button">
				&larr; <?php esc_html_e( 'Back', 'fosse' ); ?>
			</a>
			<div class="fosse-wizard__actions-primary">
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fosse_wizard_complete' ), 'fosse_wizard_complete' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Skip for now', 'fosse' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Step 5: Complete.
	 *
	 * @return void
	 */
	private static function render_step_complete(): void {
		$actor_mode = get_option( 'activitypub_actor_mode', 'actor' );
		$post_types = get_option( 'activitypub_support_post_types', array( 'post' ) );
		$site_url   = wp_parse_url( home_url(), PHP_URL_HOST ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : 'yoursite.com';

		$mode_labels = array(
			'actor'      => __( 'As you (author profiles)', 'fosse' ),
			'blog'       => sprintf(
				/* translators: %s: site domain */
				__( 'As your site (%s)', 'fosse' ),
				$site_url
			),
			'actor_blog' => __( 'Both (site + authors)', 'fosse' ),
		);

		$type_labels = array_map(
			static function ( $pt_name ) {
				$pt = get_post_type_object( $pt_name );
				return $pt ? $pt->label : $pt_name;
			},
			$post_types
		);

		?>
		<div class="fosse-wizard__complete-header">
			<div class="fosse-complete-icon">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<h1 class="fosse-wizard__title"><?php esc_html_e( 'You\'re all set!', 'fosse' ); ?></h1>
			<p class="fosse-wizard__description">
				<?php esc_html_e( 'Your site is now part of the social web. People can find and follow you from Mastodon, Bluesky, and other compatible apps.', 'fosse' ); ?>
			</p>
		</div>

		<div class="fosse-wizard__card">
			<table class="fosse-summary">
				<tr>
					<td class="fosse-summary__label"><?php esc_html_e( 'Site appears as', 'fosse' ); ?></td>
					<td class="fosse-summary__value"><?php echo esc_html( $mode_labels[ $actor_mode ] ?? $actor_mode ); ?></td>
				</tr>
				<tr>
					<td class="fosse-summary__label"><?php esc_html_e( 'Sharing', 'fosse' ); ?></td>
					<td class="fosse-summary__value"><?php echo esc_html( implode( ', ', $type_labels ) ); ?></td>
				</tr>
				<tr>
					<td class="fosse-summary__label"><?php esc_html_e( 'Bluesky', 'fosse' ); ?></td>
					<td class="fosse-summary__value fosse-summary__value--muted"><?php esc_html_e( 'Not connected', 'fosse' ); ?></td>
				</tr>
			</table>

			<div class="fosse-wizard__hint">
				<p><?php esc_html_e( 'You can change any of these settings from the FOSSE Setup page at any time.', 'fosse' ); ?></p>
			</div>
		</div>

		<div class="fosse-wizard__actions fosse-wizard__actions--center">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-status' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'View Status Dashboard', 'fosse' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse' ) ); ?>" class="button">
				<?php esc_html_e( 'Go to Setup', 'fosse' ); ?>
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
