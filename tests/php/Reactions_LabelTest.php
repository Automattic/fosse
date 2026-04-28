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
}
