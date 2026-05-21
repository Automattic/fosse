import { test, expect, type Page } from '@playwright/test';
import {
	expectNoHorizontalOverflow,
	numericCssValueFor,
	resetBlueskyState,
	setBlueskyState,
} from './test-helpers';

const selectDestination = async ( page: Page, destination: string ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );
	await page.getByText( destination, { exact: true } ).click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();
	await expect( page ).toHaveURL( /step=appearance/ );
};

const openBlueskyStep = async ( page: Page ) => {
	await selectDestination( page, 'Fediverse + Bluesky' );
	// This helper intentionally jumps to Bluesky after setting destination
	// intent; it does not seed identity/content state, so keep full-flow
	// dependencies covered by the sequential wizard specs below.
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=bluesky' );
};

const completeThroughBlueskySkip = async ( page: Page ) => {
	await openBlueskyStep( page );
	await page.getByRole( 'link', { name: 'Skip Bluesky for now' } ).click();
	await expect( page ).toHaveURL( /step=content/ );
	await page.getByRole( 'checkbox', { name: 'Posts' } ).check();
	await page.getByRole( 'button', { name: 'Continue' } ).click();
	await expect( page ).toHaveURL( /step=complete/ );
};

test( 'Wizard page loads without errors', async ( { page } ) => {
	const response = await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );
	expect( response?.status() ).toBeLessThan( 400 );

	await expect(
		page.locator( 'text=/Fatal error|Parse error|Uncaught .*Error/i' )
	).toHaveCount( 0 );
	await expect( page.locator( '#error-page' ) ).toHaveCount( 0 );

	await expect(
		page.getByRole( 'heading', {
			name: 'Where should your WordPress posts appear?',
		} )
	).toBeVisible();
} );

test( 'Wizard shows progress indicator on destination step', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	const progress = page.getByRole( 'list', { name: 'Setup progress' } );
	await expect( progress ).toBeVisible();
	await expect( progress.locator( '[aria-current="step"]' ) ).toBeVisible();
	await expect( progress.locator( '[aria-current="step"]' ) ).toHaveText(
		'Destinations'
	);
} );

test( 'Destination step shows two destination cards', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await expect(
		page.getByText(
			/Fediverse publishing creates a profile at your site's domain/
		)
	).toBeVisible();
	await expect(
		page.getByRole( 'heading', {
			name: 'Where should your WordPress posts appear?',
		} )
	).toContainText( 'Where should your WordPress posts appear?' );
	await expect(
		page.getByRole( 'link', { name: 'Skip setup' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Continue' } )
	).toBeVisible();
	const destinations = page.getByRole( 'group', {
		name: 'Where to publish',
	} );
	await expect( destinations.getByRole( 'radio' ) ).toHaveCount( 2 );
	await expect(
		destinations.getByRole( 'radio', {
			name: /fediverse profile at your site's domain/,
		} )
	).toHaveCount( 2 );
	await expect(
		destinations.getByRole( 'radio', {
			name: /Fediverse \+ Bluesky/,
		} )
	).toBeVisible();
	await expect(
		destinations.getByRole( 'radio', {
			name: /Fediverse only/,
		} )
	).toBeVisible();
	await expect( page.getByText( 'Mastodon and similar apps' ) ).toHaveCount(
		0
	);
} );

test( 'Destination step keeps skip and continue actions on one desktop row', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	const skipRect = await page
		.getByRole( 'link', { name: 'Skip setup' } )
		.boundingBox();
	const submitRect = await page
		.getByRole( 'button', { name: 'Continue' } )
		.boundingBox();

	expect( skipRect ).not.toBeNull();
	expect( submitRect ).not.toBeNull();
	expect(
		Math.abs(
			skipRect!.y +
				skipRect!.height / 2 -
				( submitRect!.y + submitRect!.height / 2 )
		)
	).toBeLessThanOrEqual( 4 );
	expect( skipRect!.x + skipRect!.width ).toBeLessThanOrEqual(
		submitRect!.x
	);
} );

