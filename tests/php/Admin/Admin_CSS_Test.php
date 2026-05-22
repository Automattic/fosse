<?php
/**
 * Tests for admin stylesheet contracts.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use WorDBless\BaseTestCase;

/**
 * Verifies that emitted admin CSS hooks have matching stylesheet rules.
 */
class Admin_CSS_Test extends BaseTestCase {

	/**
	 * Identity token modifiers emitted by admin renderers should be real
	 * styling hooks, not test-only marker classes.
	 *
	 * @dataProvider provide_identity_token_modifier_selectors
	 *
	 * @param string $selector CSS selector expected in the admin stylesheet.
	 */
	#[DataProvider( 'provide_identity_token_modifier_selectors' )]
	public function test_identity_token_modifiers_have_css_rules( string $selector ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture read; assert below fails clearly if unreadable.
		$css = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/assets/css/admin.css' );

		$this->assertIsString( $css );

		// Strip block comments so a selector mentioned in a CSS comment
		// can't satisfy the contract — only real rules should count.
		$css_without_comments = preg_replace( '#/\*.*?\*/#s', '', $css );

		$this->assertStringContainsString( $selector, $css_without_comments );
	}

	/**
	 * Selectors for emitted identity token modifier classes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_identity_token_modifier_selectors(): array {
		return array(
			'completion ap address token' => array( '.fosse-token--ap-address' ),
			'admin ap address token'      => array( '.fosse-admin-token--ap-address' ),
			'status ap address token'     => array( '.fosse-status-card__token--ap-address' ),
			'completion handle token'     => array( '.fosse-token--handle' ),
			'admin handle token'          => array( '.fosse-admin-token--handle' ),
			'status handle token'         => array( '.fosse-status-card__token--handle' ),
			'completion host token'       => array( '.fosse-token--host' ),
			'admin host token'            => array( '.fosse-admin-token--host' ),
		);
	}
}
