<?php
/**
 * Tests for Bluesky_Domain_Handle.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Atmosphere\OAuth\Encryption;
use Automattic\Fosse\Admin\Bluesky_Domain_Handle;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use WorDBless\BaseTestCase;

/**
 * Verifies the FOSSE Bluesky domain-handle service.
 */
class Bluesky_Domain_HandleTest extends BaseTestCase {

	/**
	 * Reset state before each test.
	 *
	 * @before
	 */
	#[Before]
	public function set_up_state(): void {
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'fosse-test-auth-key' );
		}

		if ( ! defined( 'AUTH_SALT' ) ) {
			define( 'AUTH_SALT', 'fosse-test-auth-salt' );
		}

		$this->reset_filters();
		delete_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE );
		delete_option( 'atmosphere_connection' );
		delete_option( 'atmosphere_identity' );
		delete_option( 'home' );
		delete_option( 'siteurl' );

		// The class-level one-shot revert slot survives across tests within
		// the same PHP process; reset it here so each test starts from a
		// clean view of `get_last_reverted_snapshot()`.
		$reflection = new ReflectionClass( Bluesky_Domain_Handle::class );
		if ( $reflection->hasProperty( 'last_revert_snapshot' ) ) {
			$reflection->getProperty( 'last_revert_snapshot' )->setValue( null, null );
		}

		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core global reset for test isolation.
		delete_transient( 'settings_errors' );
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @after
	 */
	#[After]
	public function tear_down_state(): void {
		$this->reset_filters();
	}

	/**
	 * Drop every filter the suite touches.
	 *
	 * @return void
	 */
	private function reset_filters(): void {
		remove_all_filters( Bluesky_Domain_Handle::FILTER_ENABLED );
		remove_all_filters( Bluesky_Domain_Handle::FILTER_PRE_UPDATE );
		remove_all_filters( 'home_url' );
		remove_all_filters( 'option_home' );
		remove_all_filters( 'pre_option_home' );
	}

	/**
	 * Force `home_url()` to return a fixed value across tests.
	 *
	 * @param string $url URL to return.
	 * @return void
	 */
	private function force_home_url( string $url ): void {
		add_filter(
			'home_url',
			static function ( $existing, $path ) use ( $url ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
				return rtrim( $url, '/' ) . ( '' === $path ? '' : $path );
			},
			10,
			2
		);
	}

	/**
	 * Seed an Atmosphere connection so `is_connected()` and `get_connection()`
	 * resolve to a usable handle without going through real OAuth.
	 *
	 * @param string $handle Handle to seed.
	 * @param string $did    DID to seed.
	 * @return void
	 */
	private function seed_connection( string $handle = 'alice.bsky.social', string $did = 'did:plc:test123' ): void {
		update_option(
			'atmosphere_connection',
			array(
				'did'          => $did,
				'handle'       => $handle,
				'pds_endpoint' => 'https://bsky.social',
				'access_token' => Encryption::encrypt( 'token' ),
			)
		);
	}

	/**
	 * Seed a previous-handle snapshot in the new `{did, handle}` format.
	 *
	 * @param string $did    DID the snapshot is bound to.
	 * @param string $handle Handle to revert to.
	 * @return void
	 */
	private function seed_snapshot( string $did, string $handle ): void {
		update_option(
			Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE,
			array(
				'did'    => $did,
				'handle' => $handle,
			),
			false
		);
	}

	// ---- is_enabled ----

	/**
	 * Feature defaults to enabled.
	 */
	public function test_is_enabled_defaults_true(): void {
		$this->assertTrue( Bluesky_Domain_Handle::is_enabled() );
	}

	/**
	 * Filtering the kill-switch off disables the feature.
	 */
	public function test_is_enabled_respects_filter(): void {
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );
		$this->assertFalse( Bluesky_Domain_Handle::is_enabled() );
	}

	// ---- is_root_install / get_target_handle ----

	/**
	 * Root-install detection accepts every reasonable shape of
	 * `home_url()` for a domain-rooted site.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	public static function root_install_provider(): array {
		return array(
			'no path'             => array( 'https://example.com', true ),
			'trailing slash only' => array( 'https://example.com/', true ),
			'subdomain root'      => array( 'https://blog.example.com', true ),
			'subdirectory'        => array( 'https://example.com/blog', false ),
			'deep subdirectory'   => array( 'https://example.com/site/wp', false ),
			'trailing subdir'     => array( 'https://example.com/blog/', false ),
		);
	}

	/**
	 * Subdirectory installs are excluded from the feature.
	 *
	 * @param string $url      home_url() value.
	 * @param bool   $expected Expected is_root_install() result.
	 * @dataProvider root_install_provider
	 */
	#[DataProvider( 'root_install_provider' )]
	public function test_is_root_install_for_home_url_shape( string $url, bool $expected ): void {
		$this->force_home_url( $url );

		$this->assertSame( $expected, Bluesky_Domain_Handle::is_root_install() );
	}

	/**
	 * Target handle reads the host portion of `home_url()`, lowercased.
	 */
	public function test_get_target_handle_returns_lowercased_host(): void {
		$this->force_home_url( 'https://Example.COM' );
		$this->assertSame( 'example.com', Bluesky_Domain_Handle::get_target_handle() );
	}

	/**
	 * Target handle preserves the subdomain.
	 */
	public function test_get_target_handle_preserves_subdomain(): void {
		$this->force_home_url( 'https://blog.example.com' );
		$this->assertSame( 'blog.example.com', Bluesky_Domain_Handle::get_target_handle() );
	}

	/**
	 * IDN hosts are punycode-encoded so the value matches the AT Protocol
	 * handle lexicon (LDH ASCII labels). Sending raw UTF-8 would either be
	 * rejected by the PDS or — worse — silently accepted as a non-canonical
	 * value the resolver later can't match against /.well-known/atproto-did.
	 */
	public function test_get_target_handle_punycodes_idn_hosts(): void {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'intl extension not available; IDN normalization is environment-gated.' );
		}

		// `münchen.example` (uses ü) → UTS-46 punycode form.
		$this->force_home_url( 'https://münchen.example' );

		$this->assertSame( 'xn--mnchen-3ya.example', Bluesky_Domain_Handle::get_target_handle() );
	}

	/**
	 * IDN normalization punycodes a non-ASCII host so the value matches the
	 * AT Protocol handle lexicon, AND rejects a host carrying an STD3-illegal
	 * character (here `_`, outside the LDH set) rather than emitting it.
	 *
	 * The fix swaps the deprecated transitional `IDNA_DEFAULT` for
	 * `IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES` — the processing
	 * WHATWG's URL Standard mandates. The transitional/non-transitional
	 * divergence on characters like `ß` is ICU-version-dependent and so not
	 * portable to assert on; the STD3 rejection it adds, however, is stable
	 * and is the user-visible behavioral change this test locks in.
	 */
	public function test_get_target_handle_rejects_std3_illegal_host(): void {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'intl extension not available; IDN normalization is environment-gated.' );
		}

		// Underscore is outside the LDH set STD3 permits. The host also
		// carries a non-ASCII byte so the punycode branch runs; STD3 rules
		// then refuse it, and get_target_handle() returns '' rather than
		// shipping a malformed handle to the PDS.
		$this->force_home_url( 'https://bad_läbel.example' );

		$this->assertSame( '', Bluesky_Domain_Handle::get_target_handle() );
	}

	// ---- is_resolvable_host ----

	/**
	 * Resolvable-host gate accepts plain DNS names and rejects every
	 * shape that Bluesky's PDS cannot reach over standard https/443.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	public static function resolvable_host_provider(): array {
		return array(
			'plain dns'          => array( 'https://example.com', true ),
			'subdomain'          => array( 'https://blog.example.com', true ),
			'idn dns'            => array( 'https://xn--mnchen-3ya.example', true ),
			'localhost'          => array( 'https://localhost', false ),
			'localhost.localdom' => array( 'https://localhost.localdomain', false ),
			'mdns local'         => array( 'https://my-mac.local', false ),
			'localhost tld'      => array( 'https://app.localhost', false ),
			'single label'       => array( 'https://intranetbox', false ),
			'ipv4 literal'       => array( 'https://192.0.2.1', false ),
			'ipv4 documented'    => array( 'http://10.0.0.5', false ),
			'ipv6 literal'       => array( 'https://[2001:db8::1]', false ),
			'explicit port 80'   => array( 'http://example.com:80', false ),
			'explicit port 443'  => array( 'https://example.com:443', false ),
			'explicit port 8080' => array( 'https://example.com:8080', false ),
			'empty host'         => array( 'https:///just-a-path', false ),
		);
	}

	/**
	 * Resolvable-host gate rejects IP literals, localhost, single-label
	 * hosts, *.local mDNS names, and any host with an explicit port.
	 *
	 * @param string $url      home_url() value.
	 * @param bool   $expected Expected is_resolvable_host() result.
	 * @dataProvider resolvable_host_provider
	 */
	#[DataProvider( 'resolvable_host_provider' )]
	public function test_is_resolvable_host_rejects_unverifiable_hosts( string $url, bool $expected ): void {
		$this->force_home_url( $url );

		$this->assertSame( $expected, Bluesky_Domain_Handle::is_resolvable_host() );
	}

	// ---- should_offer ----

	/**
	 * A connected user on a root-installed site sees the offer.
	 */
	public function test_should_offer_default_for_connected_root_install(): void {
		$this->force_home_url( 'https://example.com' );

		$this->assertTrue(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Disabled feature suppresses the offer entirely.
	 */
	public function test_should_offer_respects_disabled_filter(): void {
		$this->force_home_url( 'https://example.com' );
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );

		$this->assertFalse(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Subdirectory installs cannot offer the confirm UI.
	 */
	public function test_should_offer_subdirectory_blocked(): void {
		$this->force_home_url( 'https://example.com/blog' );

		$this->assertFalse(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Local / IP-only / non-default-port hosts cannot offer the confirm UI.
	 *
	 * Single representative case from the wider `resolvable_host_provider`
	 * matrix — that data provider already locks the per-shape behavior.
	 */
	public function test_should_offer_unresolvable_host_blocked(): void {
		$this->force_home_url( 'https://localhost' );

		$this->assertFalse(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Disconnected users have no handle to update.
	 */
	public function test_should_offer_disconnected_blocked(): void {
		$this->force_home_url( 'https://example.com' );

		$this->assertFalse(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => false,
					'handle'    => '',
				)
			)
		);
	}

	/**
	 * Connections already on the site host are a no-op offer.
	 */
	public function test_should_offer_skipped_when_handle_matches_host(): void {
		$this->force_home_url( 'https://Example.com' );

		$this->assertFalse(
			Bluesky_Domain_Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'example.com',
				)
			)
		);
	}

	// ---- is_drift ----

	/**
	 * A first-time connected user with no snapshot is NOT in drift —
	 * the offer is a fresh setup, not a re-alignment.
	 */
	public function test_is_drift_false_when_no_snapshot(): void {
		$this->force_home_url( 'https://example.com' );

		$this->assertFalse(
			Bluesky_Domain_Handle::is_drift(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * A snapshot bound to the connected DID plus a current handle that
	 * doesn't match the target IS drift — FOSSE set the handle before
	 * and the two have since gone out of sync (either the site domain
	 * changed or the user changed the handle on bsky.app directly).
	 */
	public function test_is_drift_true_when_snapshot_exists_for_current_did(): void {
		$this->force_home_url( 'https://newdomain.example' );
		$this->seed_connection( 'oldhandle.example', 'did:plc:test123' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		$this->assertTrue(
			Bluesky_Domain_Handle::is_drift(
				array(
					'connected' => true,
					'handle'    => 'oldhandle.example',
				)
			)
		);
	}

	/**
	 * `is_drift()` defers to `should_offer()` — when the offer wouldn't
	 * surface at all (e.g. handle already matches), drift is irrelevant
	 * and the answer is false even with a snapshot present.
	 */
	public function test_is_drift_false_when_offer_would_not_surface(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'example.com', 'did:plc:test123' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		// handle === target, so should_offer is false → is_drift cascades to false.
		$this->assertFalse(
			Bluesky_Domain_Handle::is_drift(
				array(
					'connected' => true,
					'handle'    => 'example.com',
				)
			)
		);
	}

	/**
	 * A snapshot bound to a DIFFERENT DID than the currently-connected
	 * account doesn't count as drift — the snapshot belongs to a prior
	 * account FOSSE managed, not this one. Same guard `should_offer` +
	 * the auto-revert path use to avoid touching identities FOSSE did
	 * not previously set.
	 */
	public function test_is_drift_false_when_snapshot_bound_to_different_did(): void {
		$this->force_home_url( 'https://newdomain.example' );
		$this->seed_connection( 'oldhandle.example', 'did:plc:current' );
		$this->seed_snapshot( 'did:plc:other', 'alice.bsky.social' );

		$this->assertFalse(
			Bluesky_Domain_Handle::is_drift(
				array(
					'connected' => true,
					'handle'    => 'oldhandle.example',
				)
			)
		);
	}

	// ---- set_handle ----

	/**
	 * Disabled feature short-circuits the call AND posts an info notice
	 * so the caller doesn't have to invent a "nothing happened" message.
	 */
	public function test_set_handle_disabled_returns_null_with_info_notice(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection();
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$this->assertNull( Bluesky_Domain_Handle::set_handle() );
		$this->assertFalse( $captured, 'Disabled feature must not invoke the underlying call.' );
		$this->assertContains( 'info', wp_list_pluck( get_settings_errors( 'atmosphere' ), 'type' ) );
	}

	/**
	 * Subdirectory installs short-circuit the call AND post an error notice
	 * explaining why — otherwise the user clicks confirm and sees a silent
	 * reload with no feedback.
	 */
	public function test_set_handle_subdirectory_returns_null_with_error_notice(): void {
		$this->force_home_url( 'https://example.com/blog' );
		$this->seed_connection();

		$this->assertNull( Bluesky_Domain_Handle::set_handle() );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== stripos( $m, 'subdirectory' )
			)
		);
	}

	/**
	 * Empty hostname (degraded `home` option) refuses the call AND posts
	 * an error notice — otherwise we'd send `handle: ''` to the PDS.
	 *
	 * The empty-host case is caught by the `is_resolvable_host()` guard
	 * (no host fails the gate before the get_target_handle() empty-check
	 * runs), so the surfaced message describes the upstream concern: the
	 * URL isn't publicly resolvable.
	 */
	public function test_set_handle_empty_host_returns_null_with_error_notice(): void {
		$this->force_home_url( 'https:///just-a-path' );
		$this->seed_connection();

		$this->assertNull( Bluesky_Domain_Handle::set_handle() );

		$types = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'type' );
		$this->assertContains( 'error', $types );
	}

	/**
	 * IP / localhost / port-bearing URLs refuse the call AND post an error
	 * notice mentioning the structural reason ("publicly-resolvable").
	 * The user clicked an explicit confirm button — they need feedback.
	 */
	public function test_set_handle_unresolvable_host_returns_null_with_explanatory_notice(): void {
		$this->force_home_url( 'http://localhost:8080' );
		$this->seed_connection();

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$this->assertNull( Bluesky_Domain_Handle::set_handle() );
		$this->assertFalse( $captured );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== stripos( $m, 'publicly-resolvable' )
			)
		);
	}

	/**
	 * No connection = no call. Surfaces an error notice for the user.
	 */
	public function test_set_handle_unconnected_returns_error_with_notice(): void {
		$this->force_home_url( 'https://example.com' );
		// No seeded connection.

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'fosse_not_connected', $result->get_error_code() );
		$this->assertContains( 'error', wp_list_pluck( get_settings_errors( 'atmosphere' ), 'type' ) );
	}

	/**
	 * Successful call snapshots the previous handle (DID-bound), syncs the
	 * locally-cached connection handle, and posts a success notice.
	 *
	 * The DID binding prevents reconnect-to-different-account from later
	 * pushing the wrong handle to the new account on disconnect.
	 */
	public function test_set_handle_success_snapshots_previous_handle(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'alice.bsky.social' );

		$received = null;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function ( $pre, $handle ) use ( &$received ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
				$received = $handle;
				return true;
			},
			10,
			2
		);

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertTrue( $result );
		$this->assertSame( 'example.com', $received );
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE )
		);

		// The locally-cached connection handle must reflect the new value
		// immediately — otherwise the UI keeps offering a no-op confirm
		// and Atmosphere's mention-facet builder keeps emitting the old
		// handle.
		$connection = get_option( 'atmosphere_connection' );
		$this->assertSame( 'example.com', $connection['handle'] );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== strpos( $m, 'Your Bluesky handle is now example.com' )
			)
		);
	}

	/**
	 * A second handle change for the same DID must NOT overwrite the original
	 * always-revertible snapshot.
	 *
	 * Scenario: the user first sets their handle to `old.example` (snapshotting
	 * the pre-FOSSE `alice.bsky.social`), then later moves the site to
	 * `new.example` and confirms again. The snapshot must still point at
	 * `alice.bsky.social` — the real, pre-FOSSE identity — not the intermediate
	 * FOSSE-set `old.example`. Overwriting it would strand the only handle that
	 * leads back to the user's original account.
	 */
	public function test_set_handle_does_not_overwrite_existing_snapshot_for_same_did(): void {
		$this->force_home_url( 'https://new.example' );
		// Connection currently on the first FOSSE-set domain.
		$this->seed_connection( 'old.example', 'did:plc:test123' );
		// Snapshot from the FIRST change still points at the original handle.
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ),
			'A second handle change must preserve the original pre-FOSSE snapshot.'
		);
	}

	/**
	 * A snapshot bound to a DIFFERENT account (DID) is replaced on success —
	 * it could never be reverted to under the current DID anyway, so keeping
	 * it would only block recording the genuinely revertible handle for the
	 * account now connected.
	 */
	public function test_set_handle_replaces_snapshot_bound_to_different_did(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'bob.bsky.social', 'did:plc:bob456' );
		// Stale snapshot from a prior, now-disconnected account.
		$this->seed_snapshot( 'did:plc:alice123', 'alice.bsky.social' );

		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );

		$this->assertTrue( Bluesky_Domain_Handle::set_handle() );
		$this->assertSame(
			array(
				'did'    => 'did:plc:bob456',
				'handle' => 'bob.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ),
			'A snapshot for a different DID must be replaced with the current account\'s revertible handle.'
		);
	}

	/**
	 * A successful change mirrors the new handle into the canonical
	 * `atmosphere_identity` store (when one exists), not just
	 * `atmosphere_connection`. `\Atmosphere\get_identity()` reads from there,
	 * and the public verification headers consult it directly — leaving it
	 * stale would drift the handle on the public surface.
	 */
	public function test_set_handle_success_syncs_identity_handle(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'alice.bsky.social', 'did:plc:test123' );
		update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.bsky.social',
				'pds_endpoint' => 'https://bsky.social',
			),
			true
		);

		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );

		$this->assertTrue( Bluesky_Domain_Handle::set_handle() );

		$identity = get_option( 'atmosphere_identity' );
		$this->assertSame( 'example.com', $identity['handle'] );
		// The DID + PDS must be left untouched — only the handle changes.
		$this->assertSame( 'did:plc:test123', $identity['did'] );
		$this->assertSame( 'https://bsky.social', $identity['pds_endpoint'] );
	}

	/**
	 * The identity-handle sync mirrors the new handle into an identity that
	 * Atmosphere lazy-migrated from the connection.
	 *
	 * `set_handle()` calls `\Atmosphere\is_connected()`, which materializes
	 * `atmosphere_identity` from the legacy connection shape on first read. By
	 * the time the post-success sync runs, that identity exists and must
	 * receive the new handle — otherwise the canonical store consulted by the
	 * public verification headers drifts from the PDS.
	 */
	public function test_set_handle_success_syncs_migrated_identity_handle(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'alice.bsky.social', 'did:plc:test123' );
		// No atmosphere_identity seeded — Atmosphere migrates one from the
		// connection during the is_connected() check inside set_handle().

		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );

		$this->assertTrue( Bluesky_Domain_Handle::set_handle() );

		$identity = get_option( 'atmosphere_identity' );
		$this->assertIsArray( $identity );
		$this->assertSame( 'example.com', $identity['handle'] );
		$this->assertSame( 'did:plc:test123', $identity['did'] );
	}

	/**
	 * The sync guard refuses to fabricate identity state directly: invoking
	 * the private sync with no identity on file (and no connection to migrate
	 * from) leaves `atmosphere_identity` absent. Proves fix-4's
	 * `! empty( $identity['did'] )` guard, isolated from the lazy-migration
	 * that the full `set_handle()` path triggers via `is_connected()`.
	 */
	public function test_sync_local_connection_handle_does_not_create_identity_when_absent(): void {
		// Seed only the connection cache the sync reads; no DID, so the lazy
		// migration in get_identity() cannot fire, and no identity exists.
		update_option( 'atmosphere_connection', array( 'handle' => 'alice.bsky.social' ) );

		$method = new \ReflectionMethod( Bluesky_Domain_Handle::class, 'sync_local_connection_handle' );
		$method->invoke( null, 'example.com' );

		$this->assertFalse(
			get_option( 'atmosphere_identity' ),
			'sync must not fabricate an identity record when none exists and none can be migrated.'
		);
	}

	/**
	 * No-op when the connection handle already matches the site host —
	 * nothing to call, nothing to snapshot. Posts an info notice so the
	 * user understands "you're already set" instead of seeing a silent
	 * page reload with no feedback.
	 */
	public function test_set_handle_already_matches_skips_call_and_snapshot(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'example.com' );

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertNull( $result );
		$this->assertFalse( $captured );
		$this->assertFalse( get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ) );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== strpos( $m, 'already' )
			)
		);
	}

	/**
	 * A failed call surfaces the WP_Error and posts an error notice — and
	 * deliberately does NOT leave a stale snapshot behind. The earlier
	 * design wrote the snapshot before the call, which let a failed
	 * `set_handle()` followed by an external handle change get
	 * silently overwritten on disconnect; storing only after confirmed
	 * success closes that gap.
	 */
	public function test_set_handle_failure_surfaces_error_without_writing_snapshot(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'alice.bsky.social' );

		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static fn() => new \WP_Error( 'fake_pds', 'rate limited' )
		);

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate limited', $result->get_error_message() );
		$this->assertFalse( get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ) );

		// The locally-cached connection handle must NOT change on failure —
		// the remote handle is still the old value.
		$connection = get_option( 'atmosphere_connection' );
		$this->assertSame( 'alice.bsky.social', $connection['handle'] );

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== strpos( $m, 'Could not set example.com' ) && false !== strpos( $m, 'rate limited' )
			)
		);
	}

	// ---- maybe_revert_on_disconnect ----

	/**
	 * Revert is a no-op without a snapshotted previous handle.
	 */
	public function test_maybe_revert_on_disconnect_without_snapshot_returns_null(): void {
		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$this->assertNull( Bluesky_Domain_Handle::maybe_revert_on_disconnect() );
		$this->assertFalse( $captured );
	}

	/**
	 * Successful revert clears the snapshot, syncs the locally-cached
	 * connection handle, clears `atmosphere_identity` (so the well-known
	 * route stops advertising a DID the PDS-side handle no longer claims),
	 * and posts an info notice.
	 */
	public function test_maybe_revert_on_disconnect_success_clears_option(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );
		// Seed identity so we can assert it gets cleared. Without the
		// clear, /.well-known/atproto-did keeps serving did:plc:test123
		// against a domain the account no longer claims.
		update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://bsky.social',
			),
			true
		);

		$received = null;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function ( $pre, $handle ) use ( &$received ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
				$received = $handle;
				return true;
			},
			10,
			2
		);

		$result = Bluesky_Domain_Handle::maybe_revert_on_disconnect();

		$this->assertTrue( $result );
		$this->assertSame( 'alice.bsky.social', $received );
		$this->assertFalse( get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ) );

		// Local connection handle must roll back to match the remote.
		$connection = get_option( 'atmosphere_connection' );
		$this->assertSame( 'alice.bsky.social', $connection['handle'] );

		// Identity must be cleared on a successful revert so the
		// well-known route stops advertising a now-orphaned binding.
		$this->assertFalse(
			get_option( 'atmosphere_identity' ),
			'Successful revert must clear atmosphere_identity so .well-known/atproto-did stops serving the now-orphaned DID.'
		);

		$messages = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'message' );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static fn( $m ) => false !== strpos( $m, 'Restored your previous Bluesky handle' )
			)
		);
	}

	/**
	 * Failed revert preserves the snapshot so a future retry can still revert,
	 * and deliberately does NOT post its own notice — the caller composes a
	 * combined "disconnected, but couldn't revert" message instead, so the
	 * user doesn't see a green "Disconnected" success appended after a
	 * yellow warning that's easy to read past.
	 */
	public function test_maybe_revert_on_disconnect_failure_preserves_option_without_notice(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );
		// Seed identity so we can assert it is preserved across a failed
		// revert. The PDS-side handle is still `example.com` (the revert
		// didn't land), so the well-known route should keep serving the
		// DID — exactly the state the recovery panel exists to preserve.
		$seeded_identity = array(
			'did'          => 'did:plc:test123',
			'handle'       => 'example.com',
			'pds_endpoint' => 'https://bsky.social',
		);
		update_option( 'atmosphere_identity', $seeded_identity, true );

		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static fn() => new \WP_Error( 'fake_pds', 'token revoked' )
		);

		$result = Bluesky_Domain_Handle::maybe_revert_on_disconnect();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE )
		);

		// Failed revert must preserve atmosphere_identity. The PDS-side
		// handle is still the site's domain, so the well-known anchor
		// remains accurate; clearing it here would break reconnect for a
		// user whose revert temporarily failed (token issue, network
		// hiccup) but who still has a valid domain-handle binding.
		$this->assertSame(
			$seeded_identity,
			get_option( 'atmosphere_identity' ),
			'Failed revert must preserve atmosphere_identity — the PDS-side handle binding still holds.'
		);

		$this->assertEmpty(
			get_settings_errors( 'atmosphere' ),
			'maybe_revert_on_disconnect() must not post its own notice on failure — the caller composes a combined message.'
		);
	}

	/**
	 * Snapshot is bound to the DID it was taken under. A subsequent
	 * connect-to-different-account followed by disconnect must NOT push
	 * the prior account's handle onto the new account.
	 */
	public function test_maybe_revert_on_disconnect_refuses_when_did_does_not_match(): void {
		$this->seed_connection( 'bob.bsky.social', 'did:plc:bob456' );
		$this->seed_snapshot( 'did:plc:alice123', 'alice.bsky.social' );

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$result = Bluesky_Domain_Handle::maybe_revert_on_disconnect();

		$this->assertNull( $result );
		$this->assertFalse( $captured, 'Revert must not run when the snapshot belongs to a different DID.' );
		$this->assertNotFalse(
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ),
			'Mismatched-DID snapshots are kept — the legitimate account may reconnect later.'
		);
	}

	/**
	 * Disabled feature skips the revert path entirely.
	 */
	public function test_maybe_revert_on_disconnect_disabled_returns_null(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );

		$captured = false;
		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static function () use ( &$captured ) {
				$captured = true;
				return true;
			}
		);

		$this->assertNull( Bluesky_Domain_Handle::maybe_revert_on_disconnect() );
		$this->assertFalse( $captured );
		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE )
		);
	}

	// ---- restore_snapshot / get_last_reverted_snapshot ----

	/**
	 * Round-trips a snapshot through `restore_snapshot()` and confirms
	 * `read_snapshot_for_current_did()` reads it back when the connection
	 * matches. Validates the recovery path used after a disconnect failure
	 * that follows a successful PDS revert.
	 */
	public function test_restore_snapshot_round_trips_to_read_snapshot(): void {
		$this->seed_connection( 'example.com', 'did:plc:test123' );

		$this->assertTrue(
			Bluesky_Domain_Handle::restore_snapshot( 'did:plc:test123', 'alice.bsky.social' )
		);

		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE )
		);

		// `get_pending_revert_handle()` is the public read wrapper for
		// `read_snapshot_for_current_did()` — proves the persisted shape
		// matches the read path's expectations.
		$this->assertSame(
			'alice.bsky.social',
			Bluesky_Domain_Handle::get_pending_revert_handle()
		);
	}

	/**
	 * Empty inputs are refused — never write a sentinel snapshot that the
	 * revert path would read as a no-op anyway, and never overwrite a real
	 * snapshot with garbage.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function restore_snapshot_invalid_input_provider(): array {
		return array(
			'empty did'    => array( '', 'alice.bsky.social' ),
			'empty handle' => array( 'did:plc:test123', '' ),
			'both empty'   => array( '', '' ),
		);
	}

	/**
	 * Empty did/handle inputs return false and do not touch the option.
	 *
	 * @param string $did    DID input.
	 * @param string $handle Handle input.
	 * @dataProvider restore_snapshot_invalid_input_provider
	 */
	#[DataProvider( 'restore_snapshot_invalid_input_provider' )]
	public function test_restore_snapshot_refuses_empty_input( string $did, string $handle ): void {
		$this->assertFalse( Bluesky_Domain_Handle::restore_snapshot( $did, $handle ) );
		$this->assertFalse( get_option( Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE ) );
	}

	/**
	 * Successful revert exposes the consumed snapshot via
	 * `get_last_reverted_snapshot()` so the caller can re-persist on a
	 * disconnect failure that follows. Captured BEFORE the option is
	 * deleted so the caller doesn't have to re-read the (now-empty) option.
	 */
	public function test_get_last_reverted_snapshot_exposes_consumed_snapshot_after_success(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );

		$this->assertTrue( Bluesky_Domain_Handle::maybe_revert_on_disconnect() );

		$this->assertSame(
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.bsky.social',
			),
			Bluesky_Domain_Handle::get_last_reverted_snapshot()
		);
	}

	/**
	 * The one-shot accessor returns null after `restore_snapshot()` consumes
	 * the captured snapshot — protects against a follow-on read driving a
	 * second restore call.
	 */
	public function test_get_last_reverted_snapshot_clears_after_restore(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );

		Bluesky_Domain_Handle::maybe_revert_on_disconnect();
		$snapshot = Bluesky_Domain_Handle::get_last_reverted_snapshot();
		$this->assertNotNull( $snapshot );

		Bluesky_Domain_Handle::restore_snapshot( $snapshot['did'], $snapshot['handle'] );

		$this->assertNull( Bluesky_Domain_Handle::get_last_reverted_snapshot() );
	}

	/**
	 * A no-op revert (failed call, missing snapshot, mismatched DID,
	 * disabled feature) leaves nothing in the one-shot slot. The accessor
	 * is a strict success-path hand-off, not a peek at the most recently
	 * read option.
	 */
	public function test_get_last_reverted_snapshot_null_after_failed_revert(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		add_filter(
			Bluesky_Domain_Handle::FILTER_PRE_UPDATE,
			static fn() => new \WP_Error( 'fake_pds', 'token revoked' )
		);

		$this->assertInstanceOf( \WP_Error::class, Bluesky_Domain_Handle::maybe_revert_on_disconnect() );
		$this->assertNull( Bluesky_Domain_Handle::get_last_reverted_snapshot() );
	}

	/**
	 * A subsequent `maybe_revert_on_disconnect()` invocation resets the
	 * slot at the top of the call so a no-op second revert can't surface
	 * stale data captured by a prior successful revert.
	 */
	public function test_get_last_reverted_snapshot_resets_on_subsequent_call(): void {
		$this->seed_connection( 'example.com' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );

		Bluesky_Domain_Handle::maybe_revert_on_disconnect();
		$this->assertNotNull( Bluesky_Domain_Handle::get_last_reverted_snapshot() );

		// Second call: option is already cleared, so this is a no-op revert.
		// The slot must reset so the caller doesn't see a ghost snapshot.
		Bluesky_Domain_Handle::maybe_revert_on_disconnect();
		$this->assertNull( Bluesky_Domain_Handle::get_last_reverted_snapshot() );
	}

	// ---- pre-update filter contract ----

	/**
	 * A non-null, non-bool-true, non-WP_Error short-circuit return is rejected.
	 */
	public function test_invalid_pre_update_filter_return_surfaces_error(): void {
		$this->force_home_url( 'https://example.com' );
		$this->seed_connection( 'alice.bsky.social' );
		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, static fn() => 'unexpected string' );

		$result = Bluesky_Domain_Handle::set_handle();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'fosse_invalid_pre_update_handle_return', $result->get_error_code() );
	}

	/**
	 * Class metadata is stable: filter names, settings group, option key,
	 * and the notice code the wizard's notice-suppression filter pivots on.
	 *
	 * Catches accidental constant renames that would silently break
	 * external integrations filtering on the documented filter names or
	 * reading the previous-handle option directly.
	 */
	public function test_public_constants_are_stable(): void {
		$this->assertSame( 'fosse_domain_handle_enabled', Bluesky_Domain_Handle::FILTER_ENABLED );
		$this->assertSame( 'fosse_pre_bluesky_update_handle', Bluesky_Domain_Handle::FILTER_PRE_UPDATE );
		$this->assertSame( 'atmosphere', Bluesky_Domain_Handle::NOTICE_SETTING );
		$this->assertSame( 'fosse_domain_handle', Bluesky_Domain_Handle::NOTICE_CODE );
		$this->assertSame( 'fosse_bluesky_previous_handle', Bluesky_Domain_Handle::OPTION_PREVIOUS_HANDLE );

		$reflection = new ReflectionClass( Bluesky_Domain_Handle::class );
		$this->assertFalse(
			$reflection->hasConstant( 'FILTER_AUTOMATIC' ),
			'Automatic mode was deliberately removed; replacing a Bluesky handle must always be an explicit user action.'
		);
		$this->assertFalse(
			$reflection->hasConstant( 'FORM_FIELD' ),
			'The pre-OAuth checkbox was deliberately removed; the confirm flow runs after connect.'
		);
	}

	// ---- get_pending_revert_handle ----

	/**
	 * Public revert-getter returns the snapshotted handle when the
	 * snapshot DID matches the current connection — used by the
	 * Settings-page disconnect UI to surface "Disconnecting will also
	 * restore your handle to ..." inline.
	 */
	public function test_get_pending_revert_handle_returns_snapshot_for_matching_did(): void {
		$this->seed_connection( 'example.com', 'did:plc:test123' );
		$this->seed_snapshot( 'did:plc:test123', 'alice.bsky.social' );

		$this->assertSame( 'alice.bsky.social', Bluesky_Domain_Handle::get_pending_revert_handle() );
	}

	/**
	 * Mismatched DID (reconnect to a different account) returns ''
	 * because revert would refuse anyway — the disconnect UI must not
	 * promise a revert that will silently no-op.
	 */
	public function test_get_pending_revert_handle_returns_empty_when_did_mismatches(): void {
		$this->seed_connection( 'bob.bsky.social', 'did:plc:bob456' );
		$this->seed_snapshot( 'did:plc:alice123', 'alice.bsky.social' );

		$this->assertSame( '', Bluesky_Domain_Handle::get_pending_revert_handle() );
	}

	/**
	 * No snapshot at all returns '' — disconnect UI suppresses the note.
	 */
	public function test_get_pending_revert_handle_returns_empty_when_no_snapshot(): void {
		$this->seed_connection( 'alice.bsky.social', 'did:plc:test123' );

		$this->assertSame( '', Bluesky_Domain_Handle::get_pending_revert_handle() );
	}

	/**
	 * Every notice this service posts is tagged with NOTICE_CODE so the
	 * wizard's top-of-step notice-suppression filter can distinguish our
	 * messages from Atmosphere's own connect-success echo.
	 *
	 * Without the code tag, our success message would be silently
	 * suppressed by the wizard's `success`/`info` type filter, leaving the
	 * user with no confirmation that their handle change went through.
	 */
	public function test_all_notices_carry_the_domain_handle_code(): void {
		$this->force_home_url( 'https://example.com' );

		// Cover one early-return path (disabled) plus one successful call —
		// proves the code tag is consistent across info/success/error types.
		add_filter( Bluesky_Domain_Handle::FILTER_ENABLED, '__return_false' );
		Bluesky_Domain_Handle::set_handle();

		remove_all_filters( Bluesky_Domain_Handle::FILTER_ENABLED );
		$this->seed_connection( 'alice.bsky.social' );
		add_filter( Bluesky_Domain_Handle::FILTER_PRE_UPDATE, '__return_true' );
		Bluesky_Domain_Handle::set_handle();

		$codes = wp_list_pluck( get_settings_errors( 'atmosphere' ), 'code' );
		$this->assertNotEmpty( $codes );
		foreach ( $codes as $code ) {
			$this->assertSame( Bluesky_Domain_Handle::NOTICE_CODE, $code );
		}
	}
}
