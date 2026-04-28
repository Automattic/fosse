import { test, expect } from '@playwright/test';

type SeedResponse = {
	ok: boolean;
	post_id: number;
	post_url: string;
	comment_ids: number[];
};

type BlockTypeResponse = {
	name: string;
	title: string;
	description: string;
	[ key: string ]: unknown;
};

test( 'unified reactions: AP + Bluesky rows render in the same block; inserter title is relabeled', async ( {
	page,
} ) => {
	// 1) Hit an admin page so wpApiSettings.nonce is available for REST calls.
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction(
		() => !! ( window as any ).wpApiSettings?.nonce
	);

	// 2) Seed a published post + three pre-approved reaction comments
	//    (one AP-like, one AT-like, one AT-repost) via the test mu-plugin.
	const seed = await page.evaluate( async () => {
		const res = await fetch( '/wp-json/fosse-e2e/v1/seed-reactions', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
			},
		} );
		const text = await res.text();
		return { status: res.status, text };
	} );
	expect(
		seed.status,
		`seed-reactions returned: ${ seed.text.slice( 0, 300 ) }`
	).toBe( 200 );
	const seedBody = JSON.parse( seed.text ) as SeedResponse;
	expect( seedBody.ok ).toBe( true );
	expect( seedBody.comment_ids.length ).toBe( 3 );

	// 3) Confirm the FOSSE relabel reached the registered block metadata
	//    while we still have the authenticated admin nonce in scope.
	//    /wp/v2/block-types/{namespace}/{name} requires auth.
	const blockType = await page.evaluate( async () => {
		const res = await fetch(
			'/wp-json/wp/v2/block-types/activitypub/reactions',
			{
				headers: {
					'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
				},
			}
		);
		const text = await res.text();
		return { status: res.status, text };
	} );
	expect(
		blockType.status,
		`block-types returned: ${ blockType.text.slice( 0, 300 ) }`
	).toBe( 200 );
	const blockBody = JSON.parse( blockType.text ) as BlockTypeResponse;
	expect( blockBody.title ).toBe( 'Social Reactions' );
	expect( blockBody.description ).toBe(
		'Display social likes and reposts for your posts.'
	);

	// 4) Visit the seeded post on the frontend and assert the reactions
	//    block aggregates both protocols' rows.
	await page.goto( seedBody.post_url );

	// The block's outer wrapper carries the Interactivity-API attributes;
	// .activitypub-reactions is the inner container holding the groups.
	const blockWrapper = page
		.locator( '[data-wp-interactive="activitypub/reactions"]' )
		.first();
	await expect( blockWrapper ).toBeVisible();

	const reactionsInner = blockWrapper.locator( '.activitypub-reactions' );
	await expect( reactionsInner ).toBeVisible();

	// Likes group: AP like (Alice via Mastodon) + AT like (Bob via Bluesky) = 2.
	const likeGroup = reactionsInner.locator(
		'.reaction-group[data-reaction-type="like"]'
	);
	await expect( likeGroup ).toBeVisible();
	await expect( likeGroup ).toContainText( '2' );

	// Reposts group: AT repost only (Carol via Bluesky) = 1.
	const repostGroup = reactionsInner.locator(
		'.reaction-group[data-reaction-type="repost"]'
	);
	await expect( repostGroup ).toBeVisible();
	await expect( repostGroup ).toContainText( '1' );

	// 5) Confirm both protocols' author names made it into the block's
	//    Interactivity-API context (this is how the avatar list is hydrated
	//    client-side). Asserting on the JSON proves the rows were both
	//    picked up by AP's protocol-agnostic get_comments() query.
	const contextAttr = await blockWrapper.getAttribute( 'data-wp-context' );
	expect(
		contextAttr,
		'reactions block must expose data-wp-context with hydrated reaction items'
	).not.toBeNull();
	const ctx = JSON.parse( contextAttr as string );
	const likeNames = ( ctx.reactions?.like?.items ?? [] ).map(
		( item: { name: string } ) => item.name
	);
	expect( likeNames ).toContain( 'Alice via Mastodon' );
	expect( likeNames ).toContain( 'Bob via Bluesky' );
	const repostNames = ( ctx.reactions?.repost?.items ?? [] ).map(
		( item: { name: string } ) => item.name
	);
	expect( repostNames ).toContain( 'Carol via Bluesky' );
} );
