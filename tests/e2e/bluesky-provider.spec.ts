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

		// The Bluesky connection panel renders below the unified Settings
		// form; the in-form `#fosse-provider-bluesky-settings` block is
		// suppressed while disconnected (no auto-publish toggle to save).
		const blueskyConnection = page.locator( '#fosse-provider-bluesky' );

		await expect(
			page.getByRole( 'heading', { name: 'Bluesky connection' } )
		).toBeVisible();
		await expect(
			blueskyConnection.locator( '#fosse_bluesky_handle' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Connect Bluesky' } )
		).toBeVisible();
		await expect(
			page.locator( '#fosse-provider-bluesky-settings' )
		).toHaveCount( 0 );

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

		// The connection details (handle, DID, PDS, token health) live in
		// the connection panel; the auto-publish checkbox lives in the
		// in-form settings block — separate sections, separate IDs.
		const blueskyConnection = page.locator( '#fosse-provider-bluesky' );
		const blueskySettings = page.locator(
			'#fosse-provider-bluesky-settings'
		);

		await expect(
			page.getByRole( 'button', { name: 'Disconnect Bluesky' } )
		).toBeVisible();
		await expect( blueskyConnection ).toContainText( 'alice.bsky.social' );
		await expect( blueskyConnection ).toContainText( 'did:plc:alice123' );
		await expect(
			blueskySettings.locator( 'input[name="atmosphere_auto_publish"]' )
		).not.toBeChecked();

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
