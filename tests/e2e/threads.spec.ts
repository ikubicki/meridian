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
// Setup — login
// ---------------------------------------------------------------------------
test('POST /auth/login — acquire alice token', async () => {
	const res = await apiCtx.post(`${API}/auth/login`, {
		data: { username: 'alice', password: 'testpass' },
	});

	expect(res.status()).toBe(200);

	const body = await res.json();
	accessToken = body.data.accessToken;
	expect(accessToken.split('.').length).toBe(3);
});

// ---------------------------------------------------------------------------
// GET /forums/{forumId}/topics — full shape verification
// ---------------------------------------------------------------------------
test('GET /forums/1/topics — returns data array with full TopicDTO fields', async () => {
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

	if (body.data.length > 0) {
		const topic = body.data[0];
		expect(topic).toMatchObject({
			id:             expect.any(Number),
			title:          expect.any(String),
			forumId:        expect.any(Number),
			authorId:       expect.any(Number),
			postCount:      expect.any(Number),
			lastPosterName: expect.any(String),
			lastPostTime:   expect.anything(),
			createdAt:      expect.anything(),
		});
	}
});

test('GET /forums/1/topics without auth — 200 (anonymous f_read allowed)', async () => {
	const res = await apiCtx.get(`${API}/forums/1/topics`);

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(Array.isArray(body.data)).toBe(true);
});

test('GET /forums/1/topics?page=1&perPage=1 — pagination limits results', async () => {
	const res = await apiCtx.get(`${API}/forums/1/topics?page=1&perPage=1`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.length).toBeLessThanOrEqual(1);
	expect(body.meta.perPage).toBe(1);
	expect(body.meta.page).toBe(1);
});

// ---------------------------------------------------------------------------
// GET /topics/{topicId} — full TopicDTO shape
// ---------------------------------------------------------------------------
test('GET /topics/2 — returns full TopicDTO fields', async () => {
	const res = await apiCtx.get(`${API}/topics/2`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data).toMatchObject({
		id:             2,
		title:          expect.any(String),
		forumId:        expect.any(Number),
		authorId:       expect.any(Number),
		postCount:      expect.any(Number),
		lastPosterName: expect.any(String),
		lastPostTime:   expect.anything(),
		createdAt:      expect.anything(),
	});
});

test('GET /topics/2 without auth — 200 (anonymous f_read allowed)', async () => {
	const res = await apiCtx.get(`${API}/topics/2`);

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.id).toBe(2);
});

test('GET /topics/999 — 404 for non-existing topic', async () => {
	const res = await apiCtx.get(`${API}/topics/999`, { headers: auth() });

	expect(res.status()).toBe(404);

	const body = await res.json();
	expect(body.status ?? body.error).toBeTruthy();
});

// ---------------------------------------------------------------------------
// POST /forums/{forumId}/topics — validation guards
// ---------------------------------------------------------------------------
test('POST /forums/1/topics without token — 401', async () => {
	const res = await apiCtx.post(`${API}/forums/1/topics`, {
		data: { title: 'Unauthorized', content: 'Should be rejected' },
	});

	expect(res.status()).toBe(401);
});

test('POST /forums/1/topics with empty title — 400', async () => {
	const res = await apiCtx.post(`${API}/forums/1/topics`, {
		data: { title: '   ', content: 'Content without title' },
		headers: auth(),
	});

	expect(res.status()).toBe(400);

	const body = await res.json();
	expect(body.error).toBeTruthy();
});

test('POST /forums/1/topics with missing title — 400', async () => {
	const res = await apiCtx.post(`${API}/forums/1/topics`, {
		data: { content: 'No title field at all' },
		headers: auth(),
	});

	expect(res.status()).toBe(400);
});

