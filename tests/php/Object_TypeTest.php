<?php
/**
 * Tests for the cross-network Object_Type projector.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Object_Type;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;
use WP_Post;

/**
 * Verifies the FOSSE option-backed projector correctly translates the
 * fosse_object_type option into the Atmosphere short-form discriminator
 * (atmosphere_is_short_form_post) and the ActivityPub object type
 * (activitypub_post_object_type).
 */
class Object_TypeTest extends BaseTestCase {

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
		remove_all_filters( 'atmosphere_is_short_form_post' );
		remove_all_filters( 'activitypub_post_object_type' );
		delete_option( 'fosse_object_type' );

		Object_Type::register();

		$this->post = new WP_Post( (object) array( 'ID' => 1 ) );
	}

	/**
	 * With no option set, the Atmosphere filter returns its input unchanged.
	 */
	public function test_atmosphere_filter_passes_through_by_default() {
		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post )
		);
	}

	/**
	 * Option=note forces short-form regardless of the incoming default.
	 */
	public function test_atmosphere_filter_forces_short_form_when_option_note() {
		update_option( 'fosse_object_type', 'note' );

		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post ),
			'A false default must become true when the option is note.'
		);
	}

	/**
	 * Option=wordpress-post-format is a pass-through (defer to upstream).
	 */
	public function test_atmosphere_filter_passes_through_when_option_wordpress_post_format() {
		update_option( 'fosse_object_type', 'wordpress-post-format' );

		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post )
		);
		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', true, $this->post )
		);
	}

	/**
	 * With no option set, the AP filter returns its input unchanged.
	 */
	public function test_ap_filter_passes_through_by_default() {
		$this->assertSame(
			'Article',
			apply_filters( 'activitypub_post_object_type', 'Article', $this->post )
		);
	}

	/**
	 * Option=note forces 'Note' regardless of the upstream-computed type.
	 */
	public function test_ap_filter_forces_note_when_option_note() {
		update_option( 'fosse_object_type', 'note' );

		$this->assertSame(
			'Note',
			apply_filters( 'activitypub_post_object_type', 'Article', $this->post )
		);
	}

	/**
	 * Option=wordpress-post-format is a pass-through (defer to upstream).
	 */
	public function test_ap_filter_passes_through_when_option_wordpress_post_format() {
		update_option( 'fosse_object_type', 'wordpress-post-format' );

		$this->assertSame(
			'Article',
			apply_filters( 'activitypub_post_object_type', 'Article', $this->post )
		);
	}
}
