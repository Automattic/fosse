import { test, expect, type Page } from '@playwright/test';
import {
	expectNoHorizontalOverflow,
	getProviderStatusCard,
	numericCssValueFor,
	resetBlueskyState,
	resetWizardIfComplete,
	setBlueskyState,
} from './test-helpers';

test.describe( 'Bluesky provider UI', () => {
	// The connected-state test below leaves atmosphere_connection set,
	// which flips the onboarding wizard's Bluesky step (rendered by
	// later specs) to its connected-summary branch and hides the
	// connect form. Reset to disconnected so spec ordering can't bleed
	// state into onboarding-wizard.spec.ts.
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

	const expectNoCriticalText = async ( page: Page ) => {
		await expect(
			page.locator(
				'text=/Fatal error|Parse error|Uncaught .*Error|critical error/i'
			)
		).toHaveCount( 0 );
		await expect( page.locator( '#error-page' ) ).toHaveCount( 0 );
	};

	test( 'setup and status pages show Bluesky disconnected by default', async ( {
		page,
	} ) => {
		await setBlueskyState( page, {
			connected: false,
			auto_publish: true,
		} );
		await resetWizardIfComplete( page );

		await page.setViewportSize( { width: 1280, height: 720 } );
		await page.goto( '/wp-admin/admin.php?page=fosse' );
		await expectNoCriticalText( page );

		const federationSettings = page.getByRole( 'group', {
			name: 'Publishing settings',
		} );
		const connections = page.getByRole( 'group', {
			name: 'Connections',
		} );
		const guidedSetup = page
			.getByRole( 'note' )
			.filter( { hasText: 'Want a guided setup?' } );
		const blueskyConnection = connections.locator(
			'#fosse-provider-bluesky'
		);

		await expect( guidedSetup ).toBeVisible();
		await expect( guidedSetup ).toContainText( 'Want a guided setup?' );
		await expect( guidedSetup ).toHaveCSS( 'border-left-width', '4px' );
		const calloutBox = await guidedSetup.boundingBox();
		const federationSettingsBox = await federationSettings.boundingBox();
		expect( calloutBox ).not.toBeNull();
		expect( federationSettingsBox ).not.toBeNull();
		expect(
			Math.abs( calloutBox!.width - federationSettingsBox!.width )
		).toBeLessThanOrEqual( 1 );

		await expect(
			federationSettings.getByRole( 'checkbox' )
		).not.toHaveCount( 0 );
		await expect(
			federationSettings.getByRole( 'radio', {
				name: /Author profiles/,
			} )
		).not.toHaveCount( 0 );
		await expect( federationSettings.locator( 'table' ) ).toHaveCount( 0 );
		await expect(
			federationSettings.getByRole( 'heading', {
				name: 'Publishing settings',
			} )
		).toBeVisible();
		await expect( federationSettings ).toContainText( 'Content types' );
		await expect(
			federationSettings.getByRole( 'link', {
				name: 'Advanced ActivityPub settings',
			} )
		).toBeVisible();
		await expect(
			federationSettings.getByRole( 'button', { name: 'Save settings' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Run the wizard' } )
		).toHaveAttribute( 'href', /page=fosse-wizard/ );
		const connectionsBox = await connections.boundingBox();
		const wizardLink = await page
			.getByRole( 'link', { name: 'Run the wizard' } )
			.boundingBox();
		expect( connectionsBox ).not.toBeNull();
		expect( wizardLink ).not.toBeNull();
		expect( wizardLink!.y ).toBeGreaterThan(
			connectionsBox!.y + connectionsBox!.height
		);
		await expect(
			connections.getByRole( 'heading', { name: 'Connections' } )
		).toBeVisible();
		await expect(
			connections.locator( '#fosse-provider-activitypub-connection' )
		).toContainText( 'Connected automatically' );
		await expect(
			blueskyConnection.getByLabel( 'Bluesky handle' )
		).toBeVisible();
		await expect(
			blueskyConnection.getByRole( 'button', { name: 'Connect Bluesky' } )
		).toBeVisible();
		await expect(
			page.locator( '#fosse-provider-bluesky-settings' )
		).toHaveCount( 0 );

		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		const blueskyCard = getProviderStatusCard( page, 'Bluesky' );
		await expect( blueskyCard ).toContainText( 'Disconnected' );
		// Auto Publish row was removed alongside the Settings toggle —
		// the status card no longer surfaces "Enabled" / "Disabled" text
		// for it in any state.
		await expect( blueskyCard ).not.toContainText( 'Auto Publish' );
	} );

	test( 'setup and status pages show mocked connected Bluesky details', async ( {
		page,
	} ) => {
		await setBlueskyState( page, {
			connected: true,
			handle: 'alice.bsky.social',
			did: 'did:plc:alice123',
			pds_endpoint: 'https://bsky.social',
		} );

		await page.goto( '/wp-admin/admin.php?page=fosse' );
		await expectNoCriticalText( page );

		const connections = page.locator( '#fosse-connections' );
		const blueskyConnection = connections.locator(
			'#fosse-provider-bluesky'
		);

		await expect(
			blueskyConnection.getByRole( 'button', {
				name: 'Disconnect Bluesky',
			} )
		).toBeVisible();
		await expect( blueskyConnection ).toContainText( 'Connected account' );
		await expect( blueskyConnection ).toContainText( 'Bluesky handle' );
		await expect( blueskyConnection ).toContainText( 'Account ID' );
		await expect( blueskyConnection ).toContainText( 'alice.bsky.social' );
		await expect( blueskyConnection ).toContainText( 'did:plc:alice123' );

		// The auto-publish toggle was removed from the unified Settings
		// form (Atmosphere has no per-post manual publish UI to back it
		// up); the Bluesky-specific settings section now renders nothing,
		// so its container shouldn't appear at all.
		await expect(
			page.locator( '#fosse-provider-bluesky-settings' )
		).toHaveCount( 0 );

		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		const blueskyCard = getProviderStatusCard( page, 'Bluesky' );
		await expect( blueskyCard ).toContainText( 'Connected' );
		await expect( blueskyCard ).toContainText( 'alice.bsky.social' );
		await expect( blueskyCard ).toContainText( 'did:plc:alice123' );
		await expect( blueskyCard ).toContainText( 'OK' );

		// Auto-publish row was removed from the status card alongside the
		// Settings toggle.
		await expect( blueskyCard ).not.toContainText( 'Auto Publish' );
	} );

	test( 'settings page uses restrained visual tokens without page overflow', async ( {
		page,
	} ) => {
		await setBlueskyState( page, {
			connected: true,
			handle: 'someone.with.an.unreasonably.long.handle.example.org',
			did: 'did:plc:abcdefghijklmnopqrstuvwxyz0123456789longidentifier',
			pds_endpoint: 'https://bsky.social',
		} );

		await page.setViewportSize( { width: 1280, height: 720 } );
		await page.goto( '/wp-admin/admin.php?page=fosse' );
		await expectNoCriticalText( page );
		await expectNoHorizontalOverflow( page );

		await expect(
			page.getByRole( 'button', { name: 'Save settings' } )
		).toBeVisible();
		const federationSettings = page.getByRole( 'group', {
			name: 'Publishing settings',
		} );
		const connections = page.getByRole( 'group', {
			name: 'Connections',
		} );
		await expect( federationSettings ).toHaveCount( 1 );
		await expect( connections ).toHaveCount( 1 );

		expect(
			await numericCssValueFor(
				page.getByRole( 'heading', { name: 'FOSSE Settings' } ),
				'font-size'
			)
		).toBeLessThanOrEqual( 24 );
		expect(
			await numericCssValueFor(
				page.getByRole( 'heading', { name: 'FOSSE Settings' } ),
				'letter-spacing'
			)
		).toBe( 0 );
		expect(
			await numericCssValueFor(
				page
					.getByText( 'ActivityPub profile', { exact: true } )
					.first(),
				'letter-spacing'
			)
		).toBe( 0 );
		expect(
			await numericCssValueFor( federationSettings, 'border-radius' )
		).toBeLessThanOrEqual( 4 );
		expect(
			await numericCssValueFor( connections, 'border-radius' )
		).toBeLessThanOrEqual( 4 );
		expect(
			await numericCssValueFor(
				page
					.getByRole( 'radio', { name: /Author profiles/ } )
					.locator( 'xpath=..' ),
				'border-radius'
			)
		).toBeLessThanOrEqual( 4 );
		await expect( federationSettings ).toHaveCSS( 'box-shadow', 'none' );
		await expect( connections ).toHaveCSS( 'box-shadow', 'none' );
		await expect( federationSettings ).toHaveCSS(
			'background-image',
			'none'
		);

		await page.setViewportSize( { width: 390, height: 720 } );
		await page.goto( '/wp-admin/admin.php?page=fosse' );
		await expectNoCriticalText( page );
		await expectNoHorizontalOverflow( page );
		expect(
			await numericCssValueFor(
				page.getByRole( 'heading', { name: 'FOSSE Settings' } ),
				'font-size'
			)
		).toBeLessThanOrEqual( 24 );
		await expect(
			page.getByRole( 'button', { name: 'Save settings' } )
		).toBeVisible();
	} );
} );
