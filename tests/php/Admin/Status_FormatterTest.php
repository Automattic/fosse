<?php
/**
 * Tests for Status_Formatter.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Status_Formatter;
use PHPUnit\Framework\Attributes\DataProvider;
use WorDBless\BaseTestCase;

/**
 * Verifies the Status_Formatter renders identifier tokens with sensible
 * `<wbr>` break opportunities and escapes user-controlled input.
 */
class Status_FormatterTest extends BaseTestCase {

	/**
	 * DIDs break after every `:`.
	 */
	public function test_did_inserts_wbr_after_each_colon(): void {
		$this->assertSame(
			'did:<wbr>plc:<wbr>abc123def456',
			Status_Formatter::did( 'did:plc:abc123def456' )
		);
	}

	/**
	 * Handles break after every `.`.
	 */
	public function test_handle_inserts_wbr_after_each_dot(): void {
		$this->assertSame(
			'alice.<wbr>bsky.<wbr>social',
			Status_Formatter::handle( 'alice.bsky.social' )
		);
	}

	/**
	 * Single-label handles (no dot) render unchanged.
	 */
	public function test_handle_with_no_dot_has_no_wbr(): void {
		$this->assertSame( 'alice', Status_Formatter::handle( 'alice' ) );
	}

	/**
	 * URLs break right after the scheme, then before each path-segment-like
	 * separator in the remainder.
	 */
	public function test_url_inserts_wbr_after_scheme_and_before_path_separators(): void {
		$this->assertSame(
			'https://<wbr>example.com<wbr>/some<wbr>/path<wbr>?q=1',
			Status_Formatter::url( 'https://example.com/some/path?q=1' )
		);
	}

	/**
	 * Hostname-only URLs only break after the scheme.
	 */
	public function test_url_without_path_only_breaks_after_scheme(): void {
		$this->assertSame(
			'https://<wbr>bsky.social',
			Status_Formatter::url( 'https://bsky.social' )
		);
	}

	/**
	 * Query strings with multiple parameters break before each `&`. Locks in
	 * the post-`esc_html` regex behavior — a literal `&` in the source URL
	 * becomes `&amp;` after escaping, and the formatter must place the
	 * `<wbr>` immediately before the entity so the rendered ampersand still
	 * has a break opportunity.
	 */
	public function test_url_with_ampersand_query_separator(): void {
		$this->assertSame(
			'https://<wbr>example.com<wbr>/path<wbr>?q=1<wbr>&amp;r=2',
			Status_Formatter::url( 'https://example.com/path?q=1&r=2' )
		);
	}

	/**
	 * AP fediverse addresses break before the second `@` and after each
	 * dot in the host. The leading `@` is preserved verbatim.
	 */
	public function test_ap_address_breaks_at_at_and_dots(): void {
		$this->assertSame(
			'@user<wbr>@server.<wbr>example.<wbr>org',
			Status_Formatter::ap_address( '@user@server.example.org' )
		);
	}

	/**
	 * AP addresses with no leading `@` still get formatted.
	 */
	public function test_ap_address_without_leading_at_works(): void {
		$this->assertSame(
			'user<wbr>@server.<wbr>example',
			Status_Formatter::ap_address( 'user@server.example' )
		);
	}

	/**
	 * AP addresses missing the host part return the input unchanged.
	 * Better to render plainly than to surface a malformed hint.
	 */
	public function test_ap_address_without_host_returns_plain(): void {
		$this->assertSame(
			'@nodomain',
			Status_Formatter::ap_address( '@nodomain' )
		);
	}

	/**
	 * Every token type must escape HTML in user-controlled input.
	 *
	 * @param string $callable Method name on Status_Formatter to call.
	 * @param string $input    Input value containing an HTML-meaningful character.
	 * @dataProvider escaping_provider
	 */
	#[DataProvider( 'escaping_provider' )]
	public function test_each_formatter_escapes_html( string $callable, string $input ): void {
		$output = call_user_func( array( Status_Formatter::class, $callable ), $input );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( '&lt;script', $output );
	}

	/**
	 * Data provider — one entry per formatter method.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function escaping_provider(): array {
		return array(
			'did'        => array( 'did', 'did:plc:<script>alert(1)</script>' ),
			'url'        => array( 'url', 'https://example.com/<script>alert(1)</script>' ),
			'handle'     => array( 'handle', '<script>alice.example' ),
			'ap_address' => array( 'ap_address', '@<script>@host.example' ),
		);
	}

	/**
	 * Empty inputs are safe and return empty strings (or just the leading
	 * separator for AP addresses).
	 */
	public function test_empty_inputs_are_safe(): void {
		$this->assertSame( '', Status_Formatter::did( '' ) );
		$this->assertSame( '', Status_Formatter::url( '' ) );
		$this->assertSame( '', Status_Formatter::handle( '' ) );
		$this->assertSame( '', Status_Formatter::ap_address( '' ) );
	}
}
