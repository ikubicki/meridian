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
 * M13 Config Service — E2E tests
 *
 * Use cases covered:
 *   UC-C1  Auth guard — all 4 endpoints reject without elevated token → 401
 *   UC-C2  GET /config — list all config entries as admin
 *   UC-C3  PUT /config/{key} → GET /config/{key} — round-trip write + read
 *   UC-C4  PUT /config/{key} with isDynamic field
 *   UC-C5  GET /config/nonexistent → 404
 *   UC-C6  DELETE /config/{key} lifecycle — 204 then 404
 *   UC-C7  PUT /config/{key} missing value field → 400
 */

import { test, expect, APIRequestContext, request as playwrightRequest } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const API = '/api/v1';
const TEST_KEY = 'e2e_config_test_key';

let apiCtx: APIRequestContext;
let adminToken: string;
let adminElevatedToken: string;

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
	// Cleanup: try to delete test key (ignore errors if already gone)
	if (adminElevatedToken) {
		await apiCtx.delete(`${API}/config/${TEST_KEY}`, {
			headers: { Authorization: `Bearer ${adminElevatedToken}` },
		});
	}
	await apiCtx.dispose();
});

function authHeader(token: string): Record<string, string> {
	return { Authorization: `Bearer ${token}` };
}

// ---------------------------------------------------------------------------
// Auth setup
// ---------------------------------------------------------------------------

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
// UC-C1: Auth guard — all 4 endpoints reject without elevated token
// ---------------------------------------------------------------------------

test('UC-C1a GET /config without elevated token — 401', async () => {
	const res = await apiCtx.get(`${API}/config`, {
		headers: authHeader(adminToken),
	});

	expect(res.status()).toBe(401);
});

test('UC-C1b GET /config/{key} without elevated token — 401', async () => {
	const res = await apiCtx.get(`${API}/config/${TEST_KEY}`, {
		headers: authHeader(adminToken),
	});

	expect(res.status()).toBe(401);
});

test('UC-C1c PUT /config/{key} without elevated token — 401', async () => {
	const res = await apiCtx.put(`${API}/config/${TEST_KEY}`, {
		data: { value: 'test' },
		headers: authHeader(adminToken),
	});

	expect(res.status()).toBe(401);
});

test('UC-C1d DELETE /config/{key} without elevated token — 401', async () => {
	const res = await apiCtx.delete(`${API}/config/${TEST_KEY}`, {
		headers: authHeader(adminToken),
	});

	expect(res.status()).toBe(401);
});

// ---------------------------------------------------------------------------
// UC-C2: GET /config — list all config entries
// ---------------------------------------------------------------------------

test('UC-C2 GET /config — returns all config entries as admin', async () => {
	const res = await apiCtx.get(`${API}/config`, {
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.meta.total).toBeGreaterThanOrEqual(1);

	for (const item of body.data) {
		expect(typeof item.key).toBe('string');
		expect(typeof item.value).toBe('string');
		expect(typeof item.isDynamic).toBe('boolean');
	}
});

// ---------------------------------------------------------------------------
// UC-C3: PUT + GET round-trip
// ---------------------------------------------------------------------------

test('UC-C3 PUT /config/{TEST_KEY} — upsert new key → 200 with data object', async () => {
	const res = await apiCtx.put(`${API}/config/${TEST_KEY}`, {
		data: { value: 'e2e_value_1' },
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.key).toBe(TEST_KEY);
	expect(body.data.value).toBe('e2e_value_1');
	expect(body.data.isDynamic).toBe(false);
});

test('UC-C3 GET /config/{TEST_KEY} — returns written value', async () => {
	const res = await apiCtx.get(`${API}/config/${TEST_KEY}`, {
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.value).toBe('e2e_value_1');
});

// ---------------------------------------------------------------------------
// UC-C4: PUT with isDynamic field
// ---------------------------------------------------------------------------

test('UC-C4 PUT /config/{TEST_KEY} with isDynamic: true — updates isDynamic flag', async () => {
	const res = await apiCtx.put(`${API}/config/${TEST_KEY}`, {
		data: { value: 'updated_value', isDynamic: true },
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.isDynamic).toBe(true);
});

// ---------------------------------------------------------------------------
// UC-C5: GET unknown key → 404
// ---------------------------------------------------------------------------

test('UC-C5 GET /config/nonexistent_key_xyz_9999 — 404', async () => {
	const res = await apiCtx.get(`${API}/config/nonexistent_key_xyz_9999`, {
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(404);

	const body = await res.json();
	expect(body.error).toBeTruthy();
});

// ---------------------------------------------------------------------------
// UC-C6: DELETE lifecycle — 204 then 404
// ---------------------------------------------------------------------------

test('UC-C6a DELETE /config/{TEST_KEY} — 204 on success', async () => {
	const res = await apiCtx.delete(`${API}/config/${TEST_KEY}`, {
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(204);
});

test('UC-C6b DELETE /config/{TEST_KEY} again — 404 (already deleted)', async () => {
	const res = await apiCtx.delete(`${API}/config/${TEST_KEY}`, {
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(404);
});

// ---------------------------------------------------------------------------
// UC-C7: PUT missing value field → 400
// ---------------------------------------------------------------------------

test('UC-C7 PUT /config/{TEST_KEY} missing value field — 400', async () => {
	const res = await apiCtx.put(`${API}/config/${TEST_KEY}`, {
		data: {},
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(400);

	const body = await res.json();
	expect(body.error).toMatch(/value/i);
});

// ---------------------------------------------------------------------------
// UC-C8: PUT with integer value — coerced to string
// ---------------------------------------------------------------------------

test('UC-C8 PUT /config/{TEST_KEY} with integer value — coerces to string', async () => {
	const res = await apiCtx.put(`${API}/config/${TEST_KEY}`, {
		data: { value: 42 },
		headers: authHeader(adminElevatedToken),
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(typeof body.data.value).toBe('string');
	expect(body.data.value).toBe('42');
});
