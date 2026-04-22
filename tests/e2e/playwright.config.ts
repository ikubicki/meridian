import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: '.',
	testMatch: '**/*.spec.ts',
	use: {
		baseURL: process.env.API_BASE_URL ?? 'http://localhost:8181',
		extraHTTPHeaders: {
			'Content-Type': 'application/json',
			'Accept':       'application/json',
		},
	},
	// Sequential: E2E journey shares state (access token) across tests
	workers: 1,
	retries: 0,
	reporter: [['list'], ['html', { open: 'never' }]],
});
