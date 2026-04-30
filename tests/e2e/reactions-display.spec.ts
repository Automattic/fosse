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

	// 3) Confirm the FOSSE relabel reached the registered block metadata.
	//    /wp/v2/block-types/{namespace}/{name} reads the same registered
	//    args the inserter reads, so it's a faithful proxy for the
	//    inserter UI title/description without booting the editor.
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
	//    block aggregates both protocols' rows. The seeded post embeds
	//    the activitypub/reactions block explicitly with a distinctive
	//    `fosse-e2e-seeded` className so we can scope to *that* wrapper
	//    rather than picking up the second wrapper AP's blockHooks
	//    auto-injects after `core/post-content`. Without the scope, a
	//    regression in the explicit-embed render path would silently
	//    pass against the auto-injected one.
	await page.goto( seedBody.post_url );

	const blockWrapper = page.locator(
		'[data-wp-interactive="activitypub/reactions"].fosse-e2e-seeded'
	);
	await expect( blockWrapper ).toHaveCount( 1 );
	await expect( blockWrapper ).toBeVisible();

	const reactionsInner = blockWrapper.locator( '.activitypub-reactions' );
	await expect( reactionsInner ).toBeVisible();

	// Likes group: AP like (Alice via Mastodon) + AT like (Bob via Bluesky) = 2.
	// Anchored to the .reaction-label element specifically, with a
	// word-boundary regex so a future "12" or "20" total can't silently
	// satisfy a "contains 2" assertion.
	const likeGroup = reactionsInner.locator(
		'.reaction-group[data-reaction-type="like"]'
	);
	await expect( likeGroup ).toBeVisible();
	await expect( likeGroup.locator( '.reaction-label' ) ).toHaveText(
		/^\s*2\b/
	);

	// Reposts group: AT repost only (Carol via Bluesky) = 1.
	const repostGroup = reactionsInner.locator(
		'.reaction-group[data-reaction-type="repost"]'
	);
	await expect( repostGroup ).toBeVisible();
	await expect( repostGroup.locator( '.reaction-label' ) ).toHaveText(
		/^\s*1\b/
	);

	// 5) Confirm both protocols' authors render in the avatar list. The
	//    Interactivity-API hydrates `<template data-wp-each>` into <li>
	//    elements with <a title="{name}"> bound from the per-comment
	//    context, so asserting on the rendered <a title> is what the
	//    end user actually sees — and it's stable against AP rotating
	//    the internal shape of `data-wp-context` (renaming `items`,
	//    nesting differently, or moving to wp_interactivity_state-only).
	await expect(
		likeGroup.locator( '.reaction-avatars a[title="Alice via Mastodon"]' )
	).toHaveCount( 1 );
	await expect(
		likeGroup.locator( '.reaction-avatars a[title="Bob via Bluesky"]' )
	).toHaveCount( 1 );

	await expect(
		repostGroup.locator( '.reaction-avatars a[title="Carol via Bluesky"]' )
	).toHaveCount( 1 );
} );

test( 'seed endpoint is idempotent: re-invoking yields the same post and the same reaction counts', async ( {
	page,
} ) => {
	// Locks the upsert path in fosse-reactions-seed.php — Playwright
	// retries / `--repeat-each` / a flaky CI re-run must not pile up
	// duplicate posts or stack additional reaction comments on the same
	// post. Without this case, a regression that drops the seed-meta
	// upsert or the seed-meta-scoped cleanup goes undetected.
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction(
		() => !! ( window as any ).wpApiSettings?.nonce
	);

	const post = async () =>
		page.evaluate( async () => {
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

	const first = await post();
	expect(
		first.status,
		`first POST returned: ${ first.text.slice( 0, 300 ) }`
	).toBe( 200 );
	const firstBody = JSON.parse( first.text ) as SeedResponse;
	expect( firstBody.ok ).toBe( true );
	expect( firstBody.comment_ids.length ).toBe( 3 );

	const second = await post();
	expect(
		second.status,
		`second POST returned: ${ second.text.slice( 0, 300 ) }`
	).toBe( 200 );
	const secondBody = JSON.parse( second.text ) as SeedResponse;
	expect( secondBody.ok ).toBe( true );
	expect(
		secondBody.post_id,
		'seed-reactions must reuse the same post on re-invocation'
	).toBe( firstBody.post_id );
	expect(
		secondBody.comment_ids.length,
		'seed-reactions must yield exactly three comments on re-invocation, not stack'
	).toBe( 3 );
	// Comments are wiped + reseeded, so IDs are new — but the *count*
	// must hold. Asserting the rendered counts on the post itself
	// catches the case where the upsert reused the post but the
	// cleanup query missed prior rows. Scope to the explicit-embed
	// wrapper (see comment in the main test) so the blockHooks auto-
	// injected wrapper can't mask a regression here.
	await page.goto( secondBody.post_url );
	const blockWrapper = page.locator(
		'[data-wp-interactive="activitypub/reactions"].fosse-e2e-seeded'
	);
	await expect( blockWrapper ).toHaveCount( 1 );
	await expect(
		blockWrapper.locator(
			'.activitypub-reactions .reaction-group[data-reaction-type="like"] .reaction-label'
		)
	).toHaveText( /^\s*2\b/ );
	await expect(
		blockWrapper.locator(
			'.activitypub-reactions .reaction-group[data-reaction-type="repost"] .reaction-label'
		)
	).toHaveText( /^\s*1\b/ );
} );
