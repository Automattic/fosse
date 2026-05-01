import { test, expect, type Page } from '@playwright/test';

const setBlueskyState = async (
	page: Page,
	body: Record< string, unknown >
) => {
	const result = await page.evaluate( async ( payload ) => {
		const res = await fetch( '/wp-json/fosse-e2e/v1/bluesky-state', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
			},
			body: JSON.stringify( payload ),
		} );

		return { status: res.status, text: await res.text() };
	}, body );

	expect(
		result.status,
		`fosse-e2e/v1/bluesky-state returned: ${ result.text.slice( 0, 300 ) }`
	).toBe( 200 );
};

test.describe( 'Status page polish', () => {
	// Connected-state writes leak across specs (the wizard's connect step
	// flips to its summary branch when atmosphere_connection is set), so
	// reset to disconnected on the way out — mirrors bluesky-provider.spec.ts.
	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction(
			() => !! ( window as any ).wpApiSettings?.nonce
		);
		await setBlueskyState( page, {
			connected: false,
			auto_publish: true,
		} );
		await page.close();
	} );

	test.beforeEach( async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction(
			() => !! ( window as any ).wpApiSettings?.nonce
		);
	} );

	const seedLongValues = ( page: Page ) =>
		setBlueskyState( page, {
			connected: true,
			handle: 'someone.with.an.unreasonably.long.handle.example.org',
			did: 'did:plc:abcdefghijklmnopqrstuvwxyz0123456789longidentifier',
			pds_endpoint:
				'https://very-long-pds-host-name.example.com/some/deep/nested/path',
			auto_publish: true,
		} );

	const measureCardOverflow = async ( page: Page ) => {
		const card = page
			.locator( '.fosse-status-card' )
			.filter( { hasText: 'Bluesky' } );
		await expect( card ).toBeVisible();
		return card.evaluate( ( el ) => {
			const table = el.querySelector(
				'.fosse-status-card__table'
			) as HTMLElement | null;
			if ( ! table || ! ( el instanceof HTMLElement ) ) {
				return null;
			}
			return {
				cardScroll: el.scrollWidth,
				cardClient: el.clientWidth,
				tableScroll: table.scrollWidth,
				tableClient: table.clientWidth,
			};
		} );
	};

	test( 'long DID, handle, and PDS values do not overflow at desktop width', async ( {
		page,
	} ) => {
		await seedLongValues( page );
		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		const blueskyCard = page
			.locator( '.fosse-status-card' )
			.filter( { hasText: 'Bluesky' } );

		// The value cells should carry the BEM classes the polish CSS
		// targets, and the token wrappers should carry the token-shape
		// modifier so `overflow-wrap: anywhere` is scoped correctly.
		await expect(
			blueskyCard.locator( '.fosse-status-card__token--did' )
		).toBeVisible();
		await expect(
			blueskyCard.locator( '.fosse-status-card__token--url' )
		).toBeVisible();
		await expect(
			blueskyCard.locator( '.fosse-status-card__token--handle' )
		).toBeVisible();

		const overflow = await measureCardOverflow( page );

		expect( overflow ).not.toBeNull();
		// Allow a 1px tolerance for sub-pixel rounding on different display densities.
		expect( overflow!.cardScroll ).toBeLessThanOrEqual(
			overflow!.cardClient + 1
		);
		expect( overflow!.tableScroll ).toBeLessThanOrEqual(
			overflow!.tableClient + 1
		);
	} );

	test( 'long values do not overflow at narrow admin width', async ( {
		page,
	} ) => {
		// The narrow-viewport case is the actual symptom in issue #62 —
		// wp-admin's responsive sidebar collapses cards into a single
		// column where the grid switches to `min(100%, 360px)`. A naive
		// `min-content`-sized cell would push the card past the viewport
		// edge here even though the desktop test passes.
		await page.setViewportSize( { width: 480, height: 720 } );
		await seedLongValues( page );
		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		const overflow = await measureCardOverflow( page );

		expect( overflow ).not.toBeNull();
		expect( overflow!.cardScroll ).toBeLessThanOrEqual(
			overflow!.cardClient + 1
		);
		expect( overflow!.tableScroll ).toBeLessThanOrEqual(
			overflow!.tableClient + 1
		);
	} );
} );
