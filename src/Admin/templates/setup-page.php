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
