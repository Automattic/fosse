import wp from '@wordpress/eslint-plugin';
import jestPlugin from 'eslint-plugin-jest';

export default [
	{
		ignores: [
			'build/**',
			'bundled/**',
			'vendor/**',
			'wordpress/**',
			'node_modules/**',
			'tools/vendor/**',
			'playwright-report/**',
			'test-results/**',
			'coverage/**',
			'.claude/**',
		],
	},
	...wp.configs.recommended,
	{
		files: [ 'tests/js/**/*.{js,jsx}' ],
		plugins: { jest: jestPlugin },
		languageOptions: {
			globals: jestPlugin.environments.globals.globals,
		},
		rules: {
			'@wordpress/i18n-text-domain': 'off',
			'@wordpress/i18n-no-variables': 'off',
		},
	},
	{
		files: [ 'tests/e2e/**/*.{ts,tsx,js}' ],
		rules: {
			'@wordpress/i18n-text-domain': 'off',
			'@wordpress/i18n-no-variables': 'off',
		},
	},
];
