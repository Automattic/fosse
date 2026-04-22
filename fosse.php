<?php
/**
 * Plugin Name: FOSSE
 * Plugin URI:  https://github.com/Automattic/fosse
 * Description: Social Web
 * Version:     0.0.1
 * Requires at least: 6.9
 * Tested up to: 7.0
 * Requires PHP: 8.2
 * Author:      Automattic, kraftbj, ryancowles
 * Author URI:  https://automattic.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fosse
 *
 * @package Fosse
 */

defined( 'ABSPATH' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/*
 * Bundled federation backends.
 *
 * FOSSE ships release-build copies of wordpress-activitypub and
 * wordpress-atmosphere so users get Mastodon + Bluesky federation out
 * of the box. We skip the bundled copy when the standalone plugin is
 * either already loaded (its constants are defined) OR present on
 * disk at the canonical plugin path — so if the user activates the
 * standalone later in the same request, WP's plugin_sandbox_scrape
 * doesn't redeclare classes we already loaded.
 *
 * This is a short-term bootstrap; FOSSE's own UI will replace the
 * bundled plugins' admin surface in a later iteration.
 */
$fosse_loaded_bundled_ap   = false;
$fosse_loaded_bundled_atmo = false;

$fosse_standalone_ap_present = defined( 'ACTIVITYPUB_PLUGIN_VERSION' )
	|| ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/activitypub/activitypub.php' ) );

if ( ! $fosse_standalone_ap_present && file_exists( __DIR__ . '/bundled/activitypub/activitypub.php' ) ) {
	require_once __DIR__ . '/bundled/activitypub/activitypub.php';
	$fosse_loaded_bundled_ap = true;
}

$fosse_standalone_atmo_present = defined( 'ATMOSPHERE_VERSION' )
	|| ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/atmosphere/atmosphere.php' ) );

if ( ! $fosse_standalone_atmo_present && file_exists( __DIR__ . '/bundled/atmosphere/atmosphere.php' ) ) {
	require_once __DIR__ . '/bundled/atmosphere/atmosphere.php';
	$fosse_loaded_bundled_atmo = true;
}

unset( $fosse_standalone_ap_present, $fosse_standalone_atmo_present );

/*
 * First-load bootstrap for the bundled backends.
 *
 * Bundled plugins never go through the WP plugins screen, so their
 * register_activation_hook callbacks never fire. Run the upstream
 * activate() routines once per distinct upstream version to seed
 * options, flush rewrites, and generate any needed identifiers.
 *
 * Hooked on `init` (priority 20) rather than `plugins_loaded` because
 * AP's activate() calls flush_rewrite_rules(), which requires the
 * $wp_rewrite global — initialized on `init`.
 */
add_action(
	'init',
	static function () use ( $fosse_loaded_bundled_ap, $fosse_loaded_bundled_atmo ) {
		// Degrade cleanly if FOSSE's own composer autoload is missing (e.g.
		// a bare clone without vendor/); bundled plugins still load, just
		// without the first-run activation shim.
		if ( ! class_exists( \Automattic\Fosse\Bundled\Bootstrap::class ) ) {
			return;
		}

		if ( $fosse_loaded_bundled_ap && class_exists( '\Activitypub\Activitypub' ) && defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) ) {
			\Automattic\Fosse\Bundled\Bootstrap::maybe_run(
				'fosse_bundled_ap_bootstrapped',
				ACTIVITYPUB_PLUGIN_VERSION,
				static function () {
					\Activitypub\Activitypub::activate( false );
				}
			);
		}

		if ( $fosse_loaded_bundled_atmo && function_exists( '\Atmosphere\activate' ) && defined( 'ATMOSPHERE_VERSION' ) ) {
			\Automattic\Fosse\Bundled\Bootstrap::maybe_run(
				'fosse_bundled_atmosphere_bootstrapped',
				ATMOSPHERE_VERSION,
				'\Atmosphere\activate'
			);
		}
	},
	20
);

/*
 * Cross-network object-type projector.
 *
 * Translates the single `fosse_object_type` option into per-network
 * filter answers so the Atmosphere short-form discriminator and the
 * ActivityPub object type stay aligned. Hooked at default priority 10
 * so the filter callbacks run before Atmosphere's transition_post_status
 * handler schedules its outbound work.
 */
add_action( 'init', array( '\Automattic\Fosse\Object_Type', 'register' ) );
