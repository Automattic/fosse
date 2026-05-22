import { test, expect, type Page } from '@playwright/test';
import { resetBlueskyState, setBlueskyState } from './test-helpers';

type BskyRecord = {
	$type?: string;
	text: string;
	embed?: {
		$type?: string;
		external?: { uri?: string; title?: string };
		[ key: string ]: unknown;
	};
	reply?: {
		root: { uri: string; cid: string };
		parent: { uri: string; cid: string };
	};
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

type ThreadTriple = { uri: string; cid: string; tid: string };

type PostMetaResponse = {
	post_id: number;
	meta: Record< string, unknown >;
};

const nonceHeaders = async ( page: Page ) => ( {
	'Content-Type': 'application/json',
	'X-WP-Nonce': await page.evaluate(
		() => ( window as any ).wpApiSettings.nonce
	),
} );

/**
 * Exercise the long-form `teaser-thread` strategy end-to-end.
 *
 * Setup pins `activitypub_object_type` to `wordpress-post-format` (the
 * Object_Type bridge's pass-through branch) and
 * `atmosphere_long_form_composition` to `teaser-thread`. With a titled
 * post that has a curated excerpt of ≥ 10 chars plus a body of ≥ 10
 * chars, `Transformer\Post::compute_default_teaser_thread()` returns
 * `[ excerpt-hook, body-chunk, cta ]` — three entries, no redundancy
 * collapse (the collapse requires no excerpt). The Publisher then
 * issues:
 *
 *   1. root + doc atomically (2 writes in one applyWrites call)
 *   2. reply 1 = body-chunk (1 write, reply.root + reply.parent → root)
 *   3. reply 2 = CTA       (1 write, reply.root → root, reply.parent → reply 1)
 *
 * Sizing the post is deliberate: a body whose plain-text length is in
 * `[10, 280]` chars and a separate excerpt keeps `compute_default_teaser_thread`
 * in the 3-entry branch. The redundancy collapse would only trigger
 * with NO excerpt and a body ≤ 280 chars, so the explicit excerpt
 * here also defends against future tweaks to the body-as-hook path.
 */
test.describe( 'long-form teaser-thread path', () => {
	test.afterAll( async ( { browser }, testInfo ) => {
		const baseURL = testInfo.project.use.baseURL;
		if ( ! baseURL ) {
			throw new Error(
				'baseURL must be configured in playwright.config.ts'
			);
		}
		await resetBlueskyState( browser, baseURL );
	} );

	test( 'teaser-thread: three applyWrites calls with chained reply refs', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php' );

		await page.waitForFunction(
			() => !! ( window as any ).wpApiSettings?.nonce
		);

		// Flip activitypub_object_type to pass-through mode (so the
		// Object_Type bridge defers to Atmosphere's own short-form
		// detection — a titled post is long-form) and pin the long-
		// form composition to 'teaser-thread' so this spec is robust
		// even if FOSSE's default ever changes.
		const flipResult = await page.evaluate( async () => {
			const headers = {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
			};
			const otype = await fetch( '/wp-json/fosse-e2e/v1/object-type', {
				method: 'POST',
				headers,
				body: JSON.stringify( {
					value: 'wordpress-post-format',
				} ),
			} );
			const strategy = await fetch(
				'/wp-json/fosse-e2e/v1/long-form-strategy',
				{
					method: 'POST',
					headers,
					body: JSON.stringify( { value: 'teaser-thread' } ),
				}
			);
			return {
				otypeStatus: otype.status,
				otypeText: await otype.text(),
				strategyStatus: strategy.status,
				strategyText: await strategy.text(),
			};
		} );
		expect(
			flipResult.otypeStatus,
			`object-type returned: ${ flipResult.otypeText.slice( 0, 300 ) }`
		).toBe( 200 );
		expect(
			flipResult.strategyStatus,
			`long-form-strategy returned: ${ flipResult.strategyText.slice(
				0,
				300
			) }`
		).toBe( 200 );

		// Connect Bluesky and reset the capture so only this test's
		// publish populates it.
		await setBlueskyState( page, { connected: true } );
		await page.evaluate( async () => {
			await fetch( '/wp-json/fosse-e2e/v1/apply-writes', {
				method: 'DELETE',
				headers: {
					'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
				},
			} );
		} );

		const postTitle = 'Long-form post composed as a teaser thread';
		// Curated excerpt drives the hook (≥ 10 chars triggers the
		// excerpt branch of compute_default_teaser_thread).
		const postExcerpt =
			'A curated excerpt that becomes the teaser-thread hook so callers can preview the prose before the body chunk arrives.';
		// Body is short enough to fit cleanly in a single chunk reply
		// (well under 280 chars). With an excerpt-as-hook,
		// chunk_source == the whole body; ≥ 10 chars keeps us in the
		// 3-entry [ hook, body_chunk, cta ] branch.
		const postBody =
			'The body of the post continues where the excerpt leaves off. A short paragraph of prose is enough to land in the body-chunk reply of the teaser thread.';

		const created = await page.evaluate(
			async ( { title, content, excerpt } ) => {
				const res = await fetch( '/wp-json/wp/v2/posts', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
					},
					body: JSON.stringify( {
						title,
						content,
						excerpt,
						status: 'publish',
					} ),
				} );
				return { status: res.status, text: await res.text() };
			},
			{ title: postTitle, content: postBody, excerpt: postExcerpt }
		);

		expect(
			created.status,
			`POST /posts returned: ${ created.text.slice( 0, 300 ) }`
		).toBe( 201 );
		const postId = ( JSON.parse( created.text ) as { id: number } ).id;
		expect( postId ).toBeGreaterThan( 0 );

		const headers = await nonceHeaders( page );

		// Poll for all three applyWrites calls to be recorded. The
		// capture mu-plugin runs Atmosphere's scheduled publish
		// inline on transition_post_status, so all three batches
		// should be on disk by the time POST /posts returns; the poll
		// guards against option-write flush latency.
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
			.toBe( 3 );

		const calls = captured!.calls;

		// Call 1: root post + doc atomically.
		const rootCall = calls[ 0 ];
		expect( rootCall.writes ).toHaveLength( 2 );
		const rootBskyWrite = rootCall.writes.find(
			( w ) => w.collection === 'app.bsky.feed.post'
		);
		const rootDocWrite = rootCall.writes.find(
			( w ) => w.collection === 'site.standard.document'
		);
		expect(
			rootBskyWrite,
			'root applyWrites batch includes the post'
		).toBeTruthy();
		expect(
			rootDocWrite,
			'root applyWrites batch includes the document'
		).toBeTruthy();

		const rootRecord = rootBskyWrite!.value as BskyRecord;
		expect( rootRecord.$type ).toBe( 'app.bsky.feed.post' );
		expect( rootRecord.reply, 'root has no reply ref' ).toBeUndefined();
		// The hook should be derived from the excerpt; we don't
		// assert the exact text (sanitize_text + truncate_to_budget
		// transformations are upstream's contract) — just that the
		// excerpt prose made it through.
		expect( rootRecord.text ).toContain( 'curated excerpt' );

		// Synthetic URI/CID the capture helper handed back to
		// Publisher; the post's _atmosphere_bsky_uri meta should
		// mirror it.
		const synthRootUri = `at://${ rootCall.did }/app.bsky.feed.post/${
			rootBskyWrite!.rkey
		}`;

		// Call 2: body-chunk reply, chained to the root.
		const chunkCall = calls[ 1 ];
		expect( chunkCall.writes ).toHaveLength( 1 );
		const chunkWrite = chunkCall.writes[ 0 ];
		expect( chunkWrite.collection ).toBe( 'app.bsky.feed.post' );
		const chunkRecord = chunkWrite.value as BskyRecord;
		expect( chunkRecord.reply, 'body-chunk has reply refs' ).toBeDefined();
		expect( chunkRecord.reply!.root.uri ).toBe( synthRootUri );
		expect( chunkRecord.reply!.parent.uri ).toBe( synthRootUri );

		// Call 3: CTA reply, chained root→root, parent→body-chunk.
		const ctaCall = calls[ 2 ];
		expect( ctaCall.writes ).toHaveLength( 1 );
		const ctaWrite = ctaCall.writes[ 0 ];
		expect( ctaWrite.collection ).toBe( 'app.bsky.feed.post' );
		const ctaRecord = ctaWrite.value as BskyRecord;
		expect( ctaRecord.reply, 'CTA has reply refs' ).toBeDefined();
		expect( ctaRecord.reply!.root.uri ).toBe( synthRootUri );
		expect( ctaRecord.reply!.parent.uri ).toBe(
			`at://${ ctaCall.did }/app.bsky.feed.post/${ chunkWrite.rkey }`
		);
		// CTA text carries the localized "Continue reading: <permalink>"
		// form. The exact prefix is i18n-sensitive; assert on the
		// permalink substring instead.
		expect( ctaRecord.text ).toMatch( /https?:\/\// );
		// CTA carries the terminal link-card embed.
		expect( ctaRecord.embed ).toBeDefined();
		expect( ctaRecord.embed!.$type ).toBe( 'app.bsky.embed.external' );

		// Post-meta side effects: META_THREAD_RECORDS mirrors the
		// three published thread entries in order, and META_URI
		// points at the root.
		const metaRes = await page.request.get(
			`/wp-json/fosse-e2e/v1/post-meta?post_id=${ postId }&keys=_atmosphere_bsky_thread_records,_atmosphere_bsky_uri`,
			{ headers }
		);
		expect( metaRes.ok() ).toBe( true );
		const metaJson = ( await metaRes.json() ) as PostMetaResponse;
		const threadRecords = metaJson.meta
			._atmosphere_bsky_thread_records as ThreadTriple[];
		expect( Array.isArray( threadRecords ) ).toBe( true );
		expect( threadRecords ).toHaveLength( 3 );
		expect( threadRecords[ 0 ].uri ).toBe( synthRootUri );
		expect( threadRecords[ 0 ].tid ).toBe( rootBskyWrite!.rkey );
		expect( threadRecords[ 1 ].tid ).toBe( chunkWrite.rkey );
		expect( threadRecords[ 2 ].tid ).toBe( ctaWrite.rkey );
		for ( const triple of threadRecords ) {
			expect( triple.cid ).toMatch( /^bafyrei/ );
		}

		expect( metaJson.meta._atmosphere_bsky_uri ).toBe( synthRootUri );
	} );
} );
