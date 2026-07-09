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
	 * Read the admin stylesheet with block comments stripped so an
	 * assertion can't be satisfied by a selector mentioned only in a
	 * comment.
	 *
	 * @return string Stylesheet contents minus block comments.
	 */
	private function load_stylesheet_without_comments(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture read; assert below fails clearly if unreadable.
		$css = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/assets/css/admin.css' );

		$this->assertIsString( $css );

		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/**
	 * The base identity token classes wrap long, unbreakable strings
	 * (DIDs, URLs, AP addresses, handles, hosts) by character instead
	 * of overflowing their container. Modifier-specific rules were
	 * consolidated onto the base classes, so this base contract is
	 * what keeps every modifier laid out correctly.
	 */
	public function test_identity_token_base_classes_break_long_strings(): void {
		$css = $this->load_stylesheet_without_comments();

		$this->assertMatchesRegularExpression(
			'/\.fosse-token\s*,\s*\.fosse-admin-token\s*,\s*\.fosse-status-card__token\s*\{[^}]*overflow-wrap:\s*anywhere/s',
			$css,
			'Base identity-token classes should declare `overflow-wrap: anywhere` so long unbreakable strings wrap.'
		);
	}

	/**
	 * Identity token modifiers with their own dedicated styling
	 * (rather than relying on the base classes) should appear as
	 * real CSS rules. Only modifiers that carry modifier-specific
	 * declarations are listed here; modifiers that inherit everything
	 * from the base classes are covered by
	 * {@see self::test_identity_token_base_classes_break_long_strings()}
	 * and the renderer tests that assert the class is emitted.
	 *
	 * @dataProvider provide_identity_token_modifier_selectors
	 *
	 * @param string $selector CSS selector expected in the admin stylesheet.
	 */
	#[DataProvider( 'provide_identity_token_modifier_selectors' )]
	public function test_identity_token_modifiers_have_css_rules( string $selector ): void {
		$this->assertStringContainsString( $selector, $this->load_stylesheet_without_comments() );
	}

	/**
	 * Selectors for emitted identity token modifier classes that carry
	 * their own modifier-specific declarations.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_identity_token_modifier_selectors(): array {
		return array(
			'completion host token'        => array( '.fosse-token--host' ),
			'admin host token'             => array( '.fosse-admin-token--host' ),
			'completion handle token'      => array( '.fosse-token--handle' ),
			'admin handle token'           => array( '.fosse-admin-token--handle' ),
			'status card handle token'     => array( '.fosse-status-card__token--handle' ),
			'completion ap-address token'  => array( '.fosse-token--ap-address' ),
			'admin ap-address token'       => array( '.fosse-admin-token--ap-address' ),
			'status card ap-address token' => array( '.fosse-status-card__token--ap-address' ),
		);
	}
}
