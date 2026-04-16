<?php
/**
 * Plugin Name: FOSSE
 * Plugin URI:  https://github.com/Automattic/fosse
 * Description: Social Web
 * Version:     0.0.1
 * Requires at least: 6.9
 * Tested up to: 7.0
 * Requires PHP: 7.4
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
