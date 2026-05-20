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
	$summary_description = sprintf(
		/* translators: %s: number of connected providers */
		_n(
			'FOSSE can publish through %s connected provider. Connect another provider if you want to publish there too.',
			'FOSSE can publish through %s connected providers. Connect another provider if you want to publish there too.',
			$connected_count,
			'fosse'
		),
		number_format_i18n( $connected_count )
	);
} else {
	$summary_description = __( 'All available providers are connected and ready to publish.', 'fosse' );
}
?>
<div class="wrap fosse-admin-page fosse-admin-shell fosse-admin-page--status">
	<header class="fosse-admin-page__header">
		<p class="fosse-admin-page__eyebrow"><?php esc_html_e( 'Social web', 'fosse' ); ?></p>
		<h1 class="fosse-admin-page__title"><?php esc_html_e( 'FOSSE Status', 'fosse' ); ?></h1>
		<p class="fosse-admin-page__description">
			<?php esc_html_e( 'See whether each network is connected and which identities FOSSE will publish from.', 'fosse' ); ?>
		</p>
	</header>

	<?php if ( ! empty( $available ) ) : ?>
		<div
			class="fosse-status-summary fosse-admin-card<?php echo esc_attr( $connected_count < $available_count ? ' has-attention' : '' ); ?>"
			role="group"
			aria-labelledby="fosse-status-summary-label"
		>
			<div class="fosse-status-summary__body fosse-card-body">
				<p
					id="fosse-status-summary-label"
					class="fosse-status-summary__label"
				>
					<?php esc_html_e( 'Provider status', 'fosse' ); ?>
				</p>
				<p class="fosse-status-summary__count">
					<?php
					printf(
						/* translators: 1: number of connected providers, 2: total available providers */
						esc_html__( '%1$s of %2$s providers connected', 'fosse' ),
						esc_html( number_format_i18n( $connected_count ) ),
						esc_html( number_format_i18n( $available_count ) )
					);
					?>
				</p>
				<p class="fosse-status-summary__description"><?php echo esc_html( $summary_description ); ?></p>
			</div>
			<?php if ( $connected_count < $available_count ) : ?>
				<p class="fosse-status-summary__actions fosse-card-footer fosse-action-bar">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=fosse#fosse-connections' ) ); ?>">
						<?php esc_html_e( 'Manage connections', 'fosse' ); ?>
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
					'FOSSE includes ActivityPub and Bluesky support, so this usually means a bundled component failed to load. Check the PHP error log and FOSSE\'s plugin activation state.',
					'fosse'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<p class="fosse-admin-page__footer-action">
		<a class="fosse-admin-page__secondary-link" href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard' ) ); ?>">
			<?php esc_html_e( 'Run the wizard', 'fosse' ); ?>
		</a>
	</p>
</div>
