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
let refreshToken: string;
let adminToken: string;
let adminElevatedToken: string;
let createdForumId: number;
let createdForumId2: number;
let createdTopicId: number;

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
	accessToken  = body.data.accessToken;
	refreshToken = body.data.refreshToken;
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
// 2b. /users — returns paginated user list
// ---------------------------------------------------------------------------
test('GET /users — returns paginated user list', async () => {
	const res = await apiCtx.get(`${API}/users`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.meta).toMatchObject({
		total:      expect.any(Number),
		page:       1,
		perPage:    expect.any(Number),
		totalPages: expect.any(Number),
	});
	expect(body.meta.total).toBeGreaterThanOrEqual(1);
});

// ---------------------------------------------------------------------------
// 2c. /users?q= — filtered user search
// ---------------------------------------------------------------------------
test('GET /users?q=admin — returns matching users', async () => {
	const res = await apiCtx.get(`${API}/users?q=admin`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	for (const user of body.data) {
		expect(user.username.toLowerCase()).toContain('admin');
	}
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
// 14. /topics/2 without token → 200 (GUESTS have f_read via ROLE_FORUM_READONLY)
// ---------------------------------------------------------------------------
test('GET /topics/2 without token — 200 (GUESTS have f_read)', async () => {
	const res = await apiCtx.get(`${API}/topics/2`);

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.id).toBe(2);
	expect(data.title).toBeDefined();
});

// ---------------------------------------------------------------------------
// M3-1. POST /auth/login with wrong credentials → 401
// ---------------------------------------------------------------------------
test('POST /auth/login — 401 on wrong password', async () => {
	const res = await apiCtx.post(`${API}/auth/login`, {
		data: { username: 'alice', password: 'wrongpassword' },
	});

	expect(res.status()).toBe(401);
});

// ---------------------------------------------------------------------------
// M3-2. POST /auth/refresh — rotates tokens
// ---------------------------------------------------------------------------
test('POST /auth/refresh — returns new tokens', async () => {
	const res = await apiCtx.post(`${API}/auth/refresh`, {
		data: { refreshToken },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data).toMatchObject({
		accessToken:  expect.any(String),
		refreshToken: expect.any(String),
		expiresIn:    900,
	});

	// Update tokens for subsequent tests
	accessToken  = body.data.accessToken;
	refreshToken = body.data.refreshToken;
});

// ---------------------------------------------------------------------------
// M3-3. POST /auth/elevate — issues elevated token
// ---------------------------------------------------------------------------
test('POST /auth/elevate — returns elevated token', async () => {
	const res = await apiCtx.post(`${API}/auth/elevate`, {
		data: { password: 'testpass' },
		headers: auth(),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data).toMatchObject({
		elevatedToken: expect.any(String),
		expiresIn:     300,
	});

	// Validate JWT structure
	expect(body.data.elevatedToken.split('.').length).toBe(3);
});

// ---------------------------------------------------------------------------
// 17. GET /health
// ---------------------------------------------------------------------------
test('GET /health — returns ok status', async () => {
	const res = await apiCtx.get(`${API}/health`);

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.status).toBe('ok');
});

// ---------------------------------------------------------------------------
// 18. Admin login (user_type=3 Founder — required for forum writes)
// ---------------------------------------------------------------------------
test('POST /auth/login as admin — returns admin token', async () => {
	const res = await apiCtx.post(`${API}/auth/login`, {
		data: { username: 'admin', password: 'admin' },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	adminToken = body.data.accessToken;
	expect(adminToken.split('.').length).toBe(3);
});

test('POST /auth/elevate as admin — returns elevated token', async () => {
	const res = await apiCtx.post(`${API}/auth/elevate`, {
		data: { password: 'admin' },
		headers: { Authorization: `Bearer ${adminToken}` },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	adminElevatedToken = body.data.elevatedToken;
	expect(adminElevatedToken.split('.').length).toBe(3);
});

// ---------------------------------------------------------------------------
// 19. POST /forums — auth guards
// ---------------------------------------------------------------------------
test('POST /forums without token — 401', async () => {
	const res = await apiCtx.post(`${API}/forums`, {
		data: { name: 'Unauthorized Forum', type: 1, parent_id: 0 },
	});

	expect(res.status()).toBe(401);
});

test('POST /forums with regular access token (no elevation) — 401', async () => {
	// Both alice's token and even admin's regular access token are not elevated tokens
	const res = await apiCtx.post(`${API}/forums`, {
		data: { name: 'Unauthorized Forum', type: 1, parent_id: 0 },
		headers: auth(),
	});

	expect(res.status()).toBe(401);
});

test('POST /forums with admin access token (not elevated) — 401', async () => {
	const res = await apiCtx.post(`${API}/forums`, {
		data: { name: 'Unauthorized Forum', type: 1, parent_id: 0 },
		headers: { Authorization: `Bearer ${adminToken}` },
	});

	expect(res.status()).toBe(401);
});

// ---------------------------------------------------------------------------
// 20. POST /forums — create
// ---------------------------------------------------------------------------
test('POST /forums as admin — creates forum and returns 201', async () => {
	const res = await apiCtx.post(`${API}/forums`, {
		data: { name: 'E2E Test Forum', type: 1, parent_id: 0, description: 'Created by e2e test' },
		headers: { Authorization: `Bearer ${adminElevatedToken}` },
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	expect(body.status).toBe('created');
});

test('GET /forums — created forum appears in list', async () => {
	const res = await apiCtx.get(`${API}/forums`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	const found = body.data.find((f: { title: string; id: number }) => f.title === 'E2E Test Forum');
	expect(found).toBeDefined();
	createdForumId = found.id;
});

// ---------------------------------------------------------------------------
// 21. PUT /forums/{id}
// ---------------------------------------------------------------------------
test('PUT /forums/{id} without token — 401', async () => {
	const res = await apiCtx.put(`${API}/forums/${createdForumId}`, {
		data: { name: 'Hacked' },
	});

	expect(res.status()).toBe(401);
});

test('PUT /forums/{id} as admin — updates forum name', async () => {
	const res = await apiCtx.put(`${API}/forums/${createdForumId}`, {
		data: { name: 'E2E Test Forum (Updated)' },
		headers: { Authorization: `Bearer ${adminElevatedToken}` },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.status).toBe('updated');
});

test('GET /forums/{id} — reflects updated name', async () => {
	const res = await apiCtx.get(`${API}/forums/${createdForumId}`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.title).toBe('E2E Test Forum (Updated)');
});

// ---------------------------------------------------------------------------
// 22. PATCH /forums/{id}/move
// ---------------------------------------------------------------------------
test('POST /forums as admin — creates category for move target', async () => {
	const res = await apiCtx.post(`${API}/forums`, {
		data: { name: 'E2E Move Target Category', type: 0, parent_id: 0 },
		headers: { Authorization: `Bearer ${adminElevatedToken}` },
	});

	expect(res.status()).toBe(201);

	const listRes = await apiCtx.get(`${API}/forums`, { headers: { Authorization: `Bearer ${adminElevatedToken}` } });
	const body = await listRes.json();
	const found = body.data.find((f: { title: string; id: number }) => f.title === 'E2E Move Target Category');
	expect(found).toBeDefined();
	createdForumId2 = found.id;
});

test('PATCH /forums/{id}/move without token — 401', async () => {
	const res = await apiCtx.patch(`${API}/forums/${createdForumId}/move`, {
		data: { new_parent_id: createdForumId2 },
	});

	expect(res.status()).toBe(401);
});

test('PATCH /forums/{id}/move as admin — moves forum under category', async () => {
	const res = await apiCtx.patch(`${API}/forums/${createdForumId}/move`, {
		data: { new_parent_id: createdForumId2 },
		headers: { Authorization: `Bearer ${adminElevatedToken}` },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.status).toBe('moved');
});

test('GET /forums/{id} — reflects new parentId after move', async () => {
	const res = await apiCtx.get(`${API}/forums/${createdForumId}`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.parentId).toBe(createdForumId2);
});

// ---------------------------------------------------------------------------
// 23. DELETE /forums/{id}
// ---------------------------------------------------------------------------
test('DELETE /forums/{id} without token — 401', async () => {
	const res = await apiCtx.delete(`${API}/forums/${createdForumId}`);

	expect(res.status()).toBe(401);
});

test('DELETE /forums/{id} with children — 400', async () => {
	// createdForumId2 is a parent of createdForumId — deleting it must fail
	const res = await apiCtx.delete(`${API}/forums/${createdForumId2}`, {
		headers: { Authorization: `Bearer ${adminElevatedToken}` },
	});

	expect(res.status()).toBe(400);
});

test('DELETE /forums/{id} as admin — deletes leaf forum', async () => {
	const res = await apiCtx.delete(`${API}/forums/${createdForumId}`, {
		headers: { Authorization: `Bearer ${adminElevatedToken}` },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.status).toBe('deleted');
});

test('GET /forums/{id} — 404 after delete', async () => {
	const res = await apiCtx.get(`${API}/forums/${createdForumId}`, { headers: auth() });

	expect(res.status()).toBe(404);
});

test('DELETE /forums/{id} as admin — deletes second forum (cleanup)', async () => {
	const res = await apiCtx.delete(`${API}/forums/${createdForumId2}`, {
		headers: { Authorization: `Bearer ${adminElevatedToken}` },
	});

	expect(res.status()).toBe(200);
});

// ---------------------------------------------------------------------------
// 24. POST /auth/logout
// ---------------------------------------------------------------------------
test('POST /auth/logout without token — 401', async () => {
	const res = await apiCtx.post(`${API}/auth/logout`);

	expect(res.status()).toBe(401);
});

test('POST /auth/logout — returns 204 and invalidates session', async () => {
	// Use a fresh admin login to avoid invalidating the shared accessToken
	const loginRes = await apiCtx.post(`${API}/auth/login`, {
		data: { username: 'admin', password: 'admin' },
	});
	const { data } = await loginRes.json();
	const tempToken = data.accessToken;

	const res = await apiCtx.post(`${API}/auth/logout`, {
		headers: { Authorization: `Bearer ${tempToken}` },
	});

	expect(res.status()).toBe(204);
});

// ---------------------------------------------------------------------------
// 25. POST /forums/{id}/topics — auth guards
// ---------------------------------------------------------------------------
test('POST /forums/1/topics without token — 401', async () => {
	const res = await apiCtx.post(`${API}/forums/1/topics`, {
		data: { title: 'Unauthorized Topic', content: 'Should be rejected' },
	});

	expect(res.status()).toBe(401);
});

// ---------------------------------------------------------------------------
// 26. POST /forums/{id}/topics — alice creates a topic
// ---------------------------------------------------------------------------
test('POST /forums/1/topics as alice — 201 (f_post granted via REGISTERED group)', async () => {
	const res = await apiCtx.post(`${API}/forums/1/topics`, {
		data: { title: 'Alice Test Topic', content: 'Hello from alice!' },
		headers: auth(),
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	expect(body.data).toMatchObject({
		id:      expect.any(Number),
		title:   'Alice Test Topic',
		forumId: 1,
	});

	createdTopicId = body.data.id;
});

// ---------------------------------------------------------------------------
// 27. POST /topics/{id}/posts — auth guards
// ---------------------------------------------------------------------------
test('POST /topics/{id}/posts without token — 401', async () => {
	const res = await apiCtx.post(`${API}/topics/${createdTopicId}/posts`, {
		data: { content: 'Unauthorized reply' },
	});

	expect(res.status()).toBe(401);
});

// ---------------------------------------------------------------------------
// 28. POST /topics/{id}/posts — alice replies to topic
// ---------------------------------------------------------------------------
test('POST /topics/{id}/posts as alice — 201 (f_reply granted via REGISTERED group)', async () => {
	const res = await apiCtx.post(`${API}/topics/${createdTopicId}/posts`, {
		data: { content: 'Alice reply content' },
		headers: auth(),
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	expect(body.data).toMatchObject({
		id:      expect.any(Number),
		topicId: createdTopicId,
		forumId: 1,
		content: 'Alice reply content',
	});
});

