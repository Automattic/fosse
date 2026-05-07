import { test, expect } from '@playwright/test';

type BskyRecord = {
	$type?: string;
	text: string;
	embed?: unknown;
	facets?: Array< {
		index: { byteStart: number; byteEnd: number };
		features: Array< { $type: string; [ key: string ]: unknown } >;
	} >;
	[ key: string ]: unknown;
};

type DocRecord = {
	$type: string;
	title?: string;
	publishedAt?: string;
	[ key: string ]: unknown;
};

type Capture = {
	post_id: number;
	bsky_record: BskyRecord;
	doc_record: DocRecord;
};

test( 'short-form post: tag/mention/link facets captured, no embed, plus document record', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/post-new.php' );

	// wpApiSettings is localized on editor-ish admin pages where api-fetch is
	// enqueued; we need its nonce to POST to /wp/v2/posts.
	await page.waitForFunction(
		() => !! ( window as any ).wpApiSettings?.nonce
	);

	// Set activitypub_object_type=note explicitly so this spec is robust
	// to run order — long-form-link-card.spec.ts flips the canonical
	// option to 'wordpress-post-format'; without this reset, order-
	// dependence would break one of the two tests.
	const flipResult = await page.evaluate( async () => {
		const res = await fetch( '/wp-json/fosse-e2e/v1/object-type', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
			},
			body: JSON.stringify( { value: 'note' } ),
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

	// app.bsky.feed.post record: short-form composition + facet parity.
	const bsky = captured!.bsky_record;

	expect( bsky.text ).toBe( body );
	expect( bsky.embed ).toBeUndefined();
	expect( bsky.facets ).toBeDefined();
	expect( bsky.facets! ).toHaveLength( 3 );

	const facetOfType = ( type: string ) =>
		bsky.facets!.find( ( f ) => f.features[ 0 ]?.$type === type );

	const tag = facetOfType( 'app.bsky.richtext.facet#tag' );
	expect( tag, 'tag facet present' ).toBeTruthy();
	expect( tag!.features[ 0 ].tag ).toBe( 'world' );

	const mention = facetOfType( 'app.bsky.richtext.facet#mention' );
	expect( mention, 'mention facet present' ).toBeTruthy();
	expect( mention!.features[ 0 ].did as string ).toMatch( /^did:/ );

	const link = facetOfType( 'app.bsky.richtext.facet#link' );
	expect( link, 'link facet present' ).toBeTruthy();
	expect( link!.features[ 0 ].uri ).toBe( 'https://example.com' );

	// site.standard.document record: must still be written on the short-form
	// path (DOTCOM-16809). Publisher::publish atomically writes both records;
	// this guards against a future regression if the document path ever grows
	// a post-format sensitivity.
	const doc = captured!.doc_record;
	expect( doc.$type ).toBe( 'site.standard.document' );
	expect( doc.publishedAt as string ).toMatch( /^\d{4}-\d{2}-\d{2}T/ );
} );
