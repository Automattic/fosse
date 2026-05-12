<?php
/**
 * Tests for Post_Type_Chooser.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Admin;

use Automattic\Fosse\Admin\Post_Type_Chooser;
use WorDBless\BaseTestCase;

/**
 * Pins the chooser's "FOSSE-managed post types" semantics:
 *  - `attachment` is never offered, even though WordPress registers it
 *    as a public type.
 *  - The reconcile pattern preserves any stored value FOSSE doesn't
 *    manage (e.g. `attachment` enabled upstream).
 */
class Post_Type_ChooserTest extends BaseTestCase {

	/**
	 * `attachment` is a public post type, but the chooser hides it from
	 * the wizard / Settings UI to avoid surfacing an option whose actual
	 * behavior (federate every image upload, including ones attached to
	 * drafts) doesn't match what the wizard's label implies.
	 */
	public function test_types_excludes_attachment(): void {
		$names = array_keys( Post_Type_Chooser::types() );

		$this->assertContains( 'post', $names );
		$this->assertContains( 'page', $names );
		$this->assertNotContains( 'attachment', $names );
	}

	/**
	 * `names()` mirrors `types()` minus the `WP_Post_Type` payload, so
	 * the chooser's save path can validate submissions without paying
	 * the `'objects'` cost.
	 */
	public function test_names_matches_types_keys(): void {
		$this->assertSame(
			array_values( array_keys( Post_Type_Chooser::types() ) ),
			array_values( Post_Type_Chooser::names() )
		);
	}

	/**
	 * Adding a runtime-registered public post type surfaces in BOTH
	 * `types()` and `names()` without source-of-truth drift. Locks down
	 * the invariant that the two methods stay in sync even when the
	 * caller adds an exclusion or filter at runtime to `types()`.
	 */
	public function test_names_and_types_stay_in_sync_with_runtime_registration(): void {
		register_post_type(
			'fosse_runtime_cpt',
			array(
				'public' => true,
				'label'  => 'Runtime chooser type',
			)
		);

		try {
			$this->assertContains( 'fosse_runtime_cpt', Post_Type_Chooser::names() );
			$this->assertArrayHasKey( 'fosse_runtime_cpt', Post_Type_Chooser::types() );
			$this->assertSame(
				array_values( array_keys( Post_Type_Chooser::types() ) ),
				array_values( Post_Type_Chooser::names() )
			);
		} finally {
			unregister_post_type( 'fosse_runtime_cpt' );
		}
	}

	/**
	 * The reconcile pattern intersects the submission with the managed
	 * set so a submission missing valid types collapses cleanly to the
	 * managed subset only.
	 */
	public function test_reconcile_intersects_submission_with_managed(): void {
		$result = Post_Type_Chooser::reconcile_submission(
			array( 'post', 'page', 'nonexistent_type' ),
			array()
		);

		sort( $result );
		$this->assertSame( array( 'page', 'post' ), $result );
	}

	/**
	 * A submission can't sneak `attachment` past the chooser even if
	 * someone crafts a POST that includes it. The wizard and Settings
	 * UI deliberately don't render that checkbox; the save path needs
	 * to match that intent.
	 */
	public function test_reconcile_rejects_attachment_in_submission(): void {
		$result = Post_Type_Chooser::reconcile_submission(
			array( 'post', 'attachment' ),
			array()
		);

		$this->assertSame( array( 'post' ), $result );
	}

	/**
	 * If `attachment` is already in the stored option (the user enabled
	 * it via bundled ActivityPub's own settings), a FOSSE save must not
	 * silently strip it. The chooser doesn't manage it, so the reconcile
	 * preserves it.
	 */
	public function test_reconcile_preserves_existing_attachment(): void {
		$result = Post_Type_Chooser::reconcile_submission(
			array( 'post', 'page' ),
			array( 'post', 'attachment' )
		);

		sort( $result );
		$this->assertSame( array( 'attachment', 'page', 'post' ), $result );
	}

	/**
	 * An empty submission with an existing `attachment` value preserves
	 * the upstream choice without re-adding any managed types. Empty-
	 * managed-selection is a UI-layer concern (the wizard re-prompts
	 * the user) — the chooser itself stays mechanical.
	 */
	public function test_reconcile_empty_submission_preserves_only_unmanaged(): void {
		$result = Post_Type_Chooser::reconcile_submission(
			array(),
			array( 'post', 'attachment' )
		);

		$this->assertSame( array( 'attachment' ), $result );
	}

	/**
	 * Duplicates in the submission collapse to a single entry — the
	 * stored option shouldn't grow on every save just because the
	 * form serializer doubled up a value.
	 */
	public function test_reconcile_dedupes(): void {
		$result = Post_Type_Chooser::reconcile_submission(
			array( 'post', 'post', 'page' ),
			array( 'post' )
		);

		sort( $result );
		$this->assertSame( array( 'page', 'post' ), $result );
	}
}
