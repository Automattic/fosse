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
<div class="wrap">
	<h1><?php esc_html_e( 'FOSSE Settings', 'fosse' ); ?></h1>

	<?php settings_errors( 'fosse' ); ?>

	<?php if ( $wizard_incomplete ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: opening anchor tag to setup wizard, 2: closing anchor tag */
						__( 'First time here? %1$sRun the setup wizard%2$s to configure federation in a few steps.', 'fosse' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=fosse-wizard' ) ) . '">',
						'</a>'
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $providers ) ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'No federation providers are available. Ensure ActivityPub and Atmosphere are installed.', 'fosse' ); ?></p>
		</div>
	<?php else : ?>
		<form id="fosse-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( \Automattic\Fosse\Admin\Setup_Page::SAVE_ACTION ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $save_nonce ); ?>" />

			<div class="fosse-provider-section" id="fosse-section-general">
				<h2><?php esc_html_e( 'General', 'fosse' ); ?></h2>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post Types', 'fosse' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Post Types', 'fosse' ); ?></legend>
								<?php foreach ( $all_post_types as $pt ) : ?>
									<label>
										<input
											type="checkbox"
											name="activitypub_support_post_types[]"
											value="<?php echo esc_attr( $pt->name ); ?>"
											<?php checked( in_array( $pt->name, $post_types, true ) ); ?>
										/>
										<?php echo esc_html( $pt->label ); ?>
									</label><br />
								<?php endforeach; ?>
								<p class="description">
									<?php esc_html_e( 'Post types that federate to ActivityPub and to Bluesky via Atmosphere.', 'fosse' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>

					<?php if ( $ap_available ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Actor Mode', 'fosse' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Actor Mode', 'fosse' ); ?></legend>
									<label>
										<input
											type="radio"
											id="fosse-activitypub-actor-mode-actor"
											name="activitypub_actor_mode"
											value="actor"
											aria-describedby="fosse-activitypub-actor-mode-actor-desc fosse-activitypub-actor-mode-note"
											<?php checked( 'actor', $actor_mode ); ?>
										/>
										<?php esc_html_e( 'Author profiles', 'fosse' ); ?>
									</label>
									<p id="fosse-activitypub-actor-mode-actor-desc" class="description">
										<?php esc_html_e( 'Each WordPress author publishes from their own fediverse profile. People follow individual authors, and posts appear under each author\'s name.', 'fosse' ); ?>
									</p>
									<label>
										<input
											type="radio"
											id="fosse-activitypub-actor-mode-blog"
											name="activitypub_actor_mode"
											value="blog"
											aria-describedby="fosse-activitypub-actor-mode-blog-desc fosse-activitypub-actor-mode-note"
											<?php checked( 'blog', $actor_mode ); ?>
										/>
										<?php esc_html_e( 'Blog profile', 'fosse' ); ?>
									</label>
									<p id="fosse-activitypub-actor-mode-blog-desc" class="description">
										<?php esc_html_e( 'One site-wide profile publishes every post, regardless of author. Use this when people should follow the site as one account.', 'fosse' ); ?>
									</p>
									<label>
										<input
											type="radio"
											id="fosse-activitypub-actor-mode-actor-blog"
											name="activitypub_actor_mode"
											value="actor_blog"
											aria-describedby="fosse-activitypub-actor-mode-actor-blog-desc fosse-activitypub-actor-mode-note"
											<?php checked( 'actor_blog', $actor_mode ); ?>
										/>
										<?php esc_html_e( 'Both', 'fosse' ); ?>
									</label>
									<p id="fosse-activitypub-actor-mode-actor-blog-desc" class="description">
										<?php esc_html_e( 'Authors keep individual profiles, and the site also has its own blog profile. People can follow either.', 'fosse' ); ?>
									</p>
									<div class="fosse-activitypub-actor-mode-note">
										<p id="fosse-activitypub-actor-mode-note" class="description">
											<?php esc_html_e( 'Changing modes does not move followers between profiles. Future posts publish from the profiles enabled by the selected mode.', 'fosse' ); ?>
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

			<?php submit_button( __( 'Save settings', 'fosse' ) ); ?>
		</form>

		<?php
		foreach ( $providers as $provider ) {
			if ( $provider->is_available() ) {
				$provider->render_connection_actions();
			}
		}
		?>
	<?php endif; ?>
</div>
<?php
// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
