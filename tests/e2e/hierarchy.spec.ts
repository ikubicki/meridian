/**
 *
 * This file is part of the phpBB4 "Meridian" package.
 *
 * @copyright (c) Irek Kubicki <phpbb@codebuilders.pl>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

/**
 * M5a Hierarchy Service — E2E tests
 *
 * Use cases covered:
 *   UC-H1  GET /forums without auth                    → 200 (anonymous allowed)
 *   UC-H2  GET /forums/{id} without auth               → 200 (anonymous allowed)
 *   UC-H3  GET /forums — ForumDTO shape validation     → all fields present
 *   UC-H4  GET /forums/{id} — single forum shape       → full DTO + 404 edge case
 *   UC-H5  GET /forums?parent_id={id} — filter         → scoped subset
 *   UC-H6  POST /forums auth guards                    → 401 for no/reg/non-elevated token
 *   UC-H7  Full lifecycle: create → update → move → delete
 *   UC-H8  DELETE with children present               → 400
 *   UC-H9  PUT/PATCH/DELETE non-existing forum         → 404
 */

import { test, expect, APIRequestContext, request as playwrightRequest } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const API = '/api/v1';

let apiCtx: APIRequestContext;
let aliceToken: string;
let adminToken: string;
let adminElevatedToken: string;

let createdForumId: number;
let createdCategoryId: number;

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

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

function authHeader(token: string): Record<string, string> {
	return { Authorization: `Bearer ${token}` };
}

// ---------------------------------------------------------------------------
// Auth setup
// ---------------------------------------------------------------------------

