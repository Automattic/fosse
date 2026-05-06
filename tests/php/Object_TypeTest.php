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
		delete_option( \Automattic\Fosse\Canonical_Options_Migrator::MIGRATED_FLAG_OPTION );

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
	 * Pre-migration safety net: when the canonical-options migrator hasn't
	 * marked itself complete, the bridge falls back to the legacy
	 * `fosse_object_type=note` value so a frontend publish during the
	 * narrow window between FOSSE bootstrap and the migrator firing
	 * doesn't ship the wrong shape. Once the flag is set this branch is
	 * unreachable.
	 */
	public function test_atmosphere_filter_falls_back_to_legacy_option_pre_migration(): void {
		update_option( 'fosse_object_type', 'note' );

		$this->assertTrue(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post ),
			'Legacy fosse_object_type=note must still force short-form before the migrator completes.'
		);
	}

	/**
	 * Once the migrator marks itself complete, the legacy option is
	 * ignored even if it's still present in the database (which would
	 * indicate a corrupted migration — defense in depth).
	 */
	public function test_atmosphere_filter_ignores_legacy_option_after_migration(): void {
		update_option( 'fosse_object_type', 'note' );
		update_option( \Automattic\Fosse\Canonical_Options_Migrator::MIGRATED_FLAG_OPTION, '1' );

		$this->assertFalse(
			apply_filters( 'atmosphere_is_short_form_post', false, $this->post ),
			'After migration completes, only activitypub_object_type drives the bridge.'
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
