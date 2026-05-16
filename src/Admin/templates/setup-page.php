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
<div class="wrap fosse-admin-page fosse-admin-shell fosse-admin-page--settings">
	<header class="fosse-admin-page__header">
		<p class="fosse-admin-page__eyebrow"><?php esc_html_e( 'Social web', 'fosse' ); ?></p>
		<h1 class="fosse-admin-page__title"><?php esc_html_e( 'FOSSE Settings', 'fosse' ); ?></h1>
		<p class="fosse-admin-page__description">
			<?php esc_html_e( 'Control which content types FOSSE can share, how people follow your fediverse identity, and which network accounts are connected.', 'fosse' ); ?>
		</p>
	</header>

	<?php settings_errors( 'fosse' ); ?>

	<?php if ( $wizard_incomplete ) : ?>
		<div class="fosse-guided-setup" role="note">
			<div class="fosse-guided-setup__content">
				<p class="fosse-guided-setup__title"><?php esc_html_e( 'Want a guided setup?', 'fosse' ); ?></p>
				<p class="fosse-guided-setup__description">
					<?php esc_html_e( 'Configure social web publishing in a few steps.', 'fosse' ); ?>
				</p>
			</div>
			<a class="button fosse-guided-setup__button" href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard' ) ); ?>">
				<?php esc_html_e( 'Run the setup wizard', 'fosse' ); ?>
			</a>
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
		<div class="fosse-settings-panel fosse-admin-card" id="fosse-federation-settings">
			<form id="fosse-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( \Automattic\Fosse\Admin\Setup_Page::SAVE_ACTION ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $save_nonce ); ?>" />

				<div class="fosse-settings-panel__header fosse-card-header">
					<h2><?php esc_html_e( 'Publishing settings', 'fosse' ); ?></h2>
					<p class="fosse-settings-panel__description">
						<?php esc_html_e( 'Choose the content types FOSSE publishes and the fediverse identity people can follow.', 'fosse' ); ?>
					</p>
				</div>

				<div class="fosse-card-body">
					<div class="fosse-settings-section" id="fosse-section-general">
						<h3><?php esc_html_e( 'Content and identity', 'fosse' ); ?></h3>

						<div class="fosse-field-stack">
							<div class="fosse-field">
								<div class="fosse-field__label" id="fosse-content-types-label"><?php esc_html_e( 'Content types', 'fosse' ); ?></div>
								<div class="fosse-field__control">
									<fieldset class="fosse-checkbox-grid" aria-labelledby="fosse-content-types-label">
									<legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'fosse' ); ?></legend>
									<?php foreach ( $all_post_types as $pt ) : ?>
										<label class="fosse-checkbox-grid__item">
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
								</div>
							</div>

							<?php if ( $ap_available ) : ?>
								<div class="fosse-field">
									<div class="fosse-field__label" id="fosse-activitypub-profile-label"><?php esc_html_e( 'ActivityPub profile', 'fosse' ); ?></div>
									<div class="fosse-field__control">
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
										<fieldset class="fosse-choice-card-group" aria-labelledby="fosse-activitypub-profile-label">
											<legend class="screen-reader-text"><?php esc_html_e( 'ActivityPub profile', 'fosse' ); ?></legend>
											<label class="fosse-choice-card">
												<input
													class="fosse-choice-card__input"
													type="radio"
													id="fosse-activitypub-actor-mode-actor"
													name="activitypub_actor_mode"
													value="actor"
													aria-describedby="fosse-activitypub-actor-mode-actor-desc fosse-activitypub-actor-mode-note"
													<?php checked( 'actor', $actor_mode ); ?>
												/>
												<span class="fosse-choice-card__body">
													<span class="fosse-choice-card__label">
														<?php esc_html_e( 'Author profiles', 'fosse' ); ?>
													</span>
													<span id="fosse-activitypub-actor-mode-actor-desc" class="description">
														<?php esc_html_e( 'Each WordPress author publishes from their own fediverse profile. People follow individual authors, and posts appear under each author\'s name.', 'fosse' ); ?>
													</span>
												</span>
											</label>
											<label class="fosse-choice-card">
												<input
													class="fosse-choice-card__input"
													type="radio"
													id="fosse-activitypub-actor-mode-blog"
													name="activitypub_actor_mode"
													value="blog"
													aria-describedby="fosse-activitypub-actor-mode-blog-desc fosse-activitypub-actor-mode-note"
													<?php checked( 'blog', $actor_mode ); ?>
												/>
												<span class="fosse-choice-card__body">
													<span class="fosse-choice-card__label">
														<?php esc_html_e( 'Blog profile', 'fosse' ); ?>
													</span>
													<span id="fosse-activitypub-actor-mode-blog-desc" class="description">
														<?php esc_html_e( 'One site-wide profile publishes every post, regardless of author. Use this when people should follow the site as one account.', 'fosse' ); ?>
													</span>
												</span>
											</label>
											<label class="fosse-choice-card">
												<input
													class="fosse-choice-card__input"
													type="radio"
													id="fosse-activitypub-actor-mode-actor-blog"
													name="activitypub_actor_mode"
													value="actor_blog"
													aria-describedby="fosse-activitypub-actor-mode-actor-blog-desc fosse-activitypub-actor-mode-note"
													<?php checked( 'actor_blog', $actor_mode ); ?>
												/>
												<span class="fosse-choice-card__body">
													<span class="fosse-choice-card__label">
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
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<?php
					foreach ( $providers as $provider ) {
						if ( $provider->is_available() ) {
							$provider->render_setup_section();
						}
					}
					?>
				</div>

				<div class="fosse-settings-actions fosse-card-footer fosse-action-bar" id="fosse-settings-actions">
					<?php submit_button( __( 'Save settings', 'fosse' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<div class="fosse-settings-panel fosse-admin-card" id="fosse-connections">
			<div class="fosse-settings-panel__header fosse-card-header">
				<h2><?php esc_html_e( 'Connections', 'fosse' ); ?></h2>
				<p class="fosse-settings-panel__description">
					<?php esc_html_e( 'Review connected accounts and connect or disconnect the networks FOSSE can publish to.', 'fosse' ); ?>
				</p>
			</div>

			<div class="fosse-card-body">
				<?php
				foreach ( $providers as $provider ) {
					if ( $provider->is_available() ) {
						$provider->render_connection_actions();
					}
				}
				?>
			</div>
		</div>
	<?php endif; ?>

	<p class="fosse-admin-page__footer-action">
		<a class="fosse-admin-page__secondary-link" href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard' ) ); ?>">
			<?php esc_html_e( 'Run the wizard', 'fosse' ); ?>
		</a>
	</p>
</div>
<?php
// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
