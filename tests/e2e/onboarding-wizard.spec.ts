import { test, expect, type Page } from '@playwright/test';
import {
	expectNoHorizontalOverflow,
	numericCssValue,
	resetBlueskyState,
	setBlueskyState,
} from './test-helpers';

const selectDestination = async ( page: Page, destination: string ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );
	await page
		.locator( '.fosse-destination-card', { hasText: destination } )
		.click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();
	await expect( page ).toHaveURL( /step=appearance/ );
};

const openBlueskyStep = async ( page: Page ) => {
	await selectDestination( page, 'Fediverse + Bluesky' );
	// This helper intentionally jumps to Bluesky after setting destination
	// intent; it does not seed appearance/content state, so keep full-flow
	// dependencies covered by the sequential wizard specs below.
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=bluesky' );
};

const completeThroughBlueskySkip = async ( page: Page ) => {
	await openBlueskyStep( page );
	await page.getByRole( 'link', { name: 'Skip Bluesky for now' } ).click();
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
		page.locator( '.fosse-wizard__title', {
			hasText: 'Where should your WordPress posts appear?',
		} )
	).toBeVisible();
} );

test( 'Wizard shows progress indicator on destination step', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await expect( page.locator( '.fosse-wizard__progress' ) ).toBeVisible();
	await expect(
		page.locator( '.fosse-wizard__progress-step.is-active', {
			hasText: 'Destinations',
		} )
	).toBeVisible();
} );

