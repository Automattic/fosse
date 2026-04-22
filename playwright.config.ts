import { defineConfig, devices } from '@playwright/test';

const PORT = 9400;
const BASE_URL = `http://127.0.0.1:${ PORT }`;

export default defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI
		? [ [ 'github' ], [ 'html', { open: 'never' } ] ]
		: 'list',
	use: {
		baseURL: BASE_URL,
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	webServer: {
		command: `pnpm exec wp-playground-cli server --port ${ PORT } --blueprint tests/e2e/blueprint.json --mount ${ process.cwd() }:/wordpress/wp-content/plugins/fosse`,
		url: `${ BASE_URL }/readme.html`,
		reuseExistingServer: ! process.env.CI,
		timeout: 120_000,
		stdout: 'pipe',
		stderr: 'pipe',
	},
} );
