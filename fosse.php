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
if ( ! defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) && file_exists( __DIR__ . '/bundled/activitypub/activitypub.php' ) ) {
	require_once __DIR__ . '/bundled/activitypub/activitypub.php';
}

if ( ! defined( 'ATMOSPHERE_VERSION' ) && file_exists( __DIR__ . '/bundled/atmosphere/atmosphere.php' ) ) {
	require_once __DIR__ . '/bundled/atmosphere/atmosphere.php';
}