// ---------------------------------------------------------------------------
// POST /forums/{forumId}/topics — create and verify full response
// ---------------------------------------------------------------------------
test('POST /forums/1/topics as alice — 201 with full TopicDTO', async () => {
	const res = await apiCtx.post(`${API}/forums/1/topics`, {
		data: { title: 'Threads E2E Topic', content: 'Initial post content for threads test' },
		headers: auth(),
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	expect(body.data).toMatchObject({
		id:             expect.any(Number),
		title:          'Threads E2E Topic',
		forumId:        1,
		authorId:       expect.any(Number),
		postCount:      expect.any(Number),
		lastPosterName: expect.any(String),
		lastPostTime:   expect.anything(),
		createdAt:      expect.anything(),
	});

	createdTopicId = body.data.id;
	expect(createdTopicId).toBeGreaterThan(0);
});

test('GET /topics/{newId} — returns the created topic', async () => {
	const res = await apiCtx.get(`${API}/topics/${createdTopicId}`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	expect(data.id).toBe(createdTopicId);
	expect(data.title).toBe('Threads E2E Topic');
	expect(data.forumId).toBe(1);
});

test('GET /forums/1/topics — newly created topic appears in list', async () => {
	const res = await apiCtx.get(`${API}/forums/1/topics`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	const found = body.data.find((t: { id: number }) => t.id === createdTopicId);
	expect(found).toBeDefined();
});

// ---------------------------------------------------------------------------
// GET /topics/{topicId}/posts — new endpoint, no existing E2E coverage
// ---------------------------------------------------------------------------
test('GET /topics/{newId}/posts without auth — 200 (anonymous access allowed)', async () => {
	const res = await apiCtx.get(`${API}/topics/${createdTopicId}/posts`);

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

test('GET /topics/{newId}/posts — returns initial post with full PostDTO shape', async () => {
	const res = await apiCtx.get(`${API}/topics/${createdTopicId}/posts`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.length).toBeGreaterThanOrEqual(1);
	expect(body.meta.total).toBeGreaterThanOrEqual(1);

	const post = body.data[0];
	expect(post).toMatchObject({
		id:       expect.any(Number),
		topicId:  createdTopicId,
		forumId:  1,
		authorId: expect.any(Number),
		content:  expect.any(String),
	});
});

test('GET /topics/{newId}/posts?page=1&perPage=1 — pagination works', async () => {
	const res = await apiCtx.get(`${API}/topics/${createdTopicId}/posts?page=1&perPage=1`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.data.length).toBeLessThanOrEqual(1);
	expect(body.meta.perPage).toBe(1);
});

test('GET /topics/999/posts — 404 for non-existing topic', async () => {
	const res = await apiCtx.get(`${API}/topics/999/posts`, { headers: auth() });

	expect(res.status()).toBe(404);

	const body = await res.json();
	expect(body.status ?? body.error).toBeTruthy();
});

// ---------------------------------------------------------------------------
// POST /topics/{topicId}/posts — validation guards
// ---------------------------------------------------------------------------
test('POST /topics/{newId}/posts without token — 401', async () => {
	const res = await apiCtx.post(`${API}/topics/${createdTopicId}/posts`, {
		data: { content: 'Unauthorized reply' },
	});

	expect(res.status()).toBe(401);
});

test('POST /topics/{newId}/posts with empty content — 400', async () => {
	const res = await apiCtx.post(`${API}/topics/${createdTopicId}/posts`, {
		data: { content: '   ' },
		headers: auth(),
	});

	expect(res.status()).toBe(400);

	const body = await res.json();
	expect(body.error).toBeTruthy();
});

test('POST /topics/{newId}/posts with missing content — 400', async () => {
	const res = await apiCtx.post(`${API}/topics/${createdTopicId}/posts`, {
		data: {},
		headers: auth(),
	});

	expect(res.status()).toBe(400);
});

test('POST /topics/999/posts — 404 for non-existing topic', async () => {
	const res = await apiCtx.post(`${API}/topics/999/posts`, {
		data: { content: 'Reply to ghost topic' },
		headers: auth(),
	});

	expect(res.status()).toBe(404);
});

// ---------------------------------------------------------------------------
// POST /topics/{topicId}/posts — create reply and verify
// ---------------------------------------------------------------------------
test('POST /topics/{newId}/posts as alice — 201 with full PostDTO', async () => {
	const res = await apiCtx.post(`${API}/topics/${createdTopicId}/posts`, {
		data: { content: 'Reply from threads E2E test' },
		headers: auth(),
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	expect(body.data).toMatchObject({
		id:       expect.any(Number),
		topicId:  createdTopicId,
		forumId:  1,
		authorId: expect.any(Number),
		content:  'Reply from threads E2E test',
	});
});

test('GET /topics/{newId}/posts — shows 2 posts after reply', async () => {
	const res = await apiCtx.get(`${API}/topics/${createdTopicId}/posts`, { headers: auth() });

	expect(res.status()).toBe(200);

	const body = await res.json();
	expect(body.meta.total).toBeGreaterThanOrEqual(2);
	expect(body.data.length).toBeGreaterThanOrEqual(2);

	const contents = body.data.map((p: { content: string }) => p.content);
	expect(contents).toContain('Reply from threads E2E test');
});

test('GET /topics/{newId}/posts — all posts have correct topicId and forumId', async () => {
	const res = await apiCtx.get(`${API}/topics/${createdTopicId}/posts`, { headers: auth() });

	expect(res.status()).toBe(200);

	const { data } = await res.json();
	for (const post of data) {
		expect(post.topicId).toBe(createdTopicId);
		expect(post.forumId).toBe(1);
	}
});
