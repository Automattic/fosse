import { test, expect, type Page } from '@playwright/test';
import {
	expectNoHorizontalOverflow,
	numericCssValue,
	resetBlueskyState,
	setBlueskyState,
} from './test-helpers';

test.describe( 'Status page polish', () => {
	// Connected-state writes leak across specs (the wizard's connect step
	// flips to its summary branch when atmosphere_connection is set), so
	// reset to disconnected on the way out — mirrors bluesky-provider.spec.ts.
	test.afterAll( async ( { browser }, testInfo ) => {
		const baseURL = testInfo.project.use.baseURL;
		if ( ! baseURL ) {
			throw new Error(
				'baseURL must be configured in playwright.config.ts'
			);
		}
		await resetBlueskyState( browser, baseURL );
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

	test( 'status labels do not overlap values at intermediate admin width', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 895, height: 720 } );
		await setBlueskyState( page, {
			connected: false,
			auto_publish: true,
		} );
		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		const activityPubCard = page.locator( '.fosse-status-card' ).filter( {
			has: page.getByRole( 'heading', { name: 'ActivityPub' } ),
		} );
		await expect( activityPubCard ).toHaveCount( 1 );

		const rows = activityPubCard.locator( '.fosse-status-card__table tr' );
		const rowCount = await rows.count();
		expect(
			rowCount,
			'ActivityPub status rows should be present'
		).toBeGreaterThan( 0 );

		const rowGaps = await rows.evaluateAll( ( statusRows ) =>
			statusRows.map( ( row ) => {
				const label = row.querySelector( '.fosse-status-card__label' );
				const value = row.querySelector( '.fosse-status-card__value' );

				if ( ! label || ! value ) {
					return null;
				}

				const labelRange = document.createRange();
				labelRange.selectNodeContents( label );

				return {
					label: label.textContent?.trim() ?? '',
					gap:
						value.getBoundingClientRect().left -
						labelRange.getBoundingClientRect().right,
				};
			} )
		);

		for ( const [ i, rowGap ] of rowGaps.entries() ) {
			if ( ! rowGap ) {
				throw new Error( `row ${ i } missing label/value cells` );
			}

			expect(
				rowGap.gap,
				`${ rowGap.label } label should not paint into the value cell`
			).toBeGreaterThanOrEqual( 0 );
		}
	} );

	test( 'summary and cards use restrained visual tokens without page overflow', async ( {
		page,
	} ) => {
		await setBlueskyState( page, {
			connected: false,
			auto_publish: true,
		} );

		await page.setViewportSize( { width: 1280, height: 720 } );
		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		await expectNoHorizontalOverflow( page );
		await expect(
			page.getByRole( 'link', { name: 'Manage connections' } )
		).toBeVisible();

		expect(
			await numericCssValue(
				page,
				'.fosse-admin-page__title',
				'letter-spacing'
			)
		).toBe( 0 );
		expect(
			await numericCssValue(
				page,
				'.fosse-status-summary__label',
				'letter-spacing'
			)
		).toBe( 0 );
		expect(
			await numericCssValue(
				page,
				'.fosse-status-summary',
				'border-radius'
			)
		).toBeLessThanOrEqual( 8 );
		expect(
			await numericCssValue( page, '.fosse-status-card', 'border-radius' )
		).toBeLessThanOrEqual( 8 );
		await expect( page.locator( '.fosse-status-summary' ) ).toHaveCSS(
			'background-image',
			'none'
		);

		await page.setViewportSize( { width: 390, height: 720 } );
		await page.goto( '/wp-admin/admin.php?page=fosse-status' );
		await expectNoHorizontalOverflow( page );
	} );

	test( 'partial-connected state links to connection management', async ( {
		page,
	} ) => {
		await setBlueskyState( page, {
			connected: false,
			auto_publish: true,
		} );

		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		await expect(
			page.locator( '.fosse-status-summary__count' )
		).toContainText( '1 of 2 providers connected' );

		const manageConnections = page.getByRole( 'link', {
			name: 'Manage connections',
		} );
		await expect( manageConnections ).toBeVisible();
		await expect( manageConnections ).toHaveAttribute(
			'href',
			/admin\.php\?page=fosse#fosse-connections$/
		);

		const blueskyCard = page
			.locator( '.fosse-status-card' )
			.filter( { hasText: 'Bluesky' } );
		const openBlueskySettings = blueskyCard.getByRole( 'link', {
			name: 'Open Bluesky settings',
		} );
		await expect( openBlueskySettings ).toBeVisible();
		await expect( openBlueskySettings ).toHaveAttribute(
			'href',
			/admin\.php\?page=fosse#fosse-provider-bluesky$/
		);
	} );
} );
