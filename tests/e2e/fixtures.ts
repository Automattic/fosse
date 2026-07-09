import { test as base, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/*
 * Shared e2e test object.
 *
 * Forces `prefers-reduced-motion: reduce` on every page before the test
 * body runs. The wizard's cards animate on `:hover`/selection (e.g.
 * `.fosse-mode-card:hover`'s 1px translate); the admin CSS already
 * neutralizes those under the reduced-motion media query. Without the
 * emulation, Playwright's click actionability intermittently races the
 * ongoing transform and times out — reliably so under @playwright/test
 * 1.61's stricter "stable" wait. We emulate here rather than via
 * `use.reducedMotion` because that config option was not taking effect
 * in this project setup. See #225.
 */
export const test = base.extend( {
	page: async ( { page }, use ) => {
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		/*
		 * `use` here is Playwright's fixture-teardown callback, not a React
		 * hook — the react-hooks rule misfires on the name.
		 */
		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( page );
	},
} );

export { expect };
export type { Page };
