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
 * of the box. If the user has the standalone plugin active, its
 * constants are already defined (plugins load alphabetically before
 * fosse/) and we skip the bundled copy to avoid collisions.
 *
 * This is a short-term bootstrap; FOSSE's own UI will replace the
 * bundled plugins' admin surface in a later iteration.
 */
$fosse_loaded_bundled_ap   = false;
$fosse_loaded_bundled_atmo = false;

if ( ! defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) && file_exists( __DIR__ . '/bundled/activitypub/activitypub.php' ) ) {
	require_once __DIR__ . '/bundled/activitypub/activitypub.php';
	$fosse_loaded_bundled_ap = true;
}

if ( ! defined( 'ATMOSPHERE_VERSION' ) && file_exists( __DIR__ . '/bundled/atmosphere/atmosphere.php' ) ) {
	require_once __DIR__ . '/bundled/atmosphere/atmosphere.php';
	$fosse_loaded_bundled_atmo = true;
}

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
