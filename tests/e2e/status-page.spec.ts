import { test, expect, type Page } from '@playwright/test';
import {
	expectNoHorizontalOverflow,
	getProviderStatusCard,
	numericCssValueFor,
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
		const card = getProviderStatusCard( page, 'Bluesky' );
		await expect( card ).toBeVisible();
		return card.evaluate( ( el ) => {
			const detailList = el.querySelector( 'dl' ) as HTMLElement | null;
			if ( ! detailList || ! ( el instanceof HTMLElement ) ) {
				return null;
			}
			return {
				cardScroll: el.scrollWidth,
				cardClient: el.clientWidth,
				detailListScroll: detailList.scrollWidth,
				detailListClient: detailList.clientWidth,
			};
		} );
	};

	test( 'long DID, handle, and PDS values do not overflow at desktop width', async ( {
		page,
	} ) => {
		await seedLongValues( page );
		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		const blueskyCard = getProviderStatusCard( page, 'Bluesky' );
		await expect( blueskyCard ).toContainText( 'Handle' );
		await expect( blueskyCard ).toContainText(
			'someone.with.an.unreasonably.long.handle.example.org'
		);
		await expect( blueskyCard ).toContainText( 'DID' );
		await expect( blueskyCard ).toContainText(
			'did:plc:abcdefghijklmnopqrstuvwxyz0123456789longidentifier'
		);
		await expect( blueskyCard ).toContainText( 'PDS endpoint' );
		await expect( blueskyCard ).toContainText(
			'https://very-long-pds-host-name.example.com/some/deep/nested/path'
		);

		const overflow = await measureCardOverflow( page );

		expect( overflow ).not.toBeNull();
		// Allow a 1px tolerance for sub-pixel rounding on different display densities.
		expect( overflow!.cardScroll ).toBeLessThanOrEqual(
			overflow!.cardClient + 1
		);
		expect( overflow!.detailListScroll ).toBeLessThanOrEqual(
			overflow!.detailListClient + 1
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
		expect( overflow!.detailListScroll ).toBeLessThanOrEqual(
			overflow!.detailListClient + 1
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

		const activityPubCard = getProviderStatusCard( page, 'ActivityPub' );
		await expect( activityPubCard ).toHaveCount( 1 );

		const rows = activityPubCard.locator( 'dt' );
		const rowCount = await rows.count();
		expect(
			rowCount,
			'ActivityPub status rows should be present'
		).toBeGreaterThan( 0 );

		const rowGaps = await rows.evaluateAll( ( terms ) =>
			terms.map( ( label ) => {
				const value = label.nextElementSibling;

				if ( ! value || 'DD' !== value.tagName ) {
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
		await expect(
			page.getByRole( 'link', { name: 'Run the wizard' } )
		).toHaveAttribute( 'href', /page=fosse-wizard/ );
		const statusCards = await Promise.all(
			[ 'ActivityPub', 'Bluesky' ].map( async ( provider ) => {
				const box = await getProviderStatusCard(
					page,
					provider
				).boundingBox();
				expect( box ).not.toBeNull();
				return box!;
			} )
		);
		const wizardLink = await page
			.getByRole( 'link', { name: 'Run the wizard' } )
			.boundingBox();
		expect( wizardLink ).not.toBeNull();
		expect( wizardLink!.y ).toBeGreaterThan(
			Math.max( ...statusCards.map( ( box ) => box.y + box.height ) )
		);

		expect(
			await numericCssValueFor(
				page.getByRole( 'heading', { name: 'FOSSE Status' } ),
				'letter-spacing'
			)
		).toBe( 0 );
		expect(
			await numericCssValueFor(
				page.getByText( 'Provider status', { exact: true } ),
				'letter-spacing'
			)
		).toBe( 0 );
		expect(
			await numericCssValueFor(
				page
					.getByText( 'Provider status', { exact: true } )
					.locator( 'xpath=ancestor::div[2]' ),
				'border-radius'
			)
		).toBeLessThanOrEqual( 8 );
		expect(
			await numericCssValueFor(
				getProviderStatusCard( page, 'ActivityPub' ),
				'border-radius'
			)
		).toBeLessThanOrEqual( 8 );
		await expect(
			page
				.getByText( 'Provider status', { exact: true } )
				.locator( 'xpath=ancestor::div[2]' )
		).toHaveCSS( 'background-image', 'none' );

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
			page.getByText( '1 of 2 providers connected' )
		).toBeVisible();

		const manageConnections = page.getByRole( 'link', {
			name: 'Manage connections',
		} );
		await expect( manageConnections ).toBeVisible();
		await expect( manageConnections ).toHaveAttribute(
			'href',
			/admin\.php\?page=fosse#fosse-connections$/
		);

		const blueskyCard = getProviderStatusCard( page, 'Bluesky' );
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
