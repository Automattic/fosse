import { test, expect } from '@playwright/test';

type BskyRecord = {
	$type?: string;
	text: string;
	embed?: {
		$type?: string;
		external?: { uri?: string; title?: string };
		[ key: string ]: unknown;
	};
	facets?: unknown[];
	[ key: string ]: unknown;
};

type Capture = {
	post_id: number;
	bsky_record: BskyRecord;
	doc_record: { $type: string; [ key: string ]: unknown };
};

/**
 * Complements short-form-facets.spec.ts by exercising the *pass-through*
 * branch of the Object_Type bridge. When activitypub_object_type is set
 * to 'wordpress-post-format', the bridge returns its input unchanged, so
 * Atmosphere's native is_short_form() classification drives the composition.
 * A titled post with no post format is the classic long-form case:
 * Atmosphere builds a teaser + app.bsky.embed.external link card.
 *
 * This spec proves that the bridge doesn't accidentally force
 * short-form on the pass-through path, which would silently break the
 * long-form composition for existing Atmosphere users.
 */
test( 'pass-through mode: titled post still takes the long-form link-card path', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/post-new.php' );

	await page.waitForFunction(
		() => !! ( window as any ).wpApiSettings?.nonce
	);

	// Flip activitypub_object_type to pass-through mode. Blueprint seeds
	// 'note'; this test wants the bridge to defer to Atmosphere's own
	// detection.
	const flipResult = await page.evaluate( async () => {
		const res = await fetch( '/wp-json/fosse-e2e/v1/object-type', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
			},
			body: JSON.stringify( { value: 'wordpress-post-format' } ),
		} );
		return { status: res.status, text: await res.text() };
	} );
	expect(
		flipResult.status,
		`fosse-e2e/v1/object-type returned: ${ flipResult.text.slice(
			0,
			300
		) }`
	).toBe( 200 );

	const postTitle = 'A long-form post that should become a link card';
	const body = 'This is the full body content of a long-form blog post.';

	const created = await page.evaluate(
		async ( { title, content } ) => {
			const res = await fetch( '/wp-json/wp/v2/posts', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
				},
				body: JSON.stringify( {
					title,
					content,
					status: 'publish',
				} ),
			} );
			return { status: res.status, text: await res.text() };
		},
		{ title: postTitle, content: body }
	);

	expect(
		created.status,
		`POST /posts returned: ${ created.text.slice( 0, 300 ) }`
	).toBe( 201 );
	const postId = ( JSON.parse( created.text ) as { id: number } ).id;

	const captureUrl = '/wp-content/uploads/fosse-bsky-capture.json';
	let captured: Capture | null = null;
	await expect
		.poll(
			async () => {
				const r = await page.request.get( captureUrl );
				if ( ! r.ok() ) {
					return false;
				}
				captured = ( await r.json() ) as Capture;
				return captured.post_id === postId;
			},
			{ timeout: 5_000, intervals: [ 100, 250, 500 ] }
		)
		.toBe( true );

	const bsky = captured!.bsky_record;

	// Long-form path: external embed card with the permalink.
	expect( bsky.embed ).toBeDefined();
	expect( bsky.embed!.$type ).toBe( 'app.bsky.embed.external' );
	expect( bsky.embed!.external?.uri ).toMatch( /^https?:\/\// );

	// Text should contain the title — Atmosphere's long-form build_text()
	// composes title + excerpt + permalink. We don't assert exact composition
	// (that's upstream's concern); we just prove the title made it through,
	// which short-form composition would have dropped.
	expect( bsky.text ).toContain( postTitle );

	// Document record still written on the long-form path (DOTCOM-16809 guard).
	expect( captured!.doc_record.$type ).toBe( 'site.standard.document' );
} );
