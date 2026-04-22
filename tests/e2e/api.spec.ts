/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

import { test, expect, APIRequestContext, request as playwrightRequest } from '@playwright/test';

// All tests run serially — each step builds on the previous one's state.
test.describe.configure({ mode: 'serial' });

const API = '/api/v1';

let apiCtx: APIRequestContext;
let accessToken: string;

test.beforeAll(async () => {
	apiCtx = await playwrightRequest.newContext({
		baseURL: process.env.API_BASE_URL ?? 'http://localhost:8181',
		extraHTTPHeaders: {
			'Content-Type': 'application/json',
			'Accept':       'application/json',
		},
	});
});

test.afterAll(async () => {
	await apiCtx.dispose();
});

/** Returns Authorization header with the acquired access token. */
function auth(): Record<string, string> {
	return { Authorization: `Bearer ${accessToken}` };
}

// ---------------------------------------------------------------------------
// 1. Login
// ---------------------------------------------------------------------------
test('POST /auth/login — returns access token', async () => {
	const res = await apiCtx.post(`${API}/auth/login`, {
		data: { username: 'alice', password: 'testpass' },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data).toMatchObject({
		accessToken:  expect.any(String),
		refreshToken: expect.any(String),
		expiresIn:    900,
	});

	// Persist token for subsequent tests
	accessToken = body.data.accessToken;
	expect(accessToken.split('.').length).toBe(3); // valid JWT structure
});

// ---------------------------------------------------------------------------
// 2. /me
// ---------------------------------------------------------------------------
test('GET /me — returns authenticated user', async () => {
	const res = await apiCtx.get(`${API}/me`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data).toMatchObject({
		id:       expect.any(Number),
		username: expect.any(String),
		email:    expect.any(String),
	});
});

// ---------------------------------------------------------------------------
// 3 & 4. /users/:id
// ---------------------------------------------------------------------------
test('GET /users/1 — public profile of user 1 (no email)', async () => {
	const res = await apiCtx.get(`${API}/users/1`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.id).toBe(1);
	expect(data.username).toBeDefined();
	expect(data.email).toBeUndefined(); // public profile omits email
});

test('GET /users/2 — public profile of user 2 (no email)', async () => {
	const res = await apiCtx.get(`${API}/users/2`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.id).toBe(2);
	expect(data.email).toBeUndefined();
});

// ---------------------------------------------------------------------------
// 5. /forums
// ---------------------------------------------------------------------------
test('GET /forums — returns forum list with meta', async () => {
	const res = await apiCtx.get(`${API}/forums`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.data.length).toBeGreaterThanOrEqual(2);
	expect(body.meta.total).toBeGreaterThanOrEqual(2);
});

// ---------------------------------------------------------------------------
// 6. /forums/2
// ---------------------------------------------------------------------------
test('GET /forums/2 — returns forum 2', async () => {
	const res = await apiCtx.get(`${API}/forums/2`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.id).toBe(2);
	expect(data.title).toBeDefined();
});

// ---------------------------------------------------------------------------
// 7. /forums/1
// ---------------------------------------------------------------------------
test('GET /forums/1 — returns forum 1', async () => {
	const res = await apiCtx.get(`${API}/forums/1`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.id).toBe(1);
	expect(data.title).toBeDefined();
});

// ---------------------------------------------------------------------------
// 8. /forums/999 — non-existing → 404
// ---------------------------------------------------------------------------
test('GET /forums/999 — 404 for non-existing forum', async () => {
	const res = await apiCtx.get(`${API}/forums/999`, { headers: auth() });

	expect(res.status()).toBe(404);

	const body = await res.json();
	expect(body.status ?? body.error).toBeTruthy();
});

// ---------------------------------------------------------------------------
// 9. /forums/1/topics
// ---------------------------------------------------------------------------
test('GET /forums/1/topics — returns topics for forum 1', async () => {
	const res = await apiCtx.get(`${API}/forums/1/topics`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.data.length).toBeGreaterThanOrEqual(2);
	expect(body.meta).toMatchObject({
		total:    expect.any(Number),
		page:     1,
		perPage:  expect.any(Number),
		lastPage: expect.any(Number),
	});
});

// ---------------------------------------------------------------------------
// 10. /topics/2
// ---------------------------------------------------------------------------
test('GET /topics/2 — returns topic 2', async () => {
	const res = await apiCtx.get(`${API}/topics/2`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.id).toBe(2);
	expect(data.title).toBeDefined();
});

// ---------------------------------------------------------------------------
// 11. /topics/999 — non-existing → 404
// ---------------------------------------------------------------------------
test('GET /topics/999 — 404 for non-existing topic', async () => {
	const res = await apiCtx.get(`${API}/topics/999`, { headers: auth() });

	expect(res.status()).toBe(404);

	const body = await res.json();
	expect(body.status ?? body.error).toBeTruthy();
});

// ---------------------------------------------------------------------------
// 12. /api/v1/nonexistent — invalid path → 404 JSON
// ---------------------------------------------------------------------------
test('GET /nonexistent — 404 JSON for unknown API path', async () => {
	const res = await apiCtx.get(`${API}/nonexistent-route`, { headers: auth() });

	expect(res.status()).toBe(404);

	// ExceptionSubscriber must return JSON for all /api/* paths
	const body = await res.json();
	expect(body).toBeTruthy();
});

// ---------------------------------------------------------------------------
// 13. /users/1 without token → 401
// ---------------------------------------------------------------------------
test('GET /users/1 without token — 401', async () => {
	const res = await apiCtx.get(`${API}/users/1`);

	expect(res.status()).toBe(401);
});

// ---------------------------------------------------------------------------
// 14. /topics/2 without token → 200 (accessLevel=0, public)
// ---------------------------------------------------------------------------
test('GET /topics/2 without token — 200 (public topic)', async () => {
	const res = await apiCtx.get(`${API}/topics/2`);

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.id).toBe(2);
	expect(data.accessLevel).toBe(0);
});

// ---------------------------------------------------------------------------
// 15. /topics/3 without token → 401 (accessLevel=1, login required)
// ---------------------------------------------------------------------------
test('GET /topics/3 without token — 401 (login required)', async () => {
	const res = await apiCtx.get(`${API}/topics/3`);

	expect(res.status()).toBe(401);

	const body = await res.json();
	expect(body.error).toBeDefined();
});

// ---------------------------------------------------------------------------
// 16. /topics/4 without token → 401 (accessLevel=2, password required)
// ---------------------------------------------------------------------------
test('GET /topics/4 without token — 401 (password required)', async () => {
	const res = await apiCtx.get(`${API}/topics/4`);

	expect(res.status()).toBe(401);

	const body = await res.json();
	expect(body.error).toBeDefined();
});
