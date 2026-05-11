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
$available       = array_filter(
	$providers,
	static fn( $p ) => $p->is_available()
);
$connected       = array_filter(
	$available,
	static fn( $p ) => ! empty( $p->get_status()['connected'] )
);
$available_count = count( $available );
$connected_count = count( $connected );

if ( 0 === $connected_count ) {
	$summary_description = __( 'Connect a provider to start publishing your WordPress posts across the social web.', 'fosse' );
} elseif ( $connected_count < $available_count ) {
	$summary_description = __( 'Some available providers still need attention before every connection is ready.', 'fosse' );
} else {
	$summary_description = __( 'All available providers are connected and ready to publish.', 'fosse' );
}
?>
<div class="wrap fosse-admin-page fosse-admin-page--status">
	<header class="fosse-admin-page__header">
		<p class="fosse-admin-page__eyebrow"><?php esc_html_e( 'Social web', 'fosse' ); ?></p>
		<h1 class="fosse-admin-page__title"><?php esc_html_e( 'FOSSE Status', 'fosse' ); ?></h1>
		<p class="fosse-admin-page__description">
			<?php esc_html_e( 'Monitor connection health and the identities FOSSE uses when publishing to each network.', 'fosse' ); ?>
		</p>
	</header>

	<?php if ( ! empty( $available ) ) : ?>
		<div class="fosse-status-summary<?php echo esc_attr( $connected_count < $available_count ? ' has-attention' : '' ); ?>">
			<div class="fosse-status-summary__body">
				<p class="fosse-status-summary__label"><?php esc_html_e( 'Connection health', 'fosse' ); ?></p>
				<p class="fosse-status-summary__count">
					<?php
					printf(
						/* translators: 1: number of active connections, 2: total available connections */
						esc_html__( '%1$s of %2$s connections active', 'fosse' ),
						esc_html( number_format_i18n( $connected_count ) ),
						esc_html( number_format_i18n( $available_count ) )
					);
					?>
				</p>
				<p class="fosse-status-summary__description"><?php echo esc_html( $summary_description ); ?></p>
			</div>
			<?php if ( empty( $connected ) ) : ?>
				<p class="fosse-status-summary__actions">
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
		<div class="notice notice-warning fosse-admin-notice">
			<p>
				<?php
				esc_html_e(
					'FOSSE bundles ActivityPub and Atmosphere, so this state usually means one of the bundled backends failed to bootstrap (composer autoload missing, class-loading conflict with a manually installed copy, or a host-level disable). Check the PHP error log and FOSSE\'s plugin activation state.',
					'fosse'
				);
				?>
			</p>
		</div>
	<?php endif; ?>
</div>
