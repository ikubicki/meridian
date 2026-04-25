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
 * M8 Notifications Service — E2E tests
 *
 * Use cases covered:
 *   UC-N1  Auth guards — all 4 endpoints require authentication     → 401
 *   UC-N2  GET /notifications/count — shape and polling headers     → 200
 *   UC-N3  GET /notifications/count — HTTP conditional request      → 304 Not Modified
 *   UC-N4  GET /notifications — paginated list and NotificationDTO  → 200
 *   UC-N5  GET /notifications — pagination parameters               → perPage, page
 *   UC-N6  POST /notifications/{id}/read — mark single notification → 204 / 404
 *   UC-N7  POST /notifications/read — mark all read                 → 204, idempotent, count=0
 *   UC-N8  Sequential markRead — post + topic notifications → count 2→1→0
 */

import { test, expect, APIRequestContext, request as playwrightRequest } from '@playwright/test';
import * as db from './helpers/db';

test.describe.configure({ mode: 'serial' });

const API = '/api/v1';

let apiCtx: APIRequestContext;
let aliceToken: string;

// Alice user_id = 200 (verified from phpbb_users table)
const ALICE_USER_ID = 200;

// Three unique item_ids for seeded notifications.
// Must not collide with api.spec.ts which uses 99901.
const SEED_ITEM_IDS = [99201, 99202, 99203];

// Auto-assigned notification_ids retrieved from DB after seeding.
let seededNotificationIds: number[] = [];

// Captured before each mark-read step to verify count changes.
let countBeforeMarkSingle: number = 0;

// ---------------------------------------------------------------------------
// Setup / Teardown
// ---------------------------------------------------------------------------

test.beforeAll(async () => {
	apiCtx = await playwrightRequest.newContext({
		baseURL: process.env.API_BASE_URL ?? 'http://localhost:8181',
		extraHTTPHeaders: {
			'Content-Type': 'application/json',
			'Accept':       'application/json',
		},
	});

	// Seed 3 unread notifications for alice so every test has real data.
	const idMap = await db.seedNotifications(
		SEED_ITEM_IDS.map(itemId => ({ typeId: 1, itemId, parentId: itemId, userId: ALICE_USER_ID })),
	);

	seededNotificationIds = SEED_ITEM_IDS.map(id => idMap.get(id) ?? 0).filter(id => id > 0);
});

test.afterAll(async () => {
	await db.clearNotifications(SEED_ITEM_IDS, ALICE_USER_ID);
	await db.closePool();
	await apiCtx.dispose();
});

function auth(): Record<string, string> {
	return { Authorization: `Bearer ${aliceToken}` };
}

// ---------------------------------------------------------------------------
// Auth setup
// ---------------------------------------------------------------------------

