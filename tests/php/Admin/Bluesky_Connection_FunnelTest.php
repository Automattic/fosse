<?php
/**
 * Tests for Bluesky connection-funnel error categorization.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Bluesky_Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use WorDBless\BaseTestCase;
use WP_Error;

/**
 * Locks the WP_Error → `error_category` mapping the OAuth failure paths
 * forward into `fosse_connection_failed`. Pre-classified categories are
 * load-bearing for the privacy contract — raw upstream messages never
 * leave the recorder.
 *
 * Full OAuth flow integration (attempt → completed/failed sequence)
 * is exercised via Playwright e2e in a follow-up; this file pins the
 * categorization helper.
 */
class Bluesky_Connection_FunnelTest extends BaseTestCase {

	/**
	 * Categorization cases.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function categorization_cases(): array {
		return array(
			'rate_limit_word'        => array( 'rate_limited', 'rate_limited' ),
			'rate_limit_429'         => array( '429', 'rate_limited' ),
			'http_request_failed'    => array( 'http_request_failed', 'network_timeout' ),
			'timeout_word'           => array( 'curl_timeout', 'network_timeout' ),
			'pds_lookup'             => array( 'pds_lookup_failed', 'invalid_handle' ),
			'invalid_handle'         => array( 'invalid_handle_format', 'invalid_handle' ),
			'did_resolution'         => array( 'did_resolution_failed', 'invalid_handle' ),
			'oauth_token'            => array( 'oauth_token_exchange_failed', 'auth_failed' ),
			'auth_word'              => array( 'auth_session_expired', 'auth_failed' ),
			'token_word'             => array( 'token_invalid', 'auth_failed' ),
			'atmosphere_state'       => array( 'atmosphere_state', 'auth_failed' ),
			'atmosphere_expired'     => array( 'atmosphere_expired', 'auth_failed' ),
			'atmosphere_dpop'        => array( 'atmosphere_dpop', 'auth_failed' ),
			'atmosphere_decrypt'     => array( 'atmosphere_decrypt', 'auth_failed' ),
			'atmosphere_refresh'     => array( 'atmosphere_refresh', 'auth_failed' ),
			'atmosphere_no_refresh'  => array( 'atmosphere_no_refresh', 'auth_failed' ),
			// `atmosphere_par` (Pushed Authorization Requests) falls
			// through the specific tokens but is caught by the
			// `atmosphere_` prefix fallback.
			'atmosphere_par'         => array( 'atmosphere_par', 'auth_failed' ),
			'atmosphere_connection'  => array( 'atmosphere_connection', 'auth_failed' ),
			'unknown_falls_to_other' => array( 'something_unfamiliar', 'other' ),
			'empty_falls_to_other'   => array( '', 'other' ),
		);
	}

	/**
	 * Each WP_Error code maps to the documented `error_category` enum value.
	 *
	 * @dataProvider categorization_cases
	 *
	 * @param string $code              WP_Error code under test.
	 * @param string $expected_category Expected `error_category` enum.
	 */
	#[DataProvider( 'categorization_cases' )]
	public function test_categorize_wp_error( string $code, string $expected_category ): void {
		$method = new ReflectionMethod( Bluesky_Provider::class, 'categorize_wp_error' );

		$result = $method->invoke( null, new WP_Error( $code, 'an error' ) );

		$this->assertSame( $expected_category, $result );
	}

	/**
	 * Wizard return-context maps to `'wizard'`, anything else maps to `'settings'`.
	 */
	public function test_context_to_source_mapping(): void {
		$method = new ReflectionMethod( Bluesky_Provider::class, 'context_to_source' );

		$this->assertSame( 'wizard', $method->invoke( null, 'wizard' ) );
		$this->assertSame( 'settings', $method->invoke( null, '' ) );
		$this->assertSame( 'settings', $method->invoke( null, 'unknown' ) );
	}
}
