import { test, expect } from '@playwright/test';

type CapturedRecord = {
	post_id: number;
	collection: string;
	record: {
		text: string;
		embed?: unknown;
		facets?: Array< {
			index: { byteStart: number; byteEnd: number };
			features: Array< { $type: string; [ key: string ]: unknown } >;
		} >;
		[ key: string ]: unknown;
	};
};

test( 'short-form post: tag/mention/link facets captured, no embed', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/post-new.php' );

	// wpApiSettings is localized on editor-ish admin pages where api-fetch is
	// enqueued; we need its nonce to POST to /wp/v2/posts.
	await page.waitForFunction(
		() => !! ( window as any ).wpApiSettings?.nonce
	);

	const body = 'hello #world @alice.test https://example.com';

	const created = await page.evaluate( async ( content ) => {
		const res = await fetch( '/wp-json/wp/v2/posts', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
			},
			body: JSON.stringify( {
				title: '',
				content,
				status: 'publish',
			} ),
		} );
		const text = await res.text();
		return { status: res.status, text };
	}, body );

	expect(
		created.status,
		`POST /posts returned: ${ created.text.slice( 0, 300 ) }`
	).toBe( 201 );
	const postId = ( JSON.parse( created.text ) as { id: number } ).id;

	// The mu-plugin writes uploads/fosse-bsky-capture.json synchronously on the
	// transition_post_status publish transition — so by the time /posts returns
	// 201, the capture should be on disk. Poll briefly for filesystem flush.
	const captureUrl = '/wp-content/uploads/fosse-bsky-capture.json';
	let captured: CapturedRecord | null = null;
	await expect
		.poll(
			async () => {
				const r = await page.request.get( captureUrl );
				if ( ! r.ok() ) {
					return false;
				}
				captured = ( await r.json() ) as CapturedRecord;
				return captured.post_id === postId;
			},
			{ timeout: 5_000, intervals: [ 100, 250, 500 ] }
		)
		.toBe( true );

	const record = captured!.record;

	expect( record.text ).toBe( body );
	expect( record.embed ).toBeUndefined();
	expect( record.facets ).toBeDefined();
	expect( record.facets! ).toHaveLength( 3 );

	const facetOfType = ( type: string ) =>
		record.facets!.find( ( f ) => f.features[ 0 ]?.$type === type );

	const tag = facetOfType( 'app.bsky.richtext.facet#tag' );
	expect( tag, 'tag facet present' ).toBeTruthy();
	expect( tag!.features[ 0 ].tag ).toBe( 'world' );

	const mention = facetOfType( 'app.bsky.richtext.facet#mention' );
	expect( mention, 'mention facet present' ).toBeTruthy();
	expect( mention!.features[ 0 ].did as string ).toMatch( /^did:/ );

	const link = facetOfType( 'app.bsky.richtext.facet#link' );
	expect( link, 'link facet present' ).toBeTruthy();
	expect( link!.features[ 0 ].uri ).toBe( 'https://example.com' );
} );
