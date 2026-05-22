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
	embed?: {
		$type?: string;
		external?: { uri?: string; title?: string };
		[ key: string ]: unknown;
	};
	facets?: unknown[];
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

/**
 * Complements short-form-facets.spec.ts by exercising the *pass-through*
 * branch of the Object_Type bridge. When activitypub_object_type is set
 * to 'wordpress-post-format', the bridge returns its input unchanged, so
 * Atmosphere's native is_short_form() classification drives the composition.
 * A titled post with no post format is the classic long-form case; with
 * `atmosphere_long_form_composition` pinned to `link-card`, Atmosphere
 * builds a teaser + app.bsky.embed.external link card.
 *
 * This spec proves that the bridge doesn't accidentally force
 * short-form on the pass-through path, which would silently break the
 * long-form composition for existing Atmosphere users.
 */
test.describe( 'pass-through long-form link-card path', () => {
	test.afterAll( async ( { browser }, testInfo ) => {
		const baseURL = testInfo.project.use.baseURL;
		if ( ! baseURL ) {
			throw new Error(
				'baseURL must be configured in playwright.config.ts'
			);
		}
		await resetBlueskyState( browser, baseURL );
	} );

	test( 'pass-through mode: titled post still takes the long-form link-card path', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php' );

		await page.waitForFunction(
			() => !! ( window as any ).wpApiSettings?.nonce
		);

		// Flip activitypub_object_type to pass-through mode AND pin the
		// long-form composition to 'link-card' so this spec stays focused
		// on the bridge's pass-through path even though FOSSE's canonical-
		// options migrator seeds 'teaser-thread' as the default.
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
					body: JSON.stringify( { value: 'link-card' } ),
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
		// publish populates it. The helper asserts the DELETE succeeded
		// so a silent failure can't let prior runs' stale calls leak
		// into the later "captured.calls" assertions.
		await setBlueskyState( page, { connected: true } );
		await resetApplyWritesCapture( page );

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
		expect( postId ).toBeGreaterThan( 0 );

		// The capture mu-plugin records every applyWrites batch the
		// Publisher emits and runs the publish inline on
		// transition_post_status (so we don't need to wait on
		// Playground's cron loop). Poll briefly in case the REST
		// request returns before the option write has flushed. The
		// link-card path publishes via `publish_single`, which emits
		// exactly one batch — asserting the count is `1` (not `>= 1`)
		// catches future regressions where the Publisher accidentally
		// fans out to extra batches on the pass-through long-form path.
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
			.toBe( 1 );

		// link-card path: one applyWrites call, two writes
		// (app.bsky.feed.post root + site.standard.document).
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

		// Long-form path: external embed card with the permalink.
		expect( bsky.embed ).toBeDefined();
		expect( bsky.embed!.$type ).toBe( 'app.bsky.embed.external' );
		expect( bsky.embed!.external?.uri ).toMatch( /^https?:\/\// );

		// Text should contain the title — Atmosphere's long-form
		// build_text() composes title + excerpt + permalink. We don't
		// assert exact composition (that's upstream's concern); we
		// just prove the title made it through, which short-form
		// composition would have dropped.
		expect( bsky.text ).toContain( postTitle );

		// Document record still written on the long-form path
		// (DOTCOM-16809 guard).
		const doc = docWrite!.value as { $type: string };
		expect( doc.$type ).toBe( 'site.standard.document' );
	} );
} );