test('POST /auth/login as alice — acquire regular token', async () => {
	const res = await apiCtx.post(`${API}/auth/login`, {
		data: { username: 'alice', password: 'testpass' },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	aliceToken = body.data.accessToken;
	expect(aliceToken.split('.').length).toBe(3);
});

test('POST /auth/login as admin — acquire admin token', async () => {
	const res = await apiCtx.post(`${API}/auth/login`, {
		data: { username: 'admin', password: 'admin' },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	adminToken = body.data.accessToken;
	expect(adminToken.split('.').length).toBe(3);
});

test('POST /auth/elevate as admin — acquire elevated token', async () => {
	const res = await apiCtx.post(`${API}/auth/elevate`, {
		data: { password: 'admin' },
		headers: authHeader(adminToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	adminElevatedToken = body.data.elevatedToken;
	expect(adminElevatedToken.split('.').length).toBe(3);
});

// ---------------------------------------------------------------------------
// UC-H1: Anonymous access to GET endpoints
// ---------------------------------------------------------------------------

test('UC-H1 GET /forums without auth — 200 (anonymous allowed)', async () => {
	const res = await apiCtx.get(`${API}/forums`);

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
});

// ---------------------------------------------------------------------------
// UC-H2: Anonymous access to GET /forums/{id}
// ---------------------------------------------------------------------------

test('UC-H2 GET /forums/1 without auth — 200 (anonymous allowed)', async () => {
	const res = await apiCtx.get(`${API}/forums/1`);

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.id).toBe(1);
});

// ---------------------------------------------------------------------------
// UC-H3: GET /forums — full ForumDTO shape validation
// ---------------------------------------------------------------------------

test('UC-H3 GET /forums — response has data array and meta', async () => {
	const res = await apiCtx.get(`${API}/forums`, { headers: authHeader(aliceToken) });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.data.length).toBeGreaterThanOrEqual(1);
	expect(body.meta).toMatchObject({ total: expect.any(Number) });
	expect(body.meta.total).toBeGreaterThanOrEqual(1);
});

test('UC-H3 GET /forums — each forum has full ForumDTO fields', async () => {
	const res = await apiCtx.get(`${API}/forums`, { headers: authHeader(aliceToken) });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.length).toBeGreaterThanOrEqual(1);

	for (const forum of data) {
		expect(forum).toMatchObject({
			id:             expect.any(Number),
			title:          expect.any(String),
			description:    expect.any(String),
			parentId:       expect.any(Number),
			type:           expect.any(Number),
			status:         expect.any(Number),
			leftId:         expect.any(Number),
			rightId:        expect.any(Number),
			displayOnIndex: expect.any(Boolean),
			topicCount:     expect.any(Number),
			postCount:      expect.any(Number),
			lastPostId:     expect.any(Number),
			lastPostTime:   expect.any(Number),
			lastPosterName: expect.any(String),
			link:           expect.any(String),
		});
	}
});

// ---------------------------------------------------------------------------
// UC-H4: GET /forums/{id} — single forum shape + 404
// ---------------------------------------------------------------------------

test('UC-H4 GET /forums/1 — full ForumDTO shape under data key', async () => {
	const res = await apiCtx.get(`${API}/forums/1`, { headers: authHeader(aliceToken) });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data).toMatchObject({
		id:             1,
		title:          expect.any(String),
		description:    expect.any(String),
		parentId:       expect.any(Number),
		type:           expect.any(Number),
		status:         expect.any(Number),
		leftId:         expect.any(Number),
		rightId:        expect.any(Number),
		displayOnIndex: expect.any(Boolean),
		topicCount:     expect.any(Number),
		postCount:      expect.any(Number),
		lastPostId:     expect.any(Number),
		lastPostTime:   expect.any(Number),
		lastPosterName: expect.any(String),
		link:           expect.any(String),
	});
});

test('UC-H4 GET /forums/999999 — 404 for non-existing forum', async () => {
	const res = await apiCtx.get(`${API}/forums/999999`, { headers: authHeader(aliceToken) });

	expect(res.status()).toBe(404);

	const body = await res.json();
	expect(body.error ?? body.status).toBeTruthy();
});

// ---------------------------------------------------------------------------
// UC-H5: GET /forums?parent_id={id} — parent filter
// ---------------------------------------------------------------------------

test('UC-H5 GET /forums?parent_id=0 — returns only top-level forums', async () => {
	const res = await apiCtx.get(`${API}/forums?parent_id=0`, { headers: authHeader(aliceToken) });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);

	for (const forum of body.data) {
		expect(forum.parentId).toBe(0);
	}
});

// ---------------------------------------------------------------------------
// UC-H6: POST /forums — auth guards
// ---------------------------------------------------------------------------

test('UC-H6 POST /forums without token — 401', async () => {
	const res = await apiCtx.post(`${API}/forums`, {
		data: { name: 'Unauth Forum', type: 1, parent_id: 0 },
	});

	expect(res.status()).toBe(401);
});

test('UC-H6 POST /forums with regular alice token — 401', async () => {
	const res = await apiCtx.post(`${API}/forums`, {
		data: { name: 'Unauth Forum', type: 1, parent_id: 0 },
		headers: authHeader(aliceToken),
	});

	expect(res.status()).toBe(401);
});

test('UC-H6 POST /forums with admin access token (not elevated) — 401', async () => {
	const res = await apiCtx.post(`${API}/forums`, {
		data: { name: 'Unauth Forum', type: 1, parent_id: 0 },
		headers: authHeader(adminToken),
	});

	expect(res.status()).toBe(401);
});

// ---------------------------------------------------------------------------
// UC-H7: Full lifecycle — create → update → move → delete
// ---------------------------------------------------------------------------

test('UC-H7 POST /forums as admin — creates forum (201)', async () => {
	const res = await apiCtx.post(`${API}/forums`, {
		data: {
			name:        'E2E Hierarchy Forum',
			type:        1,
			parent_id:   0,
			description: 'Created by hierarchy E2E test',
		},
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	expect(body.status).toBe('created');
});

test('UC-H7 GET /forums — new forum appears in list', async () => {
	const res = await apiCtx.get(`${API}/forums`, { headers: authHeader(aliceToken) });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	const found = data.find((f: { title: string; id: number }) => f.title === 'E2E Hierarchy Forum');
	expect(found).toBeDefined();

	createdForumId = found.id;
	expect(createdForumId).toBeGreaterThan(0);
});

test('UC-H7 PUT /forums/{id} without token — 401', async () => {
	const res = await apiCtx.put(`${API}/forums/${createdForumId}`, {
		data: { name: 'Hacked' },
	});

	expect(res.status()).toBe(401);
});

test('UC-H7 PUT /forums/{id} as admin — updates name (200)', async () => {
	const res = await apiCtx.put(`${API}/forums/${createdForumId}`, {
		data: { name: 'E2E Hierarchy Forum (Updated)' },
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.status).toBe('updated');
});

test('UC-H7 GET /forums/{id} — reflects updated name', async () => {
	const res = await apiCtx.get(`${API}/forums/${createdForumId}`, { headers: authHeader(aliceToken) });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.title).toBe('E2E Hierarchy Forum (Updated)');
});

test('UC-H7 POST /forums — create category as move target (201)', async () => {
	const res = await apiCtx.post(`${API}/forums`, {
		data: {
			name:      'E2E Hierarchy Category',
			type:      0,
			parent_id: 0,
		},
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(201);

	const listRes = await apiCtx.get(`${API}/forums`, { headers: authHeader(adminToken) });
	const { data } = await listRes.json();
	const found = data.find((f: { title: string; id: number }) => f.title === 'E2E Hierarchy Category');
	expect(found).toBeDefined();

	createdCategoryId = found.id;
	expect(createdCategoryId).toBeGreaterThan(0);
});

test('UC-H7 PATCH /forums/{id}/move without token — 401', async () => {
	const res = await apiCtx.patch(`${API}/forums/${createdForumId}/move`, {
		data: { new_parent_id: createdCategoryId },
	});

	expect(res.status()).toBe(401);
});

test('UC-H7 PATCH /forums/{id}/move as admin — moves forum (200)', async () => {
	const res = await apiCtx.patch(`${API}/forums/${createdForumId}/move`, {
		data: { new_parent_id: createdCategoryId },
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.status).toBe('moved');
});

test('UC-H7 GET /forums/{id} — reflects new parentId after move', async () => {
	const res = await apiCtx.get(`${API}/forums/${createdForumId}`, { headers: authHeader(aliceToken) });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.parentId).toBe(createdCategoryId);
});

// ---------------------------------------------------------------------------
// UC-H8: DELETE with children present → 400
// ---------------------------------------------------------------------------

test('UC-H8 DELETE /forums/{id} with children — 400', async () => {
	// createdCategoryId has createdForumId as child — must reject deletion
	const res = await apiCtx.delete(`${API}/forums/${createdCategoryId}`, {
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(400);
});

// ---------------------------------------------------------------------------
// UC-H7 continued: cleanup DELETE leaf then parent
// ---------------------------------------------------------------------------

test('UC-H7 DELETE /forums/{id} without token — 401', async () => {
	const res = await apiCtx.delete(`${API}/forums/${createdForumId}`);

	expect(res.status()).toBe(401);
});

test('UC-H7 DELETE /forums/{id} as admin — deletes leaf forum (200)', async () => {
	const res = await apiCtx.delete(`${API}/forums/${createdForumId}`, {
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.status).toBe('deleted');
});

test('UC-H7 GET /forums/{id} — 404 after deletion', async () => {
	const res = await apiCtx.get(`${API}/forums/${createdForumId}`, { headers: authHeader(aliceToken) });

	expect(res.status()).toBe(404);
});

test('UC-H7 DELETE /forums/{id} as admin — deletes parent category (cleanup, 200)', async () => {
	const res = await apiCtx.delete(`${API}/forums/${createdCategoryId}`, {
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.status).toBe('deleted');
});

// ---------------------------------------------------------------------------
// UC-H9: PUT/PATCH/DELETE on non-existing forum → 404
// ---------------------------------------------------------------------------

test('UC-H9 PUT /forums/999999 — 404 for non-existing forum', async () => {
	const res = await apiCtx.put(`${API}/forums/999999`, {
		data: { name: 'Ghost' },
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(404);
});

test('UC-H9 PATCH /forums/999999/move — 404 for non-existing forum', async () => {
	const res = await apiCtx.patch(`${API}/forums/999999/move`, {
		data: { new_parent_id: 0 },
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(404);
});

test('UC-H9 DELETE /forums/999999 — 404 for non-existing forum', async () => {
	const res = await apiCtx.delete(`${API}/forums/999999`, {
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(404);
});
