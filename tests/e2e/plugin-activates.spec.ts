import { test, expect } from '@playwright/test';

test( 'FOSSE is active on the Plugins screen', async ( { page } ) => {
	await page.goto( '/wp-admin/plugins.php' );

	const row = page.locator( 'tr[data-slug="fosse"], tr#fosse' );
	await expect( row ).toBeVisible();
	await expect( row ).toHaveClass( /active/ );
} );