test( 'Wizard progress stays compact and keeps step labels available on mobile', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 390, height: 720 } );
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await expectNoHorizontalOverflow( page );
	await expect(
		page.getByRole( 'button', { name: 'Continue' } )
	).toBeVisible();
	const progress = page.getByRole( 'list', { name: 'Setup progress' } );
	await expect( progress.locator( '[aria-current="step"]' ) ).toHaveText(
		'Destinations'
	);

	const progressMetrics = await progress.evaluate( ( el ) => {
		const visibleSteps = Array.from(
			el.querySelectorAll( 'li:not([aria-hidden="true"])' )
		);
		const unavailableLabels = visibleSteps.filter( ( step ) => {
			const label = Array.from( step.children ).find(
				( child ) => child.textContent?.trim()
			);
			if ( ! label ) {
				return true;
			}
			const style = window.getComputedStyle( label );
			return 'hidden' === style.visibility || 'none' === style.display;
		} ).length;

		return {
			height: ( el as HTMLElement ).offsetHeight,
			unavailableLabels,
		};
	} );

	expect( progressMetrics.height ).toBeLessThanOrEqual( 48 );
	expect( progressMetrics.unavailableLabels ).toBe( 0 );
} );

test( 'Wizard surfaces use restrained visual tokens without page overflow', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 720 } );
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await expectNoHorizontalOverflow( page );

	expect(
		await numericCssValueFor(
			page.getByRole( 'heading', {
				name: 'Where should your WordPress posts appear?',
			} ),
			'letter-spacing'
		)
	).toBe( 0 );
	expect(
		await numericCssValueFor(
			page.getByText( 'Recommended', { exact: true } ),
			'letter-spacing'
		)
	).toBe( 0 );
	expect(
		await numericCssValueFor(
			page
				.getByRole( 'radio', { name: /Fediverse \+ Bluesky/ } )
				.locator( 'xpath=..' ),
			'border-radius'
		)
	).toBeLessThanOrEqual( 8 );

	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=content' );
	await expectNoHorizontalOverflow( page );
	expect(
		await numericCssValueFor(
			page
				.getByRole( 'heading', { name: 'What do you want to share?' } )
				.locator( 'xpath=ancestor::div[3]' ),
			'border-radius'
		)
	).toBeLessThanOrEqual( 8 );
	expect(
		await numericCssValueFor(
			page.getByText( 'Common content types', { exact: true } ),
			'letter-spacing'
		)
	).toBe( 0 );

	await page.setViewportSize( { width: 390, height: 720 } );
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=content' );
	await expectNoHorizontalOverflow( page );
	await expect(
		page.getByRole( 'button', { name: 'Continue' } )
	).toBeVisible();
} );

test( 'Wizard keeps a centered reading column inside wp-admin content', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1103, height: 749 } );
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	const metrics = await page
		.getByRole( 'heading', {
			name: 'Where should your WordPress posts appear?',
		} )
		.evaluate( ( heading ) => {
			const wpBody = document.querySelector( '#wpbody-content' );
			let wizard = heading.parentElement;

			while ( wizard?.parentElement && wizard.parentElement !== wpBody ) {
				wizard = wizard.parentElement;
			}

			if ( ! wpBody || ! wizard ) {
				return null;
			}

			const wpBodyRect = wpBody.getBoundingClientRect();
			const wizardRect = wizard.getBoundingClientRect();

			return {
				centerDelta: Math.abs(
					wpBodyRect.left +
						wpBodyRect.width / 2 -
						( wizardRect.left + wizardRect.width / 2 )
				),
				leftGutter: wizardRect.left - wpBodyRect.left,
				rightGutter: wpBodyRect.right - wizardRect.right,
			};
		} );

	expect( metrics ).not.toBeNull();
	expect( metrics!.centerDelta ).toBeLessThanOrEqual( 1 );
	expect(
		Math.min( metrics!.leftGutter, metrics!.rightGutter )
	).toBeGreaterThanOrEqual( 56 );
} );

