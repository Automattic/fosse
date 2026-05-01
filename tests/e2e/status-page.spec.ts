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

	test( 'long DID, handle, and PDS values do not overflow the card', async ( {
		page,
	} ) => {
		// Set values that are longer than the typical card width — a
		// no-break implementation pushes the table past the card edge.
		await setBlueskyState( page, {
			connected: true,
			handle: 'someone.with.an.unreasonably.long.handle.example.org',
			did: 'did:plc:abcdefghijklmnopqrstuvwxyz0123456789longidentifier',
			pds_endpoint:
				'https://very-long-pds-host-name.example.com/some/deep/nested/path',
			auto_publish: true,
		} );

		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		const blueskyCard = page
			.locator( '.fosse-status-card' )
			.filter( { hasText: 'Bluesky' } );

		await expect( blueskyCard ).toBeVisible();

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

		// The card's table must not horizontally overflow its container.
		// scrollWidth > clientWidth is the canonical "this overflowed"
		// signal; without the polish, the long DID forces it.
		const overflow = await blueskyCard.evaluate( ( card ) => {
			const table = card.querySelector(
				'.fosse-status-card__table'
			) as HTMLElement | null;
			if ( ! table || ! ( card instanceof HTMLElement ) ) {
				return null;
			}

			return {
				cardScroll: card.scrollWidth,
				cardClient: card.clientWidth,
				tableScroll: table.scrollWidth,
				tableClient: table.clientWidth,
			};
		} );

		expect( overflow ).not.toBeNull();
		// Allow a 1px tolerance for sub-pixel rounding on different display densities.
		expect( overflow!.cardScroll ).toBeLessThanOrEqual(
			overflow!.cardClient + 1
		);
		expect( overflow!.tableScroll ).toBeLessThanOrEqual(
			overflow!.tableClient + 1
		);
	} );
} );
