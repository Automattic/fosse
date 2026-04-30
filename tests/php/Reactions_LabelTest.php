<?php
/**
 * Tests for the activitypub/reactions block relabel projector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Reactions_Label;
use PHPUnit\Framework\Attributes\After;
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
	 * Register the projector before each test.
	 *
	 * Removes the projector's own callback first so a prior test (or
	 * stray bootstrap registration) cannot double-register, then calls
	 * register() to install a single callback. Surgical, not nuke-the-
	 * whole-hook, so unrelated callbacks other tests/plugins added to
	 * register_block_type_args survive into this case.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		\remove_filter(
			'register_block_type_args',
			array( Reactions_Label::class, 'rewrite_block_args' ),
			10
		);
		Reactions_Label::register();
	}

	/**
	 * Remove only the projector's callback after each test, so the next
	 * test (or unrelated suite running later) sees the same hook state
	 * it would on a clean boot.
	 *
	 * @after
	 */
	#[After]
	public function restore_state(): void {
		\remove_filter(
			'register_block_type_args',
			array( Reactions_Label::class, 'rewrite_block_args' ),
			10
		);
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
	public function test_filter_does_not_invent_missing_description() {
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
	 * Same shape, opposite key: missing-title path is independent from the
	 * missing-description path because the two `isset` guards in the
	 * projector are independent. Without this case a regression that swaps
	 * one guard for `array_key_exists` (or removes one outright) would slip
	 * past the previous test.
	 */
	public function test_filter_does_not_invent_missing_title() {
		$args = array(
			'description' => 'Display Fediverse likes and reposts for your posts.',
			'category'    => 'widgets',
		);

		$result = apply_filters( 'register_block_type_args', $args, 'activitypub/reactions' );

		$this->assertArrayNotHasKey(
			'title',
			$result,
			'Projector must not invent a title key when upstream args omitted it.'
		);
		$this->assertSame( 'Display social likes and reposts for your posts.', $result['description'] );
		$this->assertSame( 'widgets', $result['category'] );
	}

	/**
	 * Both keys absent on the matching block name: projector is a structural
	 * no-op. Guards the contract that the projector overlays existing keys
	 * rather than inventing shape — so a future ActivityPub refactor that
	 * registers `activitypub/reactions` with an empty `$args` array fails
	 * loudly upstream instead of being papered over here.
	 */
	public function test_filter_is_noop_when_both_keys_absent() {
		$args = array(
			'category' => 'widgets',
			'icon'     => 'heart',
		);

		$result = apply_filters( 'register_block_type_args', $args, 'activitypub/reactions' );

		$this->assertSame( $args, $result, 'Both-keys-absent matching block must round-trip unchanged.' );
	}

	/**
	 * The overlaid title and description must go through `__()` with the
	 * `fosse` text domain so non-English admin/editor surfaces get the
	 * translated wording instead of untranslatable English overwriting
	 * AP's localized metadata. Asserting the rendered string alone
	 * (`'Social Reactions'`) wouldn't catch a regression that dropped
	 * the `__()` wrapper, because the wrapped and unwrapped paths
	 * produce identical output in a no-translation test environment.
	 *
	 * Hooks `gettext` to capture the (text, domain) pairs the projector
	 * passes through, then asserts both strings were registered against
	 * the `fosse` domain. The filter is removed in a `finally` so a
	 * later test isn't polluted with the capture.
	 */
	public function test_filter_translates_via_fosse_textdomain() {
		$captured = array();
		$capture  = static function ( $translation, $text, $domain ) use ( &$captured ) {
			$captured[ $text ] = $domain;
			return $translation;
		};

		\add_filter( 'gettext', $capture, 10, 3 );

		try {
			$args = array(
				'title'       => 'Fediverse Reactions',
				'description' => 'Display Fediverse likes and reposts for your posts.',
			);
			\apply_filters( 'register_block_type_args', $args, 'activitypub/reactions' );
		} finally {
			\remove_filter( 'gettext', $capture, 10 );
		}

		$this->assertSame(
			'fosse',
			$captured['Social Reactions'] ?? null,
			'Title overlay must call __() with the fosse text domain.'
		);
		$this->assertSame(
			'fosse',
			$captured['Display social likes and reposts for your posts.'] ?? null,
			'Description overlay must call __() with the fosse text domain.'
		);
	}

	/**
	 * Non-string values for `title` / `description` (e.g. a Stringable
	 * wrapper, a translation deferral object, or upstream accidentally
	 * passing an array) must round-trip untouched. The projector's
	 * `is_string` guard treats this as a structural no-op rather than
	 * silently coercing the wrapper into a plain string. Tests the
	 * "silent no-op" branch the projector docblock explicitly commits
	 * to — without this case a refactor that drops the `is_string`
	 * guard would slip through every other test in this suite, since
	 * they all pass plain strings.
	 */
	public function test_filter_passes_through_non_string_title_unchanged() {
		$stringable = new class() {
			/**
			 * Stand-in for any future Stringable / translation-deferral
			 * wrapper upstream might pass as `$args['title']`.
			 */
			public function __toString(): string {
				return 'Stringable Title';
			}
		};

		$args = array(
			'title'       => $stringable,
			'description' => 'Display Fediverse likes and reposts for your posts.',
			'category'    => 'widgets',
		);

		$result = apply_filters( 'register_block_type_args', $args, 'activitypub/reactions' );

		$this->assertSame(
			$stringable,
			$result['title'],
			'Non-string title must round-trip untouched (silent no-op).'
		);
		$this->assertSame(
			'Display social likes and reposts for your posts.',
			$result['description'],
			'String description on the same matching block must still be overlaid.'
		);
		$this->assertSame( 'widgets', $result['category'] );
	}

	/**
	 * Repeated register() calls leave exactly one callback at priority 10 on
	 * register_block_type_args, registered with `accepted_args=2`. WP's
	 * WP_Hook keys callbacks by unique-id, so identical callable-as-array
	 * registrations overwrite the same slot.
	 *
	 * The `accepted_args=2` assertion is load-bearing: a regression that
	 * drops the `2` makes the second `$name` argument null at every
	 * invocation, and the matching-name guard would silently relabel every
	 * block on the site. Pure behavioral assertions wouldn't catch that
	 * because the existing tests pass `$name` explicitly through
	 * `apply_filters`.
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

		$callbacks = $wp_filter['register_block_type_args']->callbacks[10];
		$this->assertCount(
			1,
			$callbacks,
			'register() must leave exactly one callback at priority 10 when called twice.'
		);

		$this->assertSame(
			2,
			reset( $callbacks )['accepted_args'],
			'Projector must be registered with accepted_args=2 so $name is passed.'
		);
	}
}