test( 'Destination selection navigates to identity step', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await page.getByRole( 'button', { name: 'Continue' } ).click();
	await expect( page ).toHaveURL( /step=appearance/ );
	await expect(
		page.getByRole( 'heading', { name: 'Who should people follow?' } )
	).toBeVisible();
} );

test( 'Appearance step has three actor mode cards', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=appearance' );

	await expect(
		page
			.getByRole( 'group', { name: 'How posts appear' } )
			.getByRole( 'radio' )
	).toHaveCount( 3 );
} );

test( 'Appearance step swaps the visible address preview when the actor mode changes', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=appearance' );

	await expect(
		page.getByText( 'Your fediverse address:', { exact: true } )
	).toBeVisible();

	// Selecting "As your site" exposes the blog preview and the inline
	// Site Handle row, and hides the personal address preview.
	await page.getByText( 'As your site', { exact: true } ).click();

	await expect(
		page.getByText( 'Site fediverse address:', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByText( 'Your fediverse address:', { exact: true } )
	).toBeHidden();
	await expect( page.getByText( 'As you:', { exact: true } ) ).toBeHidden();
	await expect(
		page.getByText( 'As your site:', { exact: true } )
	).toBeHidden();
	await expect( page.getByLabel( 'Site handle' ) ).toBeVisible();

	// "Both" reveals the actor_blog container and keeps the inline Site
	// handle row available for the blog actor.
	await page.getByText( 'Both', { exact: true } ).click();

	await expect( page.getByText( 'As you:', { exact: true } ) ).toBeVisible();
	await expect(
		page.getByText( 'As your site:', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByText( 'Your fediverse address:', { exact: true } )
	).toBeHidden();
	await expect(
		page.getByText( 'Site fediverse address:', { exact: true } )
	).toBeHidden();
	await expect( page.getByLabel( 'Site handle' ) ).toBeVisible();

	// Returning to "As you" hides the inline site handle row again — it
	// only applies when the mode publishes from a blog actor.
	await page.getByText( 'As you', { exact: true } ).click();

	await expect(
		page.getByText( 'Your fediverse address:', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByText( 'Site fediverse address:', { exact: true } )
	).toBeHidden();
	await expect( page.getByText( 'As you:', { exact: true } ) ).toBeHidden();
	await expect(
		page.getByText( 'As your site:', { exact: true } )
	).toBeHidden();
	await expect( page.getByLabel( 'Site handle' ) ).toBeHidden();
} );

test( 'Selecting a mode and continuing saves the option', async ( {
	page,
} ) => {
	await selectDestination( page, 'Fediverse + Bluesky' );

	// Select "As you" (actor mode). Use exact title match to avoid
	// substring collision with "As your site".
	await page.getByText( 'As you', { exact: true } ).click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=bluesky/ );

	// Verify the option was saved by going back to the appearance step
	// and checking the radio is still selected.
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=appearance' );
	await expect(
		page.getByRole( 'radio', { name: /^As you\b/ } )
	).toBeChecked();
} );

test( 'Sharing step saves post types and completes setup', async ( {
	page,
} ) => {
	await selectDestination( page, 'Fediverse + Bluesky' );
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=content' );

	// Posts should be checked by default; check Pages too.
	await page.getByRole( 'checkbox', { name: 'Pages' } ).check();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=complete/ );
} );

test( 'Bluesky step shows connect form', async ( { page } ) => {
	await openBlueskyStep( page );

	await expect(
		page.getByRole( 'heading', { name: 'Connect to Bluesky' } )
	).toBeVisible();
	await expect( page.getByLabel( 'Bluesky handle' ) ).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Connect Bluesky' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'link', { name: 'Skip Bluesky for now' } )
	).toBeVisible();
	await expect( page.locator( 'text=Coming Soon' ) ).toHaveCount( 0 );
} );

