import { test, expect } from '@playwright/test';

test( 'WP admin boots with bundled backends active and ActivityPub menu is registered', async ( {
	page,
} ) => {
	const response = await page.goto( '/wp-admin/options-general.php' );
	expect( response?.status() ).toBeLessThan( 400 );

	// Generic PHP fatal / notice banners should not appear.
	await expect(
		page.locator( 'text=/Fatal error|Parse error|Uncaught .*Error/i' )
	).toHaveCount( 0 );

	// The bundled ActivityPub plugin registers its own submenu under Settings.
	await expect(
		page.locator( '#adminmenu a', { hasText: 'ActivityPub' } ).first()
	).toBeVisible();
} );
