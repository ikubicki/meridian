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
 * M9 Search Service — E2E tests
 *
 * Use cases covered:
 *   UC-S1  GET /search without auth token              → 401
 *   UC-S2  GET /search without required param "q"      → 400
 *   UC-S3  GET /search?q=<term> — returns seeded post  → 200, data ≥ 1
 *   UC-S4  GET /search?q=<term>&forum_id=1 — filter    → only forumId === 1
 *   UC-S5  GET /search?q=<nonexistent> — no match      → 200, data = []
 */

import { test, expect, APIRequestContext, request as playwrightRequest } from '@playwright/test';
import * as db from './helpers/db';

test.describe.configure({ mode: 'serial' });

const API = '/api/v1';

// alice user_id — verified from phpbb_users table (same value as in notifications.spec.ts)
const ALICE_USER_ID = 200;

let apiCtx: APIRequestContext;
let aliceToken: string;

// Accumulate IDs across tests so afterAll can clean up everything.
const seededPostIds:  number[] = [];
const seededTopicIds: number[] = [];

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

	// Ensure ConfigRepository returns 'like' for search_driver so LikeDriver
	// is used — avoids the FULLTEXT INDEX requirement of the default driver.
	await db.setupSearchConfig();
});

test.afterAll(async () => {
	await db.cleanupSearchData(seededPostIds, seededTopicIds);
	await db.cleanupSearchConfig();
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
// UC-S1: No auth → 401
// ---------------------------------------------------------------------------

test('UC-S1: GET /search without auth token → 401', async () => {
	const res = await apiCtx.get(`${API}/search?q=hello`);
	expect(res.status()).toBe(401);
});

// ---------------------------------------------------------------------------
// UC-S2: Missing required param "q" → 400
// ---------------------------------------------------------------------------

test('UC-S2: GET /search without q → 400', async () => {
	const res = await apiCtx.get(`${API}/search`, { headers: auth() });
	expect(res.status()).toBe(400);

	const body = await res.json();
	// The error message must reference the missing parameter name.
	expect(JSON.stringify(body)).toContain('q');
});

// ---------------------------------------------------------------------------
// UC-S3: Returns matching posts
// ---------------------------------------------------------------------------

test('UC-S3: GET /search?q=<term> — returns seeded post with matching excerpt', async () => {
	const uniqueTerm = 'test_uc_s3_searchspec_hello_unique';

	const { postId, topicId } = await db.seedSearchPost({
		forumId:  1,
		posterId: ALICE_USER_ID,
		subject:  uniqueTerm,
		text:     `hello world ${uniqueTerm}`,
	});
	seededPostIds.push(postId);
	seededTopicIds.push(topicId);

	const res = await apiCtx.get(`${API}/search?q=${uniqueTerm}`, { headers: auth() });
	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.data.length).toBeGreaterThanOrEqual(1);
	expect(body.data[0].postId).toBeDefined();
	expect(body.data[0].excerpt).toContain(uniqueTerm);
	expect(body.meta.total).toBeGreaterThanOrEqual(1);
});

// ---------------------------------------------------------------------------
// UC-S4: forum_id filter restricts results to the requested forum
// ---------------------------------------------------------------------------

test('UC-S4: GET /search?q=<term>&forum_id=1 — every result has forumId === 1', async () => {
	const uniqueTerm = 'uniqueterm_s4_forumfilter_searchspec';

	// Seed one post in forum 1 and one in forum 2 with the same unique term.
	const { postId: pId1, topicId: tId1 } = await db.seedSearchPost({
		forumId:  1,
		posterId: ALICE_USER_ID,
		subject:  uniqueTerm,
		text:     uniqueTerm,
	});
	seededPostIds.push(pId1);
	seededTopicIds.push(tId1);

	const { postId: pId2, topicId: tId2 } = await db.seedSearchPost({
		forumId:  2,
		posterId: ALICE_USER_ID,
		subject:  uniqueTerm,
		text:     uniqueTerm,
	});
	seededPostIds.push(pId2);
	seededTopicIds.push(tId2);

	const res = await apiCtx.get(`${API}/search?q=${uniqueTerm}&forum_id=1`, { headers: auth() });
	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.data.length).toBeGreaterThanOrEqual(1);

	for (const item of body.data) {
		expect(item.forumId).toBe(1);
	}
});

// ---------------------------------------------------------------------------
// UC-S5: No match → empty results
// ---------------------------------------------------------------------------

test('UC-S5: GET /search?q=<nonexistent> — returns empty data array', async () => {
	const res = await apiCtx.get(`${API}/search?q=zzznomatch9999xyz_playwright_e2e_uniq`, { headers: auth() });
	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.data).toHaveLength(0);
	expect(body.meta.total).toBe(0);
});

// ---------------------------------------------------------------------------
// UC-SR4: sort_by=date_asc returns results in ascending order
// ---------------------------------------------------------------------------

