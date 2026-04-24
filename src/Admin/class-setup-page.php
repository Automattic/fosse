<?php
/**
 * FOSSE Setup page.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Renders the FOSSE Setup page by iterating registered providers.
 */
class Setup_Page {

	/**
	 * Render the Setup page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$providers = Connection_Provider_Registry::get_providers(); // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- used in template.

		include __DIR__ . '/templates/setup-page.php';
	}
}
