<?php
/**
 * Tests for the cross-network Object_Type bridge.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests;

use Automattic\Fosse\Object_Type;
use PHPUnit\Framework\Attributes\Before;
use WorDBless\BaseTestCase;
use WP_Post;

/**
 * Verifies the bridge translates the canonical `activitypub_object_type`
 * option into the Atmosphere short-form discriminator
 * (`atmosphere_is_short_form_post`). The AP object-type filter is no
 * longer projected by FOSSE — ActivityPub reads its own option directly
 * (see `sdd/canonical-upstream-options/`).
 */
class Object_TypeTest extends BaseTestCase {

	/**
	 * Stub post used to satisfy filter signatures; callbacks don't read it.
	 *
	 * @var WP_Post
	 */
	private WP_Post $post;

	/**
	 * Clean hook state + options + seed a WP_Post fixture before each test.
	 *
	 * @before
	 */
	#[Before]
	public function reset_state(): void {
		remove_all_filters( 'atmosphere_is_short_form_post' );
		remove_all_filters( 'activitypub_post_object_type' );
		delete_option( 'activitypub_object_type' );
		delete_option( 'fosse_object_type' );

		Object_Type::register();

		$this->post = new WP_Post( (object) array( 'ID' => 1 ) );
	}

	/**
	 * With no AP option set, the Atmosphere filter returns its input unchanged.
	 */
	public function test_atmosphere_filter_passes_through_by_default() {
		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post )
		);
	}

	/**
	 * AP option=note forces short-form regardless of the incoming default.
	 */
	public function test_atmosphere_filter_forces_short_form_when_ap_option_note() {
		update_option( 'activitypub_object_type', 'note' );

		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post ),
			'A false default must become true when activitypub_object_type=note.'
		);
	}

	/**
	 * AP option=wordpress-post-format is a pass-through (defer to upstream).
	 */
	public function test_atmosphere_filter_passes_through_when_ap_option_wordpress_post_format() {
		update_option( 'activitypub_object_type', 'wordpress-post-format' );

		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post )
		);
		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', true, $this->post )
		);
	}

	/**
	 * Unknown AP option values (typos, legacy values, garbage) pass through.
	 *
	 * The bridge strictly matches `'note'`; anything else is treated as the
	 * pass-through default. Guards against a future refactor to
	 * `!== 'wordpress-post-format'`-style logic that would silently flip
	 * every unrecognized value to force-Note.
	 */
	public function test_atmosphere_filter_passes_through_on_unknown_ap_option_value() {
		update_option( 'activitypub_object_type', 'garbage' );

		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post ),
			'Unknown option value must not force short-form.'
		);
		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', true, $this->post ),
			'Unknown option value must not flip true inputs to false.'
		);
	}

	/**
	 * Legacy `fosse_object_type=note` is no longer read by the bridge —
	 * the Canonical_Options_Migrator moves the value into
	 * `activitypub_object_type` on `admin_init`. Guards against a future
	 * refactor that quietly resurrects the FOSSE-side option.
	 */
	public function test_atmosphere_filter_ignores_legacy_fosse_option(): void {
		update_option( 'fosse_object_type', 'note' );

		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post ),
			'fosse_object_type is no longer authoritative; only activitypub_object_type drives the bridge.'
		);
	}

	/**
	 * The AP-side filter is no longer registered by FOSSE. ActivityPub reads
	 * `activitypub_object_type` directly when computing object type, and
	 * FOSSE registering its own callback would only re-create the desync the
	 * canonicalization eliminated.
	 */
	public function test_ap_object_type_filter_is_not_registered(): void {
		$this->assertFalse(
			has_filter( 'activitypub_post_object_type' ),
			'FOSSE must not register a callback on activitypub_post_object_type after canonicalization.'
		);
	}
}
