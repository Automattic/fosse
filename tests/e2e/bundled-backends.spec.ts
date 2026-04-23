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
