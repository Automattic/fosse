import { type Browser, expect, type Page } from '@playwright/test';

/**
 * Seed the Atmosphere connection state via the e2e REST helper.
 *
 * The mu-plugin under tests/e2e/mu-plugins/fosse-bsky-capture.php exposes
 * /wp-json/fosse-e2e/v1/bluesky-state behind a manage_options gate so
 * Playwright specs can flip the connection between tests without spinning
 * up a real PDS or running the OAuth dance.
 *
 * Lives at the e2e root so multiple specs share one definition — the
 * endpoint path, headers, and response assertions stay in one place.
 *
 * @param {Page}                    page Playwright page; must be on a wp-admin
 *                                       screen so wpApiSettings.nonce is available.
 * @param {Record<string, unknown>} body Connection state payload (connected,
 *                                       handle, did, pds_endpoint, auto_publish).
 * @return {Promise<void>} Resolves when the seed succeeds; throws if not 200.
 */
export const setBlueskyState = async (
	page: Page,
	body: Record< string, unknown >
) => {
	const result = await page.evaluate( async ( payload ) => {
		const res = await fetch( '/wp-json/fosse-e2e/v1/bluesky-state', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ( window as any ).wpApiSettings.nonce,
			},
			body: JSON.stringify( payload ),
		} );

		return { status: res.status, text: await res.text() };
	}, body );

	expect(
		result.status,
		`fosse-e2e/v1/bluesky-state returned: ${ result.text.slice( 0, 300 ) }`
	).toBe( 200 );
};

/**
 * Reset the Atmosphere connection back to disconnected from a hook scope
 * (`afterAll`, `afterEach`) where only the `browser` fixture is available.
 *
 * `browser.newPage()` does NOT inherit the project's `baseURL` from
 * `playwright.config.ts`, so this helper passes it explicitly. Without it,
 * `page.goto( '/wp-admin/...' )` would fail because there's no base to
 * resolve the relative URL against. The page is closed in a `finally` so
 * a seed failure can't leak browser contexts.
 *
 * @param {Browser} browser Playwright browser fixture.
 * @param {string}  baseURL Project base URL (read via `testInfo.project.use.baseURL`).
 * @return {Promise<void>} Resolves once the disconnect seed completes.
 */
export const resetBlueskyState = async (
	browser: Browser,
	baseURL: string
): Promise< void > => {
	const page = await browser.newPage( { baseURL } );
	try {
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction(
			() => !! ( window as any ).wpApiSettings?.nonce
		);
		await setBlueskyState( page, {
			connected: false,
			auto_publish: true,
		} );
	} finally {
		await page.close();
	}
};
