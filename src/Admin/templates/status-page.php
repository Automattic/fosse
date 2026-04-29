<?php
/**
 * Status page template.
 *
 * @package Automattic\Fosse
 *
 * @var array<string, \Automattic\Fosse\Admin\Connection_Provider> $providers
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $providers set by Status_Page::render().
$available = array_filter(
	$providers,
	static fn( $p ) => $p->is_available()
);
$connected = array_filter(
	$available,
	static fn( $p ) => ! empty( $p->get_status()['connected'] )
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'FOSSE Status', 'fosse' ); ?></h1>

	<?php if ( ! empty( $available ) ) : ?>
		<div class="fosse-status-summary<?php echo count( $connected ) < count( $available ) ? ' has-attention' : ''; ?>">
			<p>
				<?php
				printf(
					/* translators: 1: number of active connections, 2: total available connections */
					esc_html__( '%1$d of %2$d connections active', 'fosse' ),
					count( $connected ),
					count( $available )
				);
				?>
			</p>
			<?php if ( empty( $connected ) ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=fosse' ) ); ?>">
						<?php esc_html_e( 'Set up FOSSE', 'fosse' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

		<div class="fosse-status-cards">
			<?php
			foreach ( $available as $provider ) {
				$provider->render_status_card();
			}
			?>
		</div>
	<?php else : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'No federation providers are available. Ensure ActivityPub and Atmosphere are installed.', 'fosse' ); ?></p>
		</div>
	<?php endif; ?>
</div>
