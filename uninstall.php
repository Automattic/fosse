<?php
/**
 * FOSSE uninstall entrypoint.
 *
 * Runs when WordPress deletes the plugin via wp-admin/plugins.php or the
 * REST `/wp/v2/plugins/<slug>` endpoint, regardless of whether FOSSE is
 * currently active. Delegates to {@see \Automattic\Fosse\Lifecycle::uninstall()}
 * when the autoloader is intact and falls back to procedural cleanup of the
 * same FOSSE-owned keys when it isn't, so release-packaging mistakes can't
 * leave known FOSSE state behind.
 *
 * Never touches `activitypub_*` or `atmosphere_*` options or transients —
 * FOSSE writes some of those during onboarding, but they are canonical
 * settings for the standalone plugins and may still be in use after FOSSE
 * is gone. See `sdd/deactivation-lifecycle/spec.md` for the full rationale.
 *
 * NOT invoked on wp.com Simple (FOSSE there is loaded by a sticker-gated
 * mu-plugin, not a WP plugin entry). `Lifecycle::uninstall()` remains
 * callable from out-of-band tooling for that environment.
 *
 * @package Automattic\Fosse
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload_packages.php' ) ) {
	require_once __DIR__ . '/vendor/autoload_packages.php';
}

if ( class_exists( \Automattic\Fosse\Lifecycle::class ) ) {
	\Automattic\Fosse\Lifecycle::uninstall();
	return;
}

/*
 * Procedural fallback. Mirrors the lists in `class-lifecycle.php`; keep them
 * in lockstep. The fallback runs only when the autoloader failed to expose
 * `Automattic\Fosse\Lifecycle` (corrupt vendor/, partial release zip, etc.)
 * so the worst-case path still cleans up known FOSSE-owned state.
 */

$fosse_owned_options = array(
	'fosse_object_type',
	'fosse_long_form_strategy',
	'fosse_onboarding_completed',
	'fosse_onboarding_destination',
	'fosse_activation_redirect',
	'fosse_bundled_ap_bootstrapped',
	'fosse_bundled_atmosphere_bootstrapped',
	'fosse_canonical_options_migrated',
	'fosse_metrics_consent',
	'fosse_metrics_last_observed_at',
	'fosse_metrics_first_observed_at',
	'fosse_metrics_funnel',
);

foreach ( $fosse_owned_options as $fosse_option ) {
	delete_option( $fosse_option );
}

$fosse_owned_transients = array(
	'fosse_activation_redirect',
	'fosse_deactivation_handoff_pending',
);

foreach ( $fosse_owned_transients as $fosse_transient ) {
	delete_transient( $fosse_transient );
}

$fosse_transient_prefix = 'fosse_bluesky_oauth_return_';

foreach ( array_keys( wp_load_alloptions() ) as $fosse_option_name ) {
	$fosse_option_name = (string) $fosse_option_name;
	if ( str_starts_with( $fosse_option_name, '_transient_' . $fosse_transient_prefix ) ) {
		delete_transient( substr( $fosse_option_name, strlen( '_transient_' ) ) );
	} elseif ( str_starts_with( $fosse_option_name, '_transient_timeout_' . $fosse_transient_prefix ) ) {
		delete_transient( substr( $fosse_option_name, strlen( '_transient_timeout_' ) ) );
	}
}

global $wpdb;

$fosse_escaped_prefix = $wpdb->esc_like( $fosse_transient_prefix );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot uninstall cleanup; no caching layer applies.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $fosse_escaped_prefix . '%',
		'_transient_timeout_' . $fosse_escaped_prefix . '%'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

delete_metadata( 'user', 0, '_fosse_wizard_started_emitted', '', true );
