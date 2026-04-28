<?php
/**
 * Tests for the cross-network Post_Types projector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Post_Types;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the projector replaces Atmosphere's `atmosphere_syncable_post_types`
 * result with ActivityPub's stored `activitypub_support_post_types` option
 * so a user's AP post-type selection also governs Atmosphere sync.
 */
class Post_TypesTest extends BaseTestCase {

	/**
	 * Reset hook + option state and register fresh callbacks before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		remove_all_filters( 'atmosphere_syncable_post_types' );
		delete_option( 'activitypub_support_post_types' );

		Post_Types::register();
	}

	/**
	 * With no option set, the projector returns AP's default (`['post']`),
	 * regardless of whatever Atmosphere's upstream default was.
	 */
	public function test_returns_default_when_option_unset() {
		$this->assertSame(
			array( 'post' ),
			apply_filters( 'atmosphere_syncable_post_types', array( 'post' ) )
		);
	}

	/**
	 * When AP's option is set, the projector returns that value verbatim —
	 * discarding Atmosphere's upstream default. This is the whole point of
	 * the projector: AP is the single source of truth.
	 */
	public function test_returns_option_value_when_set() {
		update_option( 'activitypub_support_post_types', array( 'post', 'page', 'book' ) );

		$this->assertSame(
			array( 'post', 'page', 'book' ),
			apply_filters( 'atmosphere_syncable_post_types', array( 'post' ) )
		);
	}

	/**
	 * An empty allowlist in AP (user unchecked everything) flows through
	 * as-is — AT should sync nothing. Guards against a future refactor
	 * that falls back to the default on `empty()`, which would silently
	 * resurrect federation the user explicitly disabled.
	 */
	public function test_returns_empty_array_when_option_empty() {
		update_option( 'activitypub_support_post_types', array() );

		$this->assertSame(
			array(),
			apply_filters( 'atmosphere_syncable_post_types', array( 'post' ) )
		);
	}

	/**
	 * Ignore the upstream default passed into the filter — AP's option wins
	 * even when Atmosphere (or another filter earlier in the chain) supplied
	 * something else.
	 */
	public function test_discards_upstream_default() {
		update_option( 'activitypub_support_post_types', array( 'post' ) );

		$this->assertSame(
			array( 'post' ),
			apply_filters( 'atmosphere_syncable_post_types', array( 'page', 'book' ) )
		);
	}

	/**
	 * A late-priority filter on `option_activitypub_support_post_types`
	 * returning a scalar (rogue plugin, buggy integration) must not flow
	 * through to Atmosphere as a coerced `[scalar]` list. The projector
	 * falls back to the default instead.
	 *
	 * This matches real-world corruption conditions: ActivityPub itself
	 * registers an `option_activitypub_support_post_types` filter that
	 * casts the stored value to an array, so a raw scalar stored via
	 * `update_option()` would be normalized before reaching us. A hostile
	 * or buggy filter at a later priority is the realistic way a non-array
	 * value can appear at the projector's read.
	 */
	public function test_falls_back_to_default_on_late_filter_returning_non_array() {
		update_option( 'activitypub_support_post_types', array( 'page' ) );

		$corrupt = static function () {
			return 'not-an-array';
		};

		add_filter( 'option_activitypub_support_post_types', $corrupt, 99 );

		try {
			$this->assertSame(
				array( 'post' ),
				apply_filters( 'atmosphere_syncable_post_types', array() )
			);
		} finally {
			remove_filter( 'option_activitypub_support_post_types', $corrupt, 99 );
		}
	}

	/**
	 * `register()` is idempotent — calling it twice leaves exactly one
	 * callback on the hook. WordPress dedupes identical callable-as-array
	 * registrations (same class::method produces the same unique ID), so
	 * this is guaranteed by the registration pattern; the test pins the
	 * invariant so a refactor to closures or instance methods wouldn't
	 * silently lose dedup behavior without a test failure.
	 *
	 * A purely behavioral assertion (same filter output) wouldn't detect
	 * double-registration because `filter_atmosphere()` is pure. We inspect
	 * `$wp_filter` directly.
	 */
	public function test_register_is_idempotent() {
		// reset_state() already registered once via #[Before]; register again.
		Post_Types::register();

		global $wp_filter;

		$this->assertArrayHasKey(
			'atmosphere_syncable_post_types',
			$wp_filter,
			'register() should have added a callback to atmosphere_syncable_post_types.'
		);
		$this->assertCount(
			1,
			$wp_filter['atmosphere_syncable_post_types']->callbacks[10],
			'register() must leave exactly one callback at priority 10 when called twice.'
		);
	}
}
