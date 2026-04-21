/** @type {import('jest').Config} */
module.exports = {
	testEnvironment: 'jsdom',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	testPathIgnorePatterns: [ '/node_modules/', '/bundled/' ],
	transform: {},
};