test( 'Destination step shows two destination cards', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await expect( page.locator( '.fosse-wizard__description' ) ).toContainText(
		'Fediverse apps like Mastodon'
	);
	const wizardCard = page.locator( '.fosse-wizard__card.fosse-admin-card' );
	await expect( wizardCard ).toBeVisible();
	await expect(
		wizardCard.locator( '.fosse-card-header .fosse-wizard__title' )
	).toContainText( 'Where should your WordPress posts appear?' );
	await expect( wizardCard.locator( '.fosse-card-body' ) ).toBeVisible();
	await expect(
		wizardCard.locator( '.fosse-card-footer .fosse-wizard__actions' )
	).toBeVisible();
	await expect( page.locator( '.fosse-destination-card' ) ).toHaveCount( 2 );
	await expect( page.locator( '.fosse-choice-card' ) ).toHaveCount( 2 );
	await expect(
		page.locator( '.fosse-destination-card', {
			hasText: 'Fediverse apps like Mastodon',
		} )
	).toHaveCount( 2 );
	await expect(
		page.locator( '.fosse-destination-card', {
			hasText: 'Fediverse + Bluesky',
		} )
	).toBeVisible();
	await expect(
		page.locator( '.fosse-destination-card', {
			hasText: 'Fediverse only',
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

	const metrics = await page.evaluate( () => {
		const skip = document.querySelector( '.fosse-wizard__skip' );
		const submit = document.querySelector(
			'.fosse-wizard__actions input[type="submit"]'
		);

		if ( ! skip || ! submit ) {
			return null;
		}

		const skipRect = skip.getBoundingClientRect();
		const submitRect = submit.getBoundingClientRect();

		return {
			centerDelta: Math.abs(
				skipRect.top +
					skipRect.height / 2 -
					( submitRect.top + submitRect.height / 2 )
			),
			skipBeforeSubmit: skipRect.right <= submitRect.left,
		};
	} );

	expect( metrics ).not.toBeNull();
	expect( metrics!.centerDelta ).toBeLessThanOrEqual( 4 );
	expect( metrics!.skipBeforeSubmit ).toBe( true );
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
	await expect(
		page.locator(
			'.fosse-wizard__progress-step.is-active .fosse-wizard__progress-label',
			{ hasText: 'Destinations' }
		)
	).toBeVisible();

	const progressMetrics = await page
		.locator( '.fosse-wizard__progress' )
		.evaluate( ( el ) => {
			const unavailableLabels = Array.from(
				el.querySelectorAll( '.fosse-wizard__progress-label' )
			).filter( ( label ) => {
				const style = window.getComputedStyle( label );
				return (
					'hidden' === style.visibility || 'none' === style.display
				);
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
		await numericCssValue( page, '.fosse-wizard__title', 'letter-spacing' )
	).toBe( 0 );
	expect(
		await numericCssValue(
			page,
			'.fosse-destination-card__badge',
			'letter-spacing'
		)
	).toBe( 0 );
	expect(
		await numericCssValue( page, '.fosse-choice-card', 'border-radius' )
	).toBeLessThanOrEqual( 8 );

	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=content' );
	await expectNoHorizontalOverflow( page );
	expect(
		await numericCssValue( page, '.fosse-wizard__card', 'border-radius' )
	).toBeLessThanOrEqual( 8 );
	expect(
		await numericCssValue(
			page,
			'.fosse-post-types__group-label',
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

	const metrics = await page.evaluate( () => {
		const wpBody = document.querySelector( '#wpbody-content' );
		const wizard = document.querySelector( '.fosse-wizard' );

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
		page.locator( '.fosse-wizard__title', {
			hasText: 'Who should people follow?',
		} )
	).toBeVisible();
} );

test( 'Appearance step has three actor mode cards', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=appearance' );

	await expect( page.locator( '.fosse-mode-card' ) ).toHaveCount( 3 );
} );

test( 'Appearance step swaps the visible address preview when the actor mode changes', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=appearance' );

	// Three preview containers render server-side, one per mode. JS toggles
	// `is-hidden` on radio change; only the matching container should be
	// visible at any given time.
	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode]' )
	).toHaveCount( 3 );
	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode="actor"]' )
	).toContainText( 'Your fediverse address:' );

	// Selecting "As your site" exposes the blog preview and the inline
	// Site Handle row, and hides the personal address preview.
	await page
		.locator( '.fosse-mode-card', {
			has: page.locator( '.fosse-mode-card__title', {
				hasText: /^As your site$/,
			} ),
		} )
		.click();

	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode="blog"]' )
	).toBeVisible();
	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode="blog"]' )
	).toContainText( 'Site fediverse address:' );
	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode="actor"]' )
	).toBeHidden();
	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode="actor_blog"]' )
	).toBeHidden();
	await expect(
		page.locator( '[data-fosse-when="includes-blog"]' )
	).toBeVisible();
	await expect(
		page.locator( '#fosse-wizard-blog-identifier' )
	).toBeVisible();

	// "Both" reveals the actor_blog container instead.
	await page
		.locator( '.fosse-mode-card', {
			has: page.locator( '.fosse-mode-card__title', {
				hasText: /^Both$/,
			} ),
		} )
		.click();

	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode="actor_blog"]' )
	).toBeVisible();
	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode="blog"]' )
	).toBeHidden();
	await expect(
		page.locator( '[data-fosse-when="includes-blog"]' )
	).toBeVisible();

	// Returning to "As you" hides the inline site handle row again — it
	// only applies when the mode publishes from a blog actor.
	await page
		.locator( '.fosse-mode-card', {
			has: page.locator( '.fosse-mode-card__title', {
				hasText: /^As you$/,
			} ),
		} )
		.click();

	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode="actor"]' )
	).toBeVisible();
	await expect(
		page.locator( '.fosse-address-preview[data-fosse-mode="blog"]' )
	).toBeHidden();
	await expect(
		page.locator( '[data-fosse-when="includes-blog"]' )
	).toBeHidden();
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
	// Persist the Fediverse + Bluesky destination so the post-content
	// redirect deterministically lands on the Bluesky step regardless of any
	// stale destination state left behind by a prior run.
	await selectDestination( page, 'Fediverse + Bluesky' );
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard&step=content' );

	// Posts should be checked by default; check Pages too.
	await page.locator( '.fosse-post-type-item', { hasText: 'Pages' } ).click();
	await page.click( 'input[type="submit"]' );

	await expect( page ).toHaveURL( /step=bluesky/ );
} );

test( 'Bluesky step shows connect form', async ( { page } ) => {
	await openBlueskyStep( page );

	await expect(
		page.locator( '.fosse-wizard__title', {
			hasText: 'Connect to Bluesky',
		} )
	).toBeVisible();
	await expect( page.locator( '#fosse-bsky-handle' ) ).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Connect Bluesky' } )
	).toHaveClass( /button-primary/ );
	await expect(
		page.getByRole( 'link', { name: 'Skip Bluesky for now' } )
	).toBeVisible();
	await expect( page.locator( 'text=Coming Soon' ) ).toHaveCount( 0 );
} );

