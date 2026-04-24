<?php
/**
 * Atmosphere plugin class stub for unit tests.
 *
 * WorDBless doesn't load bundled plugins, so Atmosphere_Provider::is_available()
 * would always return false in the test environment. This minimal stub lets the
 * positive branch exercise.
 *
 * @package Automattic\Fosse
 */

namespace Atmosphere;

if ( ! class_exists( __NAMESPACE__ . '\Atmosphere' ) ) {
	/**
	 * Minimal stand-in for the bundled Atmosphere plugin's main class.
	 */
	class Atmosphere {}
}