test('POST /auth/login as alice — acquire access token', async () => {
	const res = await apiCtx.post(`${API}/auth/login`, {
		data: { username: 'alice', password: 'testpass' },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	aliceToken = body.data.accessToken;
	expect(aliceToken.split('.').length).toBe(3); // valid JWT
});

// ---------------------------------------------------------------------------
// UC-N1: Authentication guards
// All 4 notification endpoints must return 401 without a valid Bearer token.
// ---------------------------------------------------------------------------

test('UC-N1 GET /notifications/count without auth — 401', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`);

	expect(res.status()).toBe(401);

	const body = await res.json();
	expect(body.error ?? body.status).toBeTruthy();
});

test('UC-N1 GET /notifications without auth — 401', async () => {
	const res = await apiCtx.get(`${API}/notifications`);

	expect(res.status()).toBe(401);

	const body = await res.json();
	expect(body.error ?? body.status).toBeTruthy();
});

test('UC-N1 POST /notifications/read without auth — 401', async () => {
	const res = await apiCtx.post(`${API}/notifications/read`);

	expect(res.status()).toBe(401);

	const body = await res.json();
	expect(body.error ?? body.status).toBeTruthy();
});

test('UC-N1 POST /notifications/{id}/read without auth — 401', async () => {
	const res = await apiCtx.post(`${API}/notifications/1/read`);

	expect(res.status()).toBe(401);

	const body = await res.json();
	expect(body.error ?? body.status).toBeTruthy();
});

// ---------------------------------------------------------------------------
// UC-N2: GET /notifications/count — response shape and polling headers
//
// Verifies:
//   - 200 status with data.unread as integer >= 0
//   - X-Poll-Interval: 30 header (server-controlled polling hint)
//   - Last-Modified header (presence guaranteed by seeded rows)
// ---------------------------------------------------------------------------

test('UC-N2 GET /notifications/count — 200 with data.unread integer >= 0', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data).toBeDefined();
	expect(typeof body.data.unread).toBe('number');
	expect(body.data.unread).toBeGreaterThanOrEqual(1); // at least our 3 seeded rows
});

test('UC-N2 GET /notifications/count — X-Poll-Interval: 30 header present', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(res.status()).toBe(200);
	expect(res.headers()['x-poll-interval']).toBe('30');
});

test('UC-N2 GET /notifications/count — Last-Modified header non-null (seeded rows exist)', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(res.status()).toBe(200);
	expect(res.headers()['last-modified']).toBeTruthy();
});

// ---------------------------------------------------------------------------
// UC-N3: GET /notifications/count — HTTP conditional request (304 Not Modified)
//
// Client sends If-Modified-Since equal to the Last-Modified value from a prior
// response.  Server must respond 304 without a body.
//
// Known trade-off: Last-Modified is derived from MAX(notification_time) in DB.
// It does NOT update on markRead, so a client using If-Modified-Since may receive
// a stale 304 for up to 30 s after a mark-read.
// ---------------------------------------------------------------------------

test('UC-N3 GET /notifications/count with If-Modified-Since = Last-Modified — 304', async () => {
	// First request: establish Last-Modified baseline
	const first = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(first.status()).toBe(200);
	const lastModified = first.headers()['last-modified'];
	expect(lastModified).toBeTruthy();

	// Second request: send the same value as If-Modified-Since → must get 304
	const second = await apiCtx.get(`${API}/notifications/count`, {
		headers: { ...auth(), 'If-Modified-Since': lastModified },
	});

	expect(second.status()).toBe(304);
});

// ---------------------------------------------------------------------------
// UC-N4: GET /notifications — paginated list shape and NotificationDTO validation
//
// Verifies:
//   - 200 with data array and meta object
//   - meta has: total, page, perPage, lastPage (all numbers)
//   - lastPage >= 1 (max(1, ...) guard in controller)
//   - Each item conforms to NotificationDTO shape (id, type, unread, createdAt, data)
//   - NotificationDTO.data has itemId, itemParentId, responders[], responderCount
//   - Seeded item is unread=true
// ---------------------------------------------------------------------------

test('UC-N4 GET /notifications — 200 with data array and meta object', async () => {
	const res = await apiCtx.get(`${API}/notifications`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.meta).toBeDefined();
});

test('UC-N4 GET /notifications — meta has all required pagination fields', async () => {
	const res = await apiCtx.get(`${API}/notifications`, { headers: auth() });

	const body = await res.json();
	expect(body.meta).toMatchObject({
		total:    expect.any(Number),
		page:     1,
		perPage:  expect.any(Number),
		lastPage: expect.any(Number),
	});
	expect(body.meta.total).toBeGreaterThanOrEqual(3);    // at least 3 seeded rows
	expect(body.meta.lastPage).toBeGreaterThanOrEqual(1); // L1 fix: max(1, totalPages())
});

test('UC-N4 GET /notifications — each item conforms to NotificationDTO shape', async () => {
	const res = await apiCtx.get(`${API}/notifications`, { headers: auth() });

	const { data } = await res.json();
	expect(data.length).toBeGreaterThanOrEqual(1);

	for (const item of data) {
		expect(item).toMatchObject({
			id:        expect.any(Number),
			type:      expect.any(String),
			unread:    expect.any(Boolean),
			createdAt: expect.any(Number),
			data:      expect.any(Object),
		});
	}
});

test('UC-N4 GET /notifications — NotificationDTO.data sub-fields and seeded row is unread', async () => {
	const res = await apiCtx.get(`${API}/notifications`, { headers: auth() });

	const { data } = await res.json();

	// Locate one of our seeded items by itemId
	const seeded = data.find(
		(n: { data: { itemId: number }; unread: boolean }) => SEED_ITEM_IDS.includes(n.data.itemId),
	);
	expect(seeded).toBeDefined();

	expect(seeded.data).toMatchObject({
		itemId:         expect.any(Number),
		itemParentId:   expect.any(Number),
		responders:     expect.any(Array),
		responderCount: expect.any(Number),
	});
	expect(seeded.unread).toBe(true);       // freshly seeded, not yet read
	expect(seeded.createdAt).toBeGreaterThan(0);
	expect(seeded.type).toBeTruthy();       // notification_type_name from JOIN
});

// ---------------------------------------------------------------------------
// UC-N5: GET /notifications — pagination request parameters
// ---------------------------------------------------------------------------

test('UC-N5 GET /notifications?perPage=1 — returns at most 1 item with meta.perPage=1', async () => {
	const res = await apiCtx.get(`${API}/notifications?perPage=1`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.length).toBeLessThanOrEqual(1);
	expect(body.meta.perPage).toBe(1);
	expect(body.meta.lastPage).toBeGreaterThanOrEqual(1);
});

test('UC-N5 GET /notifications?page=9999 — 200 with empty data array (beyond last page)', async () => {
	const res = await apiCtx.get(`${API}/notifications?page=9999`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.data.length).toBe(0);
	expect(body.meta.page).toBe(9999);
});

// ---------------------------------------------------------------------------
// UC-N6: POST /notifications/{id}/read — mark single notification as read
//
// Verifies:
//   - 404 for a non-existent notification ID
//   - 204 for a valid owned unread notification
//   - Unread count decreases by exactly 1 after marking
//   - 404 when trying to mark an already-read notification (idempotency not supported)
// ---------------------------------------------------------------------------

test('UC-N6 POST /notifications/99999/read — 404 for non-existent notification', async () => {
	const res = await apiCtx.post(`${API}/notifications/99999/read`, { headers: auth() });

	expect(res.status()).toBe(404);

	const body = await res.json();
	expect(body.error).toBeTruthy();
});

test('UC-N6 GET /notifications/count — capture baseline unread count', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	countBeforeMarkSingle = body.data.unread;
	expect(countBeforeMarkSingle).toBeGreaterThanOrEqual(3); // 3 seeded unread rows
});

test('UC-N6 POST /notifications/{id}/read for owned unread notification — 204', async () => {
	// Use the first seeded notification_id (retrieved from DB in beforeAll)
	expect(seededNotificationIds.length).toBeGreaterThanOrEqual(1);
	const notifId = seededNotificationIds[0];

	const res = await apiCtx.post(`${API}/notifications/${notifId}/read`, { headers: auth() });

	expect(res.status()).toBe(204);
	// 204 No Content: body must be absent
	const text = await res.text();
	expect(text).toBe('');
});

test('UC-N6 GET /notifications/count — unread count decreased by exactly 1', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.unread).toBe(countBeforeMarkSingle - 1);
});

test('UC-N6 POST /notifications/{id}/read for already-read notification — 404', async () => {
	// markRead only succeeds when notification_read = 0.
	// If already read, repo returns false → service throws → 404.
	const notifId = seededNotificationIds[0];

	const res = await apiCtx.post(`${API}/notifications/${notifId}/read`, { headers: auth() });

	expect(res.status()).toBe(404);
});

// ---------------------------------------------------------------------------
// UC-N7: POST /notifications/read — mark ALL notifications as read
//
// Verifies:
//   - 204 on first call (marks all alice's unread as read)
//   - 204 on repeated call (idempotent — no error even when nothing to mark)
//   - GET /notifications/count returns 0 after mark-all
// ---------------------------------------------------------------------------

test('UC-N7 POST /notifications/read — 204 (marks all alice\'s unread notifications)', async () => {
	const res = await apiCtx.post(`${API}/notifications/read`, { headers: auth() });

	expect(res.status()).toBe(204);

	const text = await res.text();
	expect(text).toBe(''); // no body on 204
});

test('UC-N7 POST /notifications/read again — 204 (idempotent, no error)', async () => {
	const res = await apiCtx.post(`${API}/notifications/read`, { headers: auth() });

	expect(res.status()).toBe(204);
});

test('UC-N7 GET /notifications/count after mark-all-read — unread count is 0', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	// All alice's notifications have been marked as read — count must be 0.
	expect(body.data.unread).toBe(0);
});

// ---------------------------------------------------------------------------
// UC-N8: Sequential markRead — topic-follow and post-reply notifications
//
// Scenario:
//   Alice follows topic 500 (notification.type.topic, type_id=1) and has a
//   post-reply notification in that same topic (notification.type.post, type_id=5).
//   Starting state: count = 2
//   Step 1 — mark the post-reply notification read  → count = 1
//   Step 2 — mark the topic-follow notification read → count = 0
//
// This covers the real-world flow of a user clearing their inbox one by one.
// ---------------------------------------------------------------------------

// Notification IDs seeded for UC-N8 (retrieved from DB after seeding).
let uc8PostNotifId: number  = 0;
let uc8TopicNotifId: number = 0;

// item_ids chosen to avoid any collusion with UC-N1..N7 and api.spec.ts.
const UC8_POST_ITEM_ID  = 99801;
const UC8_TOPIC_ITEM_ID = 99800;

// notification_type_id values (from phpbb_notification_types):
//   1 = notification.type.topic  (new post in followed topic)
//   5 = notification.type.post   (reply to user's own topic)
const TYPE_TOPIC = 1;
const TYPE_POST  = 5;

test('UC-N8 seed — insert one topic-follow and one post-reply notification for alice', async () => {
	// UC-N7 already ran GET /count which cached 0 for alice.
	// Seeding via direct DB bypasses the API cache invalidation path,
	// so flush the on-disk notification cache first (cache/ is volume-mounted).
	db.flushNotificationCache();

	const idMap = await db.seedNotifications([
		// notification.type.topic — alice is following topic 500, new post arrived
		{ typeId: TYPE_TOPIC, itemId: UC8_TOPIC_ITEM_ID, parentId: 500, userId: ALICE_USER_ID, timeOffset: 120 },
		// notification.type.post  — someone replied to alice's post in topic 500
		{ typeId: TYPE_POST,  itemId: UC8_POST_ITEM_ID,  parentId: 500, userId: ALICE_USER_ID, timeOffset: 60  },
	]);

	uc8TopicNotifId = idMap.get(UC8_TOPIC_ITEM_ID) ?? 0;
	uc8PostNotifId  = idMap.get(UC8_POST_ITEM_ID)  ?? 0;

	expect(uc8TopicNotifId).toBeGreaterThan(0);
	expect(uc8PostNotifId).toBeGreaterThan(0);
});

test('UC-N8 GET /notifications/count — unread count is 2 after seeding both notifications', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.unread).toBe(2);
});

test('UC-N8 GET /notifications — both notifications visible with correct types', async () => {
	const res = await apiCtx.get(`${API}/notifications`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	const itemIds = data.map((n: { data: { itemId: number } }) => n.data.itemId);

	expect(itemIds).toContain(UC8_TOPIC_ITEM_ID);
	expect(itemIds).toContain(UC8_POST_ITEM_ID);

	const topicNotif = data.find((n: { data: { itemId: number } }) => n.data.itemId === UC8_TOPIC_ITEM_ID);
	const postNotif  = data.find((n: { data: { itemId: number } }) => n.data.itemId === UC8_POST_ITEM_ID);

	expect(topicNotif.type).toBe('notification.type.topic');
	expect(topicNotif.unread).toBe(true);
	expect(postNotif.type).toBe('notification.type.post');
	expect(postNotif.unread).toBe(true);
});

test('UC-N8 POST /notifications/{postNotifId}/read — mark post-reply notification read → 204', async () => {
	expect(uc8PostNotifId).toBeGreaterThan(0);

	const res = await apiCtx.post(`${API}/notifications/${uc8PostNotifId}/read`, { headers: auth() });

	expect(res.status()).toBe(204);
});

test('UC-N8 GET /notifications/count after first markRead — unread count is 1', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.unread).toBe(1);
});

test('UC-N8 POST /notifications/{topicNotifId}/read — mark topic-follow notification read → 204', async () => {
	expect(uc8TopicNotifId).toBeGreaterThan(0);

	const res = await apiCtx.post(`${API}/notifications/${uc8TopicNotifId}/read`, { headers: auth() });

	expect(res.status()).toBe(204);
});

test('UC-N8 GET /notifications/count after second markRead — unread count is 0', async () => {
	const res = await apiCtx.get(`${API}/notifications/count`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.unread).toBe(0);
});

test('UC-N8 cleanup — remove seeded UC-N8 notifications', async () => {
	await db.clearNotifications([UC8_TOPIC_ITEM_ID, UC8_POST_ITEM_ID], ALICE_USER_ID);
});
