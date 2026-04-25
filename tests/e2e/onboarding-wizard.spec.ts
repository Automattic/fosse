import { test, expect } from '@playwright/test';

test( 'Wizard page loads without errors', async ( { page } ) => {
	const response = await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );
	expect( response?.status() ).toBeLessThan( 400 );

	await expect(
		page.locator( 'text=/Fatal error|Parse error|Uncaught .*Error/i' )
	).toHaveCount( 0 );
	await expect( page.locator( '#error-page' ) ).toHaveCount( 0 );

	await expect(
		page.locator( '.fosse-wizard__title', { hasText: 'Welcome to FOSSE' } )
	).toBeVisible();
} );

test( 'Wizard shows progress indicator on welcome step', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await expect( page.locator( '.fosse-wizard__progress' ) ).toBeVisible();
	await expect(
		page.locator( '.fosse-wizard__progress-step.is-active', {
			hasText: 'Welcome',
		} )
	).toBeVisible();
} );

test( 'Get Started navigates to appearance step', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await page.click( 'text=Get Started' );
	await expect( page ).toHaveURL( /step=appearance/ );
	await expect(
		page.locator( '.fosse-wizard__title', {
			hasText: 'How should your site appear',
		} )
	).toBeVisible();
} );

test( 'Appearance step has three actor mode cards', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=appearance' );

	await expect( page.locator( '.fosse-mode-card' ) ).toHaveCount( 3 );
} );

test( 'Selecting a mode and continuing saves the option', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=appearance' );

	// Select "As you" (actor mode). Use exact title match to avoid
	// substring collision with "As your site".
	await page
		.locator( '.fosse-mode-card', {
			has: page.locator( '.fosse-mode-card__title', {
				hasText: /^As you$/,
			} ),
		} )
		.click();
	await page.click( 'input[type="submit"]' );

	await expect( page ).toHaveURL( /step=content/ );

	// Verify the option was saved by going back to the appearance step
	// and checking the radio is still selected.
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=appearance' );
	await expect(
		page.locator( '.fosse-mode-card__input[value="actor"]' )
	).toBeChecked();
} );

test( 'Content step saves post types and advances to Bluesky', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=content' );

	// Posts should be checked by default; check Pages too.
	await page.locator( '.fosse-post-type-item', { hasText: 'Pages' } ).click();
	await page.click( 'input[type="submit"]' );

	await expect( page ).toHaveURL( /step=bluesky/ );
} );

test( 'Bluesky step shows coming soon notice', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=bluesky' );

	await expect(
		page.locator( '.fosse-wizard__title', {
			hasText: 'Connect to Bluesky',
		} )
	).toBeVisible();
	await expect( page.locator( 'text=Coming Soon' ) ).toBeVisible();
} );

test( 'Skip for now on Bluesky step goes to completion', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=bluesky' );

	await page.click( 'text=Skip for now' );
	await expect( page ).toHaveURL( /step=complete/ );
	await expect(
		page.locator( '.fosse-wizard__title', {
			hasText: "You're all set",
		} )
	).toBeVisible();
} );

test( 'Completion step shows summary', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=complete' );

	await expect( page.locator( '.fosse-summary' ) ).toBeVisible();
	await expect( page.locator( 'text=Site appears as' ) ).toBeVisible();
	await expect( page.locator( 'text=Sharing' ) ).toBeVisible();
	await expect(
		page.locator( '.fosse-summary__label', { hasText: 'Bluesky' } )
	).toBeVisible();
} );

test( 'Setup page shows wizard notice when wizard is incomplete', async ( {
	page,
} ) => {
	// This test must run before "Skip setup" which marks the wizard complete.
	// Playground state is shared across tests in the same worker.
	// First, consume the activation redirect transient by visiting the wizard
	// directly, so navigating to the Setup page doesn't get redirected.
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );
	await page.goto( '/wp-admin/admin.php?page=fosse' );

	await expect(
		page.locator( 'a', { hasText: 'Run the setup wizard' } )
	).toBeVisible();
} );

test( 'Skip setup marks wizard complete and goes to Setup page', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await page.click( 'text=Skip setup' );
	await expect( page ).toHaveURL( /page=fosse(?!-)/ );

	// Wizard notice should not appear since wizard is now complete.
	await expect( page.locator( 'text=Run the setup wizard' ) ).toHaveCount(
		0
	);
} );

test( 'Wizard is not visible in the admin sidebar menu', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await expect(
		page.locator( '#adminmenu a', { hasText: 'Setup Wizard' } )
	).toHaveCount( 0 );
} );