test( 'Bluesky step (disconnected) shows sign-up help linking to bsky.app', async ( {
	page,
} ) => {
	await openBlueskyStep( page );

	const signupLink = page.getByRole( 'link', { name: 'Create one' } );
	await expect( signupLink ).toBeVisible();
	await expect( signupLink ).toHaveAttribute( 'href', 'https://bsky.app/' );
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
		await selectDestination( page, 'Fediverse + Bluesky' );
		await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=bluesky' );

		await expect(
			page.getByRole( 'heading', {
				name: 'Review Bluesky connection',
			} )
		).toBeVisible();
		await expect(
			page.getByText( /You can connect Bluesky later/ )
		).toHaveCount( 0 );
		await expect(
			page.locator( 'text=/Bluesky is connected/i' )
		).toHaveCount( 1 );
		await expect( page.getByText( 'alice.bsky.social' ) ).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Create one' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'link', { name: 'Continue' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Finish setup' } )
		).toHaveCount( 0 );
	} );
} );

test( 'Fediverse + Bluesky path visits Bluesky before Sharing', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await page.getByText( 'Fediverse + Bluesky', { exact: true } ).click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=appearance/ );
	await page.getByText( 'As you', { exact: true } ).click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=bluesky/ );
	await page.getByRole( 'link', { name: 'Skip Bluesky for now' } ).click();

	await expect( page ).toHaveURL( /step=content/ );
	await page.getByRole( 'checkbox', { name: 'Posts' } ).check();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=complete/ );
	await expect(
		page.locator( 'dt' ).filter( { hasText: /^Destinations$/ } )
	).toBeVisible();
	await expect( page.getByText( 'Fediverse + Bluesky' ) ).toBeVisible();
} );

test( 'Fediverse-only path skips the Bluesky connect step', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await page.getByText( 'Fediverse only', { exact: true } ).click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=appearance/ );
	await page.getByText( 'As you', { exact: true } ).click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=content/ );
	await page.getByRole( 'checkbox', { name: 'Posts' } ).check();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=complete/ );
	await expect(
		page.locator( 'dt' ).filter( { hasText: /^Destinations$/ } )
	).toBeVisible();
	await expect( page.getByText( 'Fediverse only' ) ).toBeVisible();
	await expect( page.getByText( 'Skipped' ) ).toBeVisible();
} );

test( 'Skip Bluesky for now goes to Sharing', async ( { page } ) => {
	await openBlueskyStep( page );

	await page.getByRole( 'link', { name: 'Skip Bluesky for now' } ).click();
	await expect( page ).toHaveURL( /step=content/ );
	await expect(
		page.getByRole( 'heading', { name: 'What do you want to share?' } )
	).toBeVisible();
} );

test( 'Completion step shows summary', async ( { page } ) => {
	await completeThroughBlueskySkip( page );

	await expect(
		page.locator( 'dt' ).filter( { hasText: /^Destinations$/ } )
	).toBeVisible();
	await expect(
		page.locator( 'dt' ).filter( { hasText: /^Fediverse identity$/ } )
	).toBeVisible();
	await expect(
		page.locator( 'dt' ).filter( { hasText: /^Sharing$/ } )
	).toBeVisible();
	await expect(
		page.locator( 'dt' ).filter( { hasText: /^Bluesky$/ } )
	).toBeVisible();
} );

