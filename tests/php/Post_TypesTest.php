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
	 * A corrupted option value (non-array — e.g. a buggy
	 * `option_activitypub_support_post_types` filter returning a scalar)
	 * falls back to the default rather than fatally coercing via `(array)`
	 * and handing Atmosphere a malformed list.
	 */
	public function test_falls_back_to_default_on_non_array_option() {
		update_option( 'activitypub_support_post_types', 'not-an-array' );

		$this->assertSame(
			array( 'post' ),
			apply_filters( 'atmosphere_syncable_post_types', array() )
		);
	}

	/**
	 * `register()` is idempotent — calling it twice does not double-filter.
	 * WordPress dedupes identical callable-as-array registrations, so a
	 * second call must not cause the projector to run twice on a given
	 * apply_filters() invocation.
	 */
	public function test_register_is_idempotent() {
		Post_Types::register();
		update_option( 'activitypub_support_post_types', array( 'post', 'page' ) );

		$this->assertSame(
			array( 'post', 'page' ),
			apply_filters( 'atmosphere_syncable_post_types', array( 'post' ) )
		);
	}
}