test('UC-SR4: GET /search?q=<term>&sort_by=date_asc — results ordered oldest first', async () => {
	const uniqueTerm = 'test_ucsr4_sortterm_asc_unique';

	for (const postTime of [1000000, 2000000, 3000000]) {
		const { postId, topicId } = await db.seedSearchPost({
			forumId:  1,
			posterId: ALICE_USER_ID,
			subject:  uniqueTerm,
			text:     uniqueTerm,
			postTime,
		});
		seededPostIds.push(postId);
		seededTopicIds.push(topicId);
	}

	const res = await apiCtx.get(`${API}/search?q=${uniqueTerm}&sort_by=date_asc`, { headers: auth() });
	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.data.length).toBeGreaterThanOrEqual(2);

	for (let i = 1; i < body.data.length; i++) {
		expect(body.data[i - 1].postedAt).toBeLessThanOrEqual(body.data[i].postedAt);
	}
});

// ---------------------------------------------------------------------------
// UC-SR5: search_in=titles only matches post_subject / topic_title
// ---------------------------------------------------------------------------

test('UC-SR5: GET /search?q=<term>&search_in=titles — only title-matching post returned', async () => {
	const uniqueTerm = 'test_ucsr5_titlesearch_unique';

	// Post A: keyword in subject (will match search_in=titles)
	const { postId: pA, topicId: tA } = await db.seedSearchPost({
		forumId:  1,
		posterId: ALICE_USER_ID,
		subject:  uniqueTerm,
		text:     'nothing special here',
	});
	seededPostIds.push(pA);
	seededTopicIds.push(tA);

	// Post B: keyword only in body text (must NOT match search_in=titles)
	const { postId: pB, topicId: tB } = await db.seedSearchPost({
		forumId:  1,
		posterId: ALICE_USER_ID,
		subject:  'regular subject sr5',
		text:     `body only ${uniqueTerm}`,
	});
	seededPostIds.push(pB);
	seededTopicIds.push(tB);

	const res = await apiCtx.get(`${API}/search?q=${uniqueTerm}&search_in=titles`, { headers: auth() });
	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.meta.total).toBe(1);
	expect(body.data[0].postId).toBe(pA);
});

// ---------------------------------------------------------------------------
// UC-SR6: date_from filter excludes posts older than the threshold
// ---------------------------------------------------------------------------

test('UC-SR6: GET /search?q=<term>&date_from=1700000000 — old post excluded', async () => {
	const uniqueTerm = 'test_ucsr6_datedterm_unique';

	const { postId: pOld, topicId: tOld } = await db.seedSearchPost({
		forumId:  1,
		posterId: ALICE_USER_ID,
		subject:  uniqueTerm,
		text:     uniqueTerm,
		postTime: 1000000, // 1970 — must be excluded
	});
	seededPostIds.push(pOld);
	seededTopicIds.push(tOld);

	const { postId: pNew, topicId: tNew } = await db.seedSearchPost({
		forumId:  1,
		posterId: ALICE_USER_ID,
		subject:  uniqueTerm,
		text:     uniqueTerm,
		postTime: 1800000000, // ~2027 — must be included
	});
	seededPostIds.push(pNew);
	seededTopicIds.push(tNew);

	const res = await apiCtx.get(`${API}/search?q=${uniqueTerm}&date_from=1700000000`, { headers: auth() });
	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
	expect(body.meta.total).toBe(1);
	expect(body.data[0].postId).toBe(pNew);
	expect(body.data[0].postedAt).toBeGreaterThanOrEqual(1700000000);
});

// ---------------------------------------------------------------------------
// UC-SR9: Pagination — page=2 returns second page of results
// ---------------------------------------------------------------------------

test('UC-SR9: GET /search?q=<term>&page=2&perPage=5 — returns second page', async () => {
	const uniqueTerm = 'test_ucsr9_pageterm_unique';

	for (let i = 0; i < 7; i++) {
		const { postId, topicId } = await db.seedSearchPost({
			forumId:  1,
			posterId: ALICE_USER_ID,
			subject:  uniqueTerm,
			text:     uniqueTerm,
		});
		seededPostIds.push(postId);
		seededTopicIds.push(topicId);
	}

	// Page 1
	const res1 = await apiCtx.get(`${API}/search?q=${uniqueTerm}&page=1&perPage=5`, { headers: auth() });
	expect(res1.status()).toBe(200);
	const body1 = await res1.json();
	expect(body1.data.length).toBe(5);
	expect(body1.meta.total).toBeGreaterThanOrEqual(7);

	// Page 2
	const res2 = await apiCtx.get(`${API}/search?q=${uniqueTerm}&page=2&perPage=5`, { headers: auth() });
	expect(res2.status()).toBe(200);
	const body2 = await res2.json();
	expect(body2.data.length).toBeGreaterThanOrEqual(1);
	expect(body2.meta.page).toBe(2);
});