test( 'Completion step exposes "Publish your first Post" CTA to post-new.php', async ( {
	page,
} ) => {
	await completeThroughBlueskySkip( page );

	await expect(
		page.getByRole( 'heading', { name: 'What happens next' } )
	).toBeVisible();
	await expect(
		page.getByText( 'Publish in WordPress as usual.' )
	).toBeVisible();
	await expect(
		page.getByText(
			'FOSSE shares eligible new public content automatically.'
		)
	).toBeVisible();
	await expect(
		page.getByText(
			'People follow your fediverse address to receive updates.'
		)
	).toBeVisible();

	const publishCta = page.getByRole( 'link', {
		name: 'Publish your first Post',
	} );
	await expect( publishCta ).toBeVisible();
	// Capitalization mirrors the post type's `singular_name` ("Post") so
	// the assertion matches the PHP-side behavior — see PHPUnit
	// `test_render_complete_step_renders_publish_cta`.
	await expect( publishCta ).toHaveAttribute( 'href', /post-new\.php$/ );
} );

test( 'Completion step keeps success header and actions aligned inside the card', async ( {
	page,
} ) => {
	await completeThroughBlueskySkip( page );
	await expect(
		page.getByRole( 'link', { name: 'Publish your first Post' } )
	).toBeVisible();

	type CompletionMetrics = {
		cardBottom: number;
		ctaBottom: number;
		ctaInsideFooter: boolean;
		ctaStartsInFooter: boolean;
		descriptionContainsSetup: boolean;
		descriptionContainsReach: boolean;
		descriptionContainsBluesky: boolean;
		descriptionTopGap: number;
		headerHeight: number;
		helpInsideHeader: boolean;
		helpInsideDescription: boolean;
		helpOutsideFooter: boolean;
		iconAboveTitle: boolean;
		iconCenterDelta: number;
		messageCenterDelta: number;
		iconHeight: number;
		resetBelowCard: boolean;
		resetOutsideCard: boolean;
		descriptionTextAlign: string;
		titleTextAlign: string;
	} | null;

	const metrics = await page
		.getByRole( 'heading', { name: "You're all set!" } )
		.evaluate( ( title ): CompletionMetrics => {
			const message = title.parentElement;
			const header = message?.parentElement;
			const card = header?.parentElement;
			const icon = header?.firstElementChild;
			const description = Array.from(
				message?.querySelectorAll( 'p' ) ?? []
			).find(
				( paragraph ) =>
					paragraph.textContent?.includes(
						'Your sharing setup is ready'
					)
			);
			const help = Array.from(
				description?.querySelectorAll( 'span' ) ?? []
			).find(
				( span ) =>
					span.textContent?.includes(
						'Connect Bluesky to share there too.'
					)
			);
			const cta = Array.from( card?.querySelectorAll( 'a' ) ?? [] ).find(
				( link ) =>
					link.textContent?.trim() === 'Publish your first Post'
			);
			const footer = Array.from( card?.children ?? [] ).find( ( child ) =>
				child.contains( cta ?? null )
			);
			const reset = Array.from( document.querySelectorAll( 'a' ) ).find(
				( link ) => link.textContent?.trim() === 'Run wizard again'
			)?.parentElement;

			if (
				! card ||
				! header ||
				! message ||
				! icon ||
				! description ||
				! help ||
				! footer ||
				! cta ||
				! reset
			) {
				return null;
			}

			const cardRect = card.getBoundingClientRect();
			const headerRect = header.getBoundingClientRect();
			const iconRect = icon.getBoundingClientRect();
			const messageRect = message.getBoundingClientRect();
			const titleRect = title.getBoundingClientRect();
			const descriptionRect = description.getBoundingClientRect();
			const footerRect = footer.getBoundingClientRect();
			const ctaRect = cta.getBoundingClientRect();
			const resetRect = reset.getBoundingClientRect();

			return {
				cardBottom: cardRect.bottom,
				ctaBottom: ctaRect.bottom,
				ctaInsideFooter: footer.contains( cta ),
				ctaStartsInFooter: ctaRect.top >= footerRect.top,
				descriptionContainsSetup:
					description.textContent?.includes(
						'Your sharing setup is ready'
					) ?? false,
				descriptionContainsReach:
					description.textContent?.includes(
						'Your post can reach people'
					) ?? false,
				descriptionContainsBluesky:
					description.textContent?.includes(
						'Connect Bluesky to share there too.'
					) ?? false,
				descriptionTopGap: descriptionRect.top - titleRect.bottom,
				headerHeight: headerRect.height,
				helpInsideHeader: header.contains( help ),
				helpInsideDescription: description.contains( help ),
				helpOutsideFooter: ! footer.contains( help ),
				iconAboveTitle: iconRect.bottom <= titleRect.top - 8,
				iconCenterDelta: Math.abs(
					iconRect.left +
						iconRect.width / 2 -
						( headerRect.left + headerRect.width / 2 )
				),
				messageCenterDelta: Math.abs(
					messageRect.left +
						messageRect.width / 2 -
						( headerRect.left + headerRect.width / 2 )
				),
				iconHeight: iconRect.height,
				resetBelowCard: resetRect.top >= cardRect.bottom + 12,
				resetOutsideCard: ! card.contains( reset ),
				descriptionTextAlign: getComputedStyle( description ).textAlign,
				titleTextAlign: getComputedStyle( title ).textAlign,
			};
		} );

	expect( metrics ).not.toBeNull();
	expect( metrics!.ctaInsideFooter ).toBe( true );
	expect( metrics!.ctaStartsInFooter ).toBe( true );
	expect( metrics!.ctaBottom ).toBeLessThanOrEqual( metrics!.cardBottom + 1 );
	expect( metrics!.descriptionContainsSetup ).toBe( true );
	expect( metrics!.descriptionContainsReach ).toBe( false );
	expect( metrics!.descriptionContainsBluesky ).toBe( true );
	expect( metrics!.descriptionTopGap ).toBeGreaterThanOrEqual( 6 );
	expect( metrics!.descriptionTopGap ).toBeLessThanOrEqual( 14 );
	expect( metrics!.headerHeight ).toBeLessThanOrEqual( 210 );
	expect( metrics!.helpInsideHeader ).toBe( true );
	expect( metrics!.helpInsideDescription ).toBe( true );
	expect( metrics!.helpOutsideFooter ).toBe( true );
	expect( metrics!.iconAboveTitle ).toBe( true );
	expect( metrics!.iconCenterDelta ).toBeLessThanOrEqual( 2 );
	expect( metrics!.messageCenterDelta ).toBeLessThanOrEqual( 2 );
	expect( metrics!.iconHeight ).toBeGreaterThanOrEqual( 52 );
	expect( metrics!.iconHeight ).toBeLessThanOrEqual( 64 );
	expect( metrics!.descriptionTextAlign ).toBe( 'center' );
	expect( metrics!.titleTextAlign ).toBe( 'center' );
	expect( metrics!.resetOutsideCard ).toBe( true );
	expect( metrics!.resetBelowCard ).toBe( true );
} );

// Activation-redirect coverage lives in PHPUnit (MenuTest). The E2E
// version was flaky because Playground's blueprint activates the plugin,
// sets the redirect signal, and consuming it deterministically before
// navigating to the Setup page proved unreliable in CI.

test( 'Skip setup marks wizard complete and goes to Setup page', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await page.getByRole( 'link', { name: 'Skip setup' } ).click();
	await expect( page ).toHaveURL( /page=fosse(?!-)/ );

	// Wizard notice should not appear since wizard is now complete.
	await expect( page.locator( 'text=Run the setup wizard' ) ).toHaveCount(
		0
	);

	await expect(
		page.getByRole( 'note' ).filter( { hasText: 'Want a guided setup?' } )
	).toHaveCount( 0 );
	await expect(
		page.getByRole( 'link', { name: 'Run the wizard' } )
	).toHaveAttribute( 'href', /page=fosse-wizard/ );
} );

test( 'Wizard is not visible in the admin sidebar menu', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await expect(
		page.locator( '#adminmenu a', { hasText: 'Setup Wizard' } )
	).toHaveCount( 0 );
} );
