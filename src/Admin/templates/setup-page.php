<?php
/**
 * Setup page template.
 *
 * @package Automattic\Fosse
 *
 * @var array<string, \Automattic\Fosse\Admin\Connection_Provider> $providers
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $providers set by Setup_Page::render().
?>
<div class="wrap">
	<h1><?php esc_html_e( 'FOSSE Setup', 'fosse' ); ?></h1>

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

	<?php
	foreach ( $providers as $provider ) {
		if ( $provider->is_available() ) {
			$provider->render_setup_section();
		}
	}
	?>

	<?php if ( empty( $providers ) ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'No federation providers are available. Ensure ActivityPub and Atmosphere are installed.', 'fosse' ); ?></p>
		</div>
	<?php endif; ?>
</div>
