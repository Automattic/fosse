import { test, expect } from '@playwright/test';

test( 'WP admin boots with bundled backends active and FOSSE menu replaces ActivityPub', async ( {
	page,
} ) => {
	const response = await page.goto( '/wp-admin/options-general.php' );
	expect( response?.status() ).toBeLessThan( 400 );

	// Generic PHP fatal / notice banners should not appear.
	await expect(
		page.locator( 'text=/Fatal error|Parse error|Uncaught .*Error/i' )
	).toHaveCount( 0 );

	// FOSSE hides the bundled ActivityPub menu and provides its own.
	await expect(
		page.locator( '#adminmenu a', { hasText: 'ActivityPub' } ).first()
	).toBeHidden();
	await expect(
		page.locator( '#adminmenu a', { hasText: 'FOSSE' } ).first()
	).toBeVisible();
} );

test( 'Suppressed bundled AP settings page is still accessible by direct URL', async ( {
	page,
} ) => {
	const response = await page.goto(
		'/wp-admin/options-general.php?page=activitypub'
	);
	expect( response?.status() ).toBeLessThan( 400 );

	// The page should load without errors — not a "You do not have
	// sufficient permissions" screen or a redirect away.
	await expect(
		page.locator( 'text=/Fatal error|Parse error|Uncaught .*Error/i' )
	).toHaveCount( 0 );
	await expect( page.locator( '#error-page' ) ).toHaveCount( 0 );
} );

test( 'Suppressed bundled Atmosphere settings page is still accessible by direct URL', async ( {
	page,
} ) => {
	const response = await page.goto(
		'/wp-admin/options-general.php?page=atmosphere'
	);
	expect( response?.status() ).toBeLessThan( 400 );

	await expect(
		page.locator( 'text=/Fatal error|Parse error|Uncaught .*Error/i' )
	).toHaveCount( 0 );
	await expect( page.locator( '#error-page' ) ).toHaveCount( 0 );
} );

test( 'AP reader UI nodes are suppressed when activitypub_reader_ui=1', async ( {
	page,
} ) => {
	const response = await page.goto( '/wp-admin/' );
	expect( response?.status() ).toBeLessThan( 400 );

	// Dashboard submenu entry for the Social Web app (registered via
	// add_dashboard_page) must be suppressed.
	await expect(
		page.locator( '#adminmenu a[href*="activitypub-social-web"]' )
	).toHaveCount( 0 );

	// Admin-bar node for Social Web (registered at admin_bar_menu priority
	// 100) must also be suppressed.
	await expect(
		page.locator( '#wp-admin-bar-activitypub-social-web' )
	).toHaveCount( 0 );
} );
