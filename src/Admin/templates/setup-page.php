<?php
/**
 * FOSSE Settings page template.
 *
 * @package Automattic\Fosse
 *
 * @var array<string, \Automattic\Fosse\Admin\Connection_Provider> $providers
 * @var bool                                                       $wizard_incomplete
 * @var bool                                                       $ap_available
 * @var array<int, string>                                         $post_types
 * @var string                                                     $actor_mode
 * @var array<string, \WP_Post_Type>                               $all_post_types
 * @var string                                                     $save_nonce
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- variables set by Setup_Page::render().
?>
<div class="wrap fosse-admin-page fosse-admin-page--settings">
	<header class="fosse-admin-page__header">
		<p class="fosse-admin-page__eyebrow"><?php esc_html_e( 'Social web', 'fosse' ); ?></p>
		<h1 class="fosse-admin-page__title"><?php esc_html_e( 'FOSSE Settings', 'fosse' ); ?></h1>
		<p class="fosse-admin-page__description">
			<?php esc_html_e( 'Control which content types FOSSE can share, how people follow your fediverse identity, and which network accounts are connected.', 'fosse' ); ?>
		</p>
	</header>

	<?php settings_errors( 'fosse' ); ?>

	<?php if ( $wizard_incomplete ) : ?>
		<div class="notice notice-info fosse-admin-notice">
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: opening anchor tag to setup wizard, 2: closing anchor tag */
						__( 'Need a guided setup? %1$sRun the setup wizard%2$s to configure social web publishing in a few steps.', 'fosse' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=fosse-wizard' ) ) . '">',
						'</a>'
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $providers ) ) : ?>
		<div class="notice notice-warning fosse-admin-notice">
			<p>
				<?php
				esc_html_e(
					'FOSSE includes ActivityPub and Bluesky support, so this usually means a bundled component failed to load. Check the PHP error log and FOSSE\'s plugin activation state.',
					'fosse'
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<div class="fosse-settings-panel" id="fosse-federation-settings">
			<form id="fosse-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( \Automattic\Fosse\Admin\Setup_Page::SAVE_ACTION ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $save_nonce ); ?>" />

				<div class="fosse-settings-panel__header">
					<h2><?php esc_html_e( 'Publishing settings', 'fosse' ); ?></h2>
					<p class="fosse-settings-panel__description">
						<?php esc_html_e( 'Choose the content types FOSSE publishes and the fediverse identity people can follow.', 'fosse' ); ?>
					</p>
				</div>

				<div class="fosse-settings-section" id="fosse-section-general">
					<h3><?php esc_html_e( 'Content and identity', 'fosse' ); ?></h3>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Content types', 'fosse' ); ?></th>
							<td>
								<fieldset class="fosse-settings-choice-grid">
									<legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'fosse' ); ?></legend>
									<?php foreach ( $all_post_types as $pt ) : ?>
										<label class="fosse-settings-choice-label">
											<input
												type="checkbox"
												name="activitypub_support_post_types[]"
												value="<?php echo esc_attr( $pt->name ); ?>"
												<?php checked( in_array( $pt->name, $post_types, true ) ); ?>
											/>
											<?php echo esc_html( $pt->label ); ?>
										</label>
									<?php endforeach; ?>
									<p class="description">
										<?php esc_html_e( 'Selected content types can be published to your connected social web providers.', 'fosse' ); ?>
									</p>
								</fieldset>
							</td>
						</tr>

						<?php if ( $ap_available ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'ActivityPub profile', 'fosse' ); ?></th>
								<td>
									<?php if ( \Automattic\Fosse\Admin\Actor_Mode_Lock::is_locked() ) : ?>
										<?php
										$forced_mode  = \Automattic\Fosse\Admin\Actor_Mode_Lock::forced_mode();
										$forced_label = \Automattic\Fosse\Admin\Actor_Mode_Lock::MODE_BLOG === $forced_mode
											? __( 'Blog profile', 'fosse' )
											: __( 'Author profiles', 'fosse' );
										?>
										<p>
											<strong><?php echo esc_html( $forced_label ); ?></strong>
										</p>
										<p class="description">
											<?php echo esc_html( \Automattic\Fosse\Admin\Actor_Mode_Lock::locked_notice() ); ?>
										</p>
										<input type="hidden" name="activitypub_actor_mode" value="<?php echo esc_attr( $forced_mode ); ?>" />
									<?php else : ?>
										<fieldset class="fosse-settings-radio-group">
											<legend class="screen-reader-text"><?php esc_html_e( 'ActivityPub profile', 'fosse' ); ?></legend>
											<label class="fosse-settings-card-option">
												<input
													class="fosse-settings-card-option__input"
													type="radio"
													id="fosse-activitypub-actor-mode-actor"
													name="activitypub_actor_mode"
													value="actor"
													aria-describedby="fosse-activitypub-actor-mode-actor-desc fosse-activitypub-actor-mode-note"
													<?php checked( 'actor', $actor_mode ); ?>
												/>
												<span class="fosse-settings-card-option__body">
													<span class="fosse-settings-card-option__label">
														<?php esc_html_e( 'Author profiles', 'fosse' ); ?>
													</span>
													<span id="fosse-activitypub-actor-mode-actor-desc" class="description">
														<?php esc_html_e( 'Each WordPress author publishes from their own fediverse profile. People follow individual authors, and posts appear under each author\'s name.', 'fosse' ); ?>
													</span>
												</span>
											</label>
											<label class="fosse-settings-card-option">
												<input
													class="fosse-settings-card-option__input"
													type="radio"
													id="fosse-activitypub-actor-mode-blog"
													name="activitypub_actor_mode"
													value="blog"
													aria-describedby="fosse-activitypub-actor-mode-blog-desc fosse-activitypub-actor-mode-note"
													<?php checked( 'blog', $actor_mode ); ?>
												/>
												<span class="fosse-settings-card-option__body">
													<span class="fosse-settings-card-option__label">
														<?php esc_html_e( 'Blog profile', 'fosse' ); ?>
													</span>
													<span id="fosse-activitypub-actor-mode-blog-desc" class="description">
														<?php esc_html_e( 'One site-wide profile publishes every post, regardless of author. Use this when people should follow the site as one account.', 'fosse' ); ?>
													</span>
												</span>
											</label>
											<label class="fosse-settings-card-option">
												<input
													class="fosse-settings-card-option__input"
													type="radio"
													id="fosse-activitypub-actor-mode-actor-blog"
													name="activitypub_actor_mode"
													value="actor_blog"
													aria-describedby="fosse-activitypub-actor-mode-actor-blog-desc fosse-activitypub-actor-mode-note"
													<?php checked( 'actor_blog', $actor_mode ); ?>
												/>
												<span class="fosse-settings-card-option__body">
													<span class="fosse-settings-card-option__label">
														<?php esc_html_e( 'Both author and blog profiles', 'fosse' ); ?>
													</span>
													<span id="fosse-activitypub-actor-mode-actor-blog-desc" class="description">
														<?php esc_html_e( 'Authors keep individual profiles, and the site also has its own blog profile. People can follow either.', 'fosse' ); ?>
													</span>
												</span>
											</label>
											<div class="fosse-activitypub-actor-mode-note">
												<p id="fosse-activitypub-actor-mode-note" class="description">
													<?php esc_html_e( 'Changing this setting does not move followers between profiles. New posts use the profile choice selected here.', 'fosse' ); ?>
												</p>
												<p class="description">
													<?php
													echo wp_kses(
														sprintf(
															/* translators: %s: anchor link reading "Blog profile settings" pointing to the ActivityPub blog profile tab. */
															__( 'Configure the site-wide blog profile name, image, and description in %s.', 'fosse' ),
															'<a href="' . esc_url( admin_url( 'options-general.php?page=activitypub&tab=blog-profile' ) ) . '">' . esc_html__( 'Blog profile settings', 'fosse' ) . '</a>'
														),
														array(
															'a' => array(
																'href' => array(),
															),
														)
													);
													?>
												</p>
											</div>
										</fieldset>
									<?php endif; ?>
								</td>
							</tr>
						<?php endif; ?>
					</table>
				</div>

				<?php
				foreach ( $providers as $provider ) {
					if ( $provider->is_available() ) {
						$provider->render_setup_section();
					}
				}
				?>

				<div class="fosse-settings-actions" id="fosse-settings-actions">
					<?php submit_button( __( 'Save settings', 'fosse' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<div class="fosse-settings-panel" id="fosse-connections">
			<div class="fosse-settings-panel__header">
				<h2><?php esc_html_e( 'Connections', 'fosse' ); ?></h2>
				<p class="fosse-settings-panel__description">
					<?php esc_html_e( 'Review connected accounts and connect or disconnect the networks FOSSE can publish to.', 'fosse' ); ?>
				</p>
			</div>

			<?php
			foreach ( $providers as $provider ) {
				if ( $provider->is_available() ) {
					$provider->render_connection_actions();
				}
			}
			?>
		</div>
	<?php endif; ?>
</div>
<?php
// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
