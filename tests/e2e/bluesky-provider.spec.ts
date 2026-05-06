import { test, expect } from '@playwright/test';
import { resetBlueskyState, setBlueskyState } from './test-helpers';

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

	test( 'setup and status pages show Bluesky disconnected by default', async ( {
		page,
	} ) => {
		await setBlueskyState( page, {
			connected: false,
			auto_publish: true,
		} );

		await page.goto( '/wp-admin/admin.php?page=fosse' );

		const federationSettings = page.locator( '#fosse-federation-settings' );
		const connections = page.locator( '#fosse-connections' );
		const blueskyConnection = connections.locator(
			'#fosse-provider-bluesky'
		);

		await expect(
			federationSettings.getByRole( 'heading', {
				name: 'Federation settings',
			} )
		).toBeVisible();
		await expect(
			federationSettings.getByRole( 'button', { name: 'Save settings' } )
		).toBeVisible();
		await expect(
			connections.getByRole( 'heading', { name: 'Connections' } )
		).toBeVisible();
		await expect(
			connections.locator( '#fosse-provider-activitypub-connection' )
		).toContainText( 'Connected automatically' );
		await expect(
			blueskyConnection.locator( '#fosse_bluesky_handle' )
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

		const connections = page.locator( '#fosse-connections' );
		const blueskyConnection = connections.locator(
			'#fosse-provider-bluesky'
		);

		await expect(
			blueskyConnection.getByRole( 'button', {
				name: 'Disconnect Bluesky',
			} )
		).toBeVisible();
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
} );
