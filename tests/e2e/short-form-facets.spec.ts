import { test, expect } from '@playwright/test';
import {
	nonceHeaders,
	resetApplyWritesCapture,
	resetBlueskyState,
	setBlueskyState,
} from './test-helpers';

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

type Write = {
	$type: string;
	collection: string;
	rkey: string;
	value: Record< string, unknown >;
};

type Call = {
	writes: Write[];
	did: string;
};

type CapturedCalls = {
	calls: Call[];
};

test.describe( 'short-form facet capture', () => {
	test.afterAll( async ( { browser }, testInfo ) => {
		const baseURL = testInfo.project.use.baseURL;
		if ( ! baseURL ) {
			throw new Error(
				'baseURL must be configured in playwright.config.ts'
			);
		}
		await resetBlueskyState( browser, baseURL );
	} );

	test( 'short-form post: tag/mention/link facets captured, no embed, plus document record', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php' );

		// wpApiSettings is localized on editor-ish admin pages where api-fetch
		// is enqueued; we need its nonce to POST to /wp/v2/posts.
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

		// Connect Bluesky and reset the capture so only this test's
		// publish populates it. The helper asserts the DELETE succeeded
		// so a silent failure can't let prior runs' stale calls leak
		// into the later "captured.calls" assertions.
		await setBlueskyState( page, { connected: true } );
		await resetApplyWritesCapture( page );

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
		expect( postId ).toBeGreaterThan( 0 );

		// The capture mu-plugin records every applyWrites batch the
		// Publisher emits and runs the publish inline on
		// transition_post_status, so the capture option is populated
		// by the time POST /posts returns. Poll briefly in case the
		// option write hasn't flushed yet.
		const headers = await nonceHeaders( page );
		let captured: CapturedCalls | null = null;
		await expect
			.poll(
				async () => {
					const r = await page.request.get(
						'/wp-json/fosse-e2e/v1/apply-writes',
						{ headers }
					);
					if ( ! r.ok() ) {
						return 0;
					}
					captured = ( await r.json() ) as CapturedCalls;
					return captured.calls.length;
				},
				{ timeout: 5_000, intervals: [ 100, 250, 500 ] }
			)
			.toBeGreaterThanOrEqual( 1 );

		// Short-form path: one applyWrites call, two writes
		// (app.bsky.feed.post + site.standard.document atomically).
		const writes = captured!.calls[ 0 ].writes;
		const bskyWrite = writes.find(
			( w ) => w.collection === 'app.bsky.feed.post'
		);
		expect( bskyWrite, 'app.bsky.feed.post write present' ).toBeTruthy();
		const docWrite = writes.find(
			( w ) => w.collection === 'site.standard.document'
		);
		expect(
			docWrite,
			'site.standard.document write present (DOTCOM-16809 guard)'
		).toBeTruthy();

		const bsky = bskyWrite!.value as BskyRecord;

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

		// site.standard.document record: must still be written on the
		// short-form path (DOTCOM-16809). Publisher::publish atomically
		// writes both records; this guards against a future regression
		// if the document path ever grows a post-format sensitivity.
		const doc = docWrite!.value as DocRecord;
		expect( doc.$type ).toBe( 'site.standard.document' );
		expect( doc.publishedAt as string ).toMatch( /^\d{4}-\d{2}-\d{2}T/ );
	} );
} );
