import { test, expect } from '@playwright/test';
import { resetBlueskyState, setBlueskyState } from './test-helpers';

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

test( 'Bluesky step shows connect form', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=bluesky' );

	await expect(
		page.locator( '.fosse-wizard__title', {
			hasText: 'Connect to Bluesky',
		} )
	).toBeVisible();
	await expect( page.locator( '#fosse-bsky-handle' ) ).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Connect Bluesky' } )
	).toBeVisible();
	await expect( page.locator( 'text=Coming Soon' ) ).toHaveCount( 0 );
} );

test( 'Bluesky step (disconnected) shows sign-up help linking to bsky.app', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=bluesky' );

	const signupLink = page.locator(
		'.fosse-bluesky-signup a[href="https://bsky.app/"]'
	);
	await expect( signupLink ).toBeVisible();
	await expect( signupLink ).toHaveAttribute( 'target', '_blank' );
	await expect( signupLink ).toHaveAttribute( 'rel', /noopener/ );
} );

test.describe( 'Bluesky step — connected (post-OAuth completion)', () => {
	// Reset to disconnected so this group's seeded connection cannot bleed
	// into the sibling specs that assume the disconnected default.
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
		await setBlueskyState( page, {
			connected: true,
			handle: 'alice.bsky.social',
			did: 'did:plc:alice123',
			pds_endpoint: 'https://bsky.social',
			auto_publish: true,
		} );
	} );

	test( 'connected state suppresses "connect later" copy and shows summary', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=bluesky' );

		await expect(
			page.locator( '.fosse-wizard__title', {
				hasText: 'Bluesky is connected',
			} )
		).toBeVisible();
		await expect(
			page.locator( '.fosse-wizard__description' )
		).not.toContainText( 'connect later' );
		await expect( page.locator( '.fosse-summary' ) ).toContainText(
			'alice.bsky.social'
		);
		await expect( page.locator( '.fosse-bluesky-signup' ) ).toHaveCount(
			0
		);
		await expect(
			page.getByRole( 'link', { name: 'Finish setup' } )
		).toBeVisible();
	} );
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

test( 'Completion step exposes "Publish your first Post" CTA to post-new.php', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=complete' );

	const publishCta = page.locator( '.fosse-wizard__cta-publish' );
	await expect( publishCta ).toBeVisible();
	// Capitalization mirrors the post type's `singular_name` ("Post") so
	// the assertion matches the PHP-side behavior — see PHPUnit
	// `test_render_complete_step_renders_publish_cta`.
	await expect( publishCta ).toContainText( 'Publish your first Post' );
	await expect( publishCta ).toHaveAttribute( 'href', /post-new\.php$/ );
} );

// Activation-redirect coverage lives in PHPUnit (MenuTest). The E2E
// version was flaky because Playground's blueprint activates the plugin,
// sets the redirect signal, and consuming it deterministically before
// navigating to the Setup page proved unreliable in CI.

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
