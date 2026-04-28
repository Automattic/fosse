<?php
/**
 * Tests for the activitypub/reactions block relabel projector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Reactions_Label;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;

/**
 * Verifies the FOSSE-side relabel of the bundled activitypub/reactions
 * block: title and description are rewritten via register_block_type_args.
 *
 * Locks the relabel against silent regressions when the bundled
 * ActivityPub plugin is refreshed via tools/sync-bundled.sh. The block
 * itself stays AP-owned; FOSSE only overlays the user-visible wording.
 */
class Reactions_LabelTest extends BaseTestCase {

	/**
	 * Clean hook state and register the projector before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		remove_all_filters( 'register_block_type_args' );

		Reactions_Label::register();
	}

	/**
	 * Matching block name: title and description are rewritten; unrelated keys
	 * round-trip unchanged. Locks the central happy-path invariant.
	 */
	public function test_filter_rewrites_activitypub_reactions_block_args() {
		$args = array(
			'title'       => 'Fediverse Reactions',
			'description' => 'Display Fediverse likes and reposts for your posts.',
			'category'    => 'widgets',
			'icon'        => 'heart',
		);

		$result = apply_filters( 'register_block_type_args', $args, 'activitypub/reactions' );

		$this->assertSame( 'Social Reactions', $result['title'] );
		$this->assertSame( 'Display social likes and reposts for your posts.', $result['description'] );
		$this->assertSame( 'widgets', $result['category'] );
		$this->assertSame( 'heart', $result['icon'] );
	}

	/**
	 * Non-matching block name: every key passes through untouched. Guards
	 * against the projector accidentally mutating other AP blocks (e.g.
	 * activitypub/follow-me) or unrelated core blocks.
	 */
	public function test_filter_passes_through_unrelated_block_names() {
		$args = array(
			'title'       => 'Original Title',
			'description' => 'Original Description',
			'category'    => 'text',
		);

		$result = apply_filters( 'register_block_type_args', $args, 'core/paragraph' );

		$this->assertSame( $args, $result, 'Unrelated block names must round-trip the args unchanged.' );
	}

	/**
	 * Partial args (no description key) for the matching block: title is
	 * rewritten in place, description is NOT invented. The projector overlays
	 * keys upstream supplied; it does not invent shape upstream omits.
	 */
	public function test_filter_does_not_invent_missing_keys() {
		$args = array(
			'title'    => 'Fediverse Reactions',
			'category' => 'widgets',
		);

		$result = apply_filters( 'register_block_type_args', $args, 'activitypub/reactions' );

		$this->assertSame( 'Social Reactions', $result['title'] );
		$this->assertArrayNotHasKey(
			'description',
			$result,
			'Projector must not invent a description key when upstream args omitted it.'
		);
		$this->assertSame( 'widgets', $result['category'] );
	}

	/**
	 * Repeated register() calls leave exactly one callback at priority 10 on
	 * register_block_type_args. Mirrors the Post_Types pattern — WP's WP_Hook
	 * keys callbacks by unique-id, so identical callable-as-array registrations
	 * overwrite the same slot.
	 */
	public function test_register_is_idempotent() {
		// reset_state() already registered once via #[Before]; register again.
		Reactions_Label::register();

		global $wp_filter;

		$this->assertArrayHasKey(
			'register_block_type_args',
			$wp_filter,
			'register() should have added a callback to register_block_type_args.'
		);
		$this->assertCount(
			1,
			$wp_filter['register_block_type_args']->callbacks[10],
			'register() must leave exactly one callback at priority 10 when called twice.'
		);
	}
}
