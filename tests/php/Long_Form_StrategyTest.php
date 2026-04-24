<?php
/**
 * Tests for the cross-network Long_Form_Strategy projector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Long_Form_Strategy;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;
use WP_Post;

/**
 * Verifies the FOSSE option-backed projector translates the
 * fosse_long_form_strategy option into the Atmosphere long-form
 * composition filter (atmosphere_long_form_composition).
 *
 * Unlike Object_Type's pass-through, Long_Form_Strategy coerces
 * unset/unknown values to the FOSSE default ('teaser-thread'). The
 * tests lock that opinionation in — a future refactor that silently
 * flipped the projector to pass-through would regress the whole
 * "install FOSSE, get threads" story.
 */
class Long_Form_StrategyTest extends BaseTestCase {

	/**
	 * Stub post used to satisfy filter signatures; callbacks don't read it.
	 *
	 * @var WP_Post
	 */
	private WP_Post $post;

	/**
	 * Clean hook state + option + seed a WP_Post fixture before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		remove_all_filters( 'atmosphere_long_form_composition' );
		delete_option( 'fosse_long_form_strategy' );

		Long_Form_Strategy::register();

		$this->post = new WP_Post( (object) array( 'ID' => 1 ) );
	}

	/**
	 * No option set: filter returns the FOSSE default, regardless of the
	 * upstream-computed default. This is the "install FOSSE, get threads"
	 * behavior — the single most important invariant of the projector.
	 */
	public function test_unset_option_returns_teaser_thread() {
		$this->assertSame(
			'teaser-thread',
			apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->post )
		);
	}

	/**
	 * Explicit 'teaser-thread' is returned as-is.
	 */
	public function test_teaser_thread_option_returns_teaser_thread() {
		update_option( 'fosse_long_form_strategy', 'teaser-thread' );

		$this->assertSame(
			'teaser-thread',
			apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->post )
		);
	}

	/**
	 * Explicit 'truncate-link' is returned as-is.
	 */
	public function test_truncate_link_option_returns_truncate_link() {
		update_option( 'fosse_long_form_strategy', 'truncate-link' );

		$this->assertSame(
			'truncate-link',
			apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->post )
		);
	}

	/**
	 * Explicit 'link-card' is returned as-is — opt-out of FOSSE's thread
	 * default while keeping FOSSE installed.
	 */
	public function test_link_card_option_returns_link_card() {
		update_option( 'fosse_long_form_strategy', 'link-card' );

		$this->assertSame(
			'link-card',
			apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->post )
		);
	}

	/**
	 * Explicit 'document-card' is returned as-is. The v2 renderer doesn't
	 * exist yet, but the projector is forward-compatible — Atmosphere falls
	 * back to 'link-card' on unknown strategies on its side, so picking
	 * 'document-card' today renders as a link-card and Just Works when the
	 * upstream renderer lands.
	 */
	public function test_document_card_option_returns_document_card() {
		update_option( 'fosse_long_form_strategy', 'document-card' );

		$this->assertSame(
			'document-card',
			apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->post )
		);
	}

	/**
	 * Unknown option values (typos, legacy values, garbage) coerce to the
	 * FOSSE default. Guards that invariant against a future refactor to
	 * pass-through semantics — FOSSE's opinion is intentional, not a bug.
	 */
	public function test_unknown_option_coerces_to_teaser_thread() {
		update_option( 'fosse_long_form_strategy', 'nonsense' );

		$this->assertSame(
			'teaser-thread',
			apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->post ),
			'Unknown option values must coerce to the FOSSE default, not pass through.'
		);
	}

	/**
	 * Empty-string option coerces to the FOSSE default.
	 */
	public function test_empty_option_coerces_to_teaser_thread() {
		update_option( 'fosse_long_form_strategy', '' );

		$this->assertSame(
			'teaser-thread',
			apply_filters( 'atmosphere_long_form_composition', 'link-card', $this->post )
		);
	}

	/**
	 * The projector overrides the upstream-computed default even for
	 * recognized upstream strategies. Callers registering a higher-priority
	 * filter with a different default value still see FOSSE's opinion
	 * applied afterwards; this locks that call-order contract in.
	 */
	public function test_projector_overrides_upstream_default() {
		$this->assertSame(
			'teaser-thread',
			apply_filters( 'atmosphere_long_form_composition', 'truncate-link', $this->post )
		);
	}
}