test( 'Bluesky step (disconnected) shows sign-up help linking to bsky.app', async ( {
	page,
} ) => {
	await openBlueskyStep( page );

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
				hasText: 'Review Bluesky connection',
			} )
		).toBeVisible();
		await expect(
			page.locator( '.fosse-wizard__description' )
		).not.toContainText( 'connect later' );
		await expect(
			page.locator( 'text=/Bluesky is connected/i' )
		).toHaveCount( 1 );
		await expect( page.locator( '.fosse-detail-list' ) ).toContainText(
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

test( 'Fediverse-only path skips the Bluesky connect step', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await page
		.locator( '.fosse-destination-card', { hasText: 'Fediverse only' } )
		.click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=appearance/ );
	await page
		.locator( '.fosse-mode-card', {
			has: page.locator( '.fosse-mode-card__title', {
				hasText: /^As you$/,
			} ),
		} )
		.click();
	await page.click( 'input[type="submit"]' );

	await expect( page ).toHaveURL( /step=content/ );
	await page.click( 'input[type="submit"]' );

	await expect( page ).toHaveURL( /step=complete/ );
	await expect(
		page.locator( '.fosse-detail-list__term', { hasText: 'Destinations' } )
	).toBeVisible();
	await expect( page.locator( '.fosse-detail-list' ) ).toContainText(
		'Fediverse only'
	);
	await expect( page.locator( '.fosse-detail-list' ) ).toContainText(
		'Skipped'
	);
} );

test( 'Skip Bluesky for now goes to completion', async ( { page } ) => {
	await openBlueskyStep( page );

	await page.getByRole( 'link', { name: 'Skip Bluesky for now' } ).click();
	await expect( page ).toHaveURL( /step=complete/ );
	await expect(
		page.locator( '.fosse-wizard__title', {
			hasText: "You're all set",
		} )
	).toBeVisible();
} );

test( 'Completion step shows summary', async ( { page } ) => {
	await completeThroughBlueskySkip( page );

	await expect( page.locator( '.fosse-detail-list' ) ).toBeVisible();
	await expect(
		page.locator( '.fosse-detail-list__term', { hasText: 'Destinations' } )
	).toBeVisible();
	await expect( page.locator( 'text=Fediverse identity' ) ).toBeVisible();
	await expect( page.locator( 'text=Content types' ) ).toBeVisible();
	await expect(
		page.locator( '.fosse-detail-list__term', { hasText: 'Bluesky' } )
	).toBeVisible();
} );

test( 'Completion step exposes "Publish your first Post" CTA to post-new.php', async ( {
	page,
} ) => {
	await completeThroughBlueskySkip( page );

	const publishCta = page.locator( '.fosse-wizard__cta-publish' );
	await expect( publishCta ).toBeVisible();
	// Capitalization mirrors the post type's `singular_name` ("Post") so
	// the assertion matches the PHP-side behavior — see PHPUnit
	// `test_render_complete_step_renders_publish_cta`.
	await expect( publishCta ).toContainText( 'Publish your first Post' );
	await expect( publishCta ).toHaveAttribute( 'href', /post-new\.php$/ );
} );

test( 'Completion step keeps success header and actions aligned inside the card', async ( {
	page,
} ) => {
	await completeThroughBlueskySkip( page );
	await expect(
		page.locator(
			'.fosse-wizard__complete-card .fosse-wizard__completion-footer .fosse-wizard__cta-publish'
		)
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
		oldTitleRowRemoved: boolean;
		iconHeight: number;
		resetBelowCard: boolean;
		resetOutsideCard: boolean;
		descriptionTextAlign: string;
		titleTextAlign: string;
	} | null;

	const metrics = await page.evaluate( (): CompletionMetrics => {
		const card = document.querySelector( '.fosse-wizard__complete-card' );
		const header = card?.querySelector( '.fosse-wizard__complete-header' );
		const message = card?.querySelector(
			'.fosse-wizard__complete-message'
		);
		const icon = card?.querySelector( '.fosse-complete-icon' );
		const title = card?.querySelector( '.fosse-wizard__title' );
		const description = card?.querySelector(
			'.fosse-wizard__complete-message .fosse-wizard__description'
		);
		const help = card?.querySelector( '.fosse-wizard__cta-help' );
		const oldTitleRow = card?.querySelector(
			'.fosse-wizard__complete-title-row'
		);
		const footer = card?.querySelector(
			'.fosse-wizard__completion-footer'
		);
		const cta = card?.querySelector( '.fosse-wizard__cta-publish' );
		const reset = document.querySelector( '.fosse-wizard__reset' );

		if (
			! card ||
			! header ||
			! message ||
			! icon ||
			! title ||
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
			oldTitleRowRemoved: ! oldTitleRow,
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
	expect( metrics!.oldTitleRowRemoved ).toBe( true );
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
