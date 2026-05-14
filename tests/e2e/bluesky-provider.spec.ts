import { test, expect, type Page } from '@playwright/test';
import {
	expectNoHorizontalOverflow,
	numericCssValue,
	resetBlueskyState,
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

	const resetWizardIfComplete = async ( page: Page ) => {
		await page.goto(
			'/wp-admin/admin.php?page=fosse-wizard&step=complete'
		);
		const reset = page.getByRole( 'link', { name: 'Run wizard again' } );

		if ( await reset.count() ) {
			await reset.click();
			await expect( page ).toHaveURL( /page=fosse-wizard/ );
		}
	};

	test( 'setup and status pages show Bluesky disconnected by default', async ( {
		page,
	} ) => {
		await setBlueskyState( page, {
			connected: false,
			auto_publish: true,
		} );
		await resetWizardIfComplete( page );

		await page.goto( '/wp-admin/admin.php?page=fosse' );
		await expectNoCriticalText( page );

		const federationSettings = page.locator( '#fosse-federation-settings' );
		const connections = page.locator( '#fosse-connections' );
		const guidedSetup = page.locator( '.fosse-guided-setup' );
		const blueskyConnection = connections.locator(
			'#fosse-provider-bluesky'
		);

		await expect( guidedSetup ).toBeVisible();
		await expect( guidedSetup ).toContainText( 'Want a guided setup?' );
		await expect( guidedSetup ).toHaveCSS( 'border-left-width', '4px' );
		await expect( guidedSetup ).toHaveCSS(
			'border-left-color',
			'rgb(56, 88, 233)'
		);
		const calloutWidth = await page.evaluate( () => {
			const callout = document.querySelector( '.fosse-guided-setup' );
			const mainCard = document.querySelector(
				'#fosse-federation-settings'
			);

			if ( ! callout || ! mainCard ) {
				return null;
			}

			return {
				calloutWidth: callout.getBoundingClientRect().width,
				mainCardWidth: mainCard.getBoundingClientRect().width,
			};
		} );
		expect( calloutWidth ).not.toBeNull();
		expect(
			Math.abs( calloutWidth!.calloutWidth - calloutWidth!.mainCardWidth )
		).toBeLessThanOrEqual( 1 );

		await expect( federationSettings ).toHaveClass( /fosse-admin-card/ );
		await expect(
			federationSettings.locator( '.fosse-field' )
		).not.toHaveCount( 0 );
		await expect(
			federationSettings.locator( '.fosse-choice-card' )
		).not.toHaveCount( 0 );
		await expect(
			page.locator( '.fosse-admin-page .form-table' )
		).toHaveCount( 0 );
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
		const wizardLinkPlacement = await page.evaluate( () => {
			const connectionSection =
				document.querySelector( '#fosse-connections' );
			const link = document.querySelector(
				'.fosse-admin-page__footer-action a'
			);

			if ( ! connectionSection || ! link ) {
				return null;
			}

			return {
				connectionsBottom:
					connectionSection.getBoundingClientRect().bottom,
				linkTop: link.getBoundingClientRect().top,
			};
		} );
		expect( wizardLinkPlacement ).not.toBeNull();
		expect( wizardLinkPlacement!.linkTop ).toBeGreaterThan(
			wizardLinkPlacement!.connectionsBottom
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

		const blueskyCard = page
			.locator( '.fosse-status-card' )
			.filter( { hasText: 'Bluesky' } );
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

		const blueskyCard = page
			.locator( '.fosse-status-card' )
			.filter( { hasText: 'Bluesky' } );
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
				'.fosse-field__label',
				'letter-spacing'
			)
		).toBe( 0 );
		expect(
			await numericCssValue( page, '.fosse-admin-card', 'border-radius' )
		).toBeLessThanOrEqual( 8 );
		expect(
			await numericCssValue( page, '.fosse-choice-card', 'border-radius' )
		).toBeLessThanOrEqual( 8 );

		await page.setViewportSize( { width: 390, height: 720 } );
		await page.goto( '/wp-admin/admin.php?page=fosse' );
		await expectNoCriticalText( page );
		await expectNoHorizontalOverflow( page );
		await expect(
			page.getByRole( 'button', { name: 'Save settings' } )
		).toBeVisible();
	} );
} );
