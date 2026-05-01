import { test, expect } from '@playwright/test';
import { setBlueskyState } from './test-helpers';

test.describe( 'Bluesky provider UI', () => {
	// The connected-state test below leaves atmosphere_connection set,
	// which flips the onboarding wizard's Bluesky step (rendered by
	// later specs) to its connected-summary branch and hides the
	// connect form. Reset to disconnected so spec ordering can't bleed
	// state into onboarding-wizard.spec.ts.
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

	test( 'setup and status pages show Bluesky disconnected by default', async ( {
		page,
	} ) => {
		await setBlueskyState( page, {
			connected: false,
			auto_publish: true,
		} );

		await page.goto( '/wp-admin/admin.php?page=fosse' );

		const blueskySection = page
			.locator( '.fosse-provider-section' )
			.filter( { hasText: 'Bluesky' } );

		await expect(
			page.getByRole( 'heading', { name: 'Bluesky' } )
		).toBeVisible();
		await expect(
			blueskySection.locator( '#fosse_bluesky_handle' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Connect Bluesky' } )
		).toBeVisible();

		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		const blueskyCard = page
			.locator( '.fosse-status-card' )
			.filter( { hasText: 'Bluesky' } );
		await expect( blueskyCard ).toContainText( 'Disconnected' );
		await expect( blueskyCard ).toContainText( 'Enabled' );
	} );

	test( 'setup and status pages show mocked connected Bluesky details', async ( {
		page,
	} ) => {
		await setBlueskyState( page, {
			connected: true,
			handle: 'alice.bsky.social',
			did: 'did:plc:alice123',
			pds_endpoint: 'https://bsky.social',
			auto_publish: false,
		} );

		await page.goto( '/wp-admin/admin.php?page=fosse' );

		const blueskySection = page
			.locator( '.fosse-provider-section' )
			.filter( { hasText: 'Bluesky' } );

		await expect(
			page.getByRole( 'button', { name: 'Disconnect Bluesky' } )
		).toBeVisible();
		await expect( blueskySection ).toContainText( 'alice.bsky.social' );
		await expect( blueskySection ).toContainText( 'did:plc:alice123' );
		await expect( blueskySection ).toContainText( 'Disabled' );

		await page.goto( '/wp-admin/admin.php?page=fosse-status' );

		const blueskyCard = page
			.locator( '.fosse-status-card' )
			.filter( { hasText: 'Bluesky' } );
		await expect( blueskyCard ).toContainText( 'Connected' );
		await expect( blueskyCard ).toContainText( 'alice.bsky.social' );
		await expect( blueskyCard ).toContainText( 'did:plc:alice123' );
		await expect( blueskyCard ).toContainText( 'Disabled' );
		await expect( blueskyCard ).toContainText( 'OK' );
	} );
} );
