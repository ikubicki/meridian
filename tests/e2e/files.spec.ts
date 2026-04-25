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

/**
 * M5b Storage Service — E2E tests
 *
 * Use cases covered:
 *   UC-1  Upload without auth                 → 401
 *   UC-2  Upload with missing file field       → 400 (missing_file)
 *   UC-3  Upload with invalid asset_type       → 400 (invalid_asset_type)
 *   UC-4  Upload empty file                    → 400 (empty_file)
 *   UC-5  Successful avatar upload             → 201 + correct response shape
 *   UC-6  Successful attachment upload         → 201 + private URL
 *   UC-7  File URL discriminates visibility    → avatar URL is public path
 *   UC-8  Attachment URL is auth-gated path    → /api/v1/files/:id/download
 *   UC-9  Duplicate upload (same content)      → 201 (idempotency not enforced — separate IDs)
 *   UC-10 File too large                       → 413
 */

import { test, expect, APIRequestContext, request as playwrightRequest } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const API = '/api/v1';

let apiCtx: APIRequestContext;
let aliceToken: string;
let avatarFileId: string;
let attachmentFileId: string;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Minimal valid JPEG — 1×1 pixel, base-64 decoded to a Buffer.
 * This is a real JFIF header so finfo_file() on the server returns image/jpeg.
 */
function minimalJpeg(): Buffer {
	return Buffer.from(
		'/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8U'
		+ 'HRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgN'
		+ 'DRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
		+ 'MjL/wAARCAABAAEDASIAAhEBAxEB/8QAFgABAQEAAAAAAAAAAAAAAAAABgUEB'
		+ '/8QAIBAAAQQCAgMAAAAAAAAAAAAAAQIDBAURITFBUf/EABQBAQAAAAAAAAAAAAAAAAAAAAD'
		+ '/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwABbptQAAAAAAAAAAAAAA'
		+ 'AAAAAAAAAAAAAAAAAAAAAAAAAAAB/9k=',
		'base64',
	);
}

function auth(token: string): Record<string, string> {
	return { Authorization: `Bearer ${token}` };
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

test.beforeAll(async () => {
	apiCtx = await playwrightRequest.newContext({
		baseURL: process.env.API_BASE_URL ?? 'http://localhost:8181',
		// Explicitly set headers WITHOUT Content-Type so multipart requests can
		// set their own Content-Type (multipart/form-data; boundary=...) correctly.
		// The global playwright.config.ts sets Content-Type: application/json which
		// would override multipart boundaries if inherited.
		extraHTTPHeaders: {
			'Accept': 'application/json',
		},
	});

	// Authenticate as alice
	const res = await apiCtx.post(`${API}/auth/login`, {
		data: {
			username: 'alice',
			password: 'testpass',
		},
		headers: { 'Content-Type': 'application/json' },
	});

	expect(res.status()).toBe(200);
	const body = await res.json();
	aliceToken = body.data.accessToken;
});

test.afterAll(async () => {
	await apiCtx.dispose();
});

// ---------------------------------------------------------------------------
// UC-1: Upload without auth → 401
// ---------------------------------------------------------------------------
test('POST /files without auth — 401', async () => {
	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			file:       { name: 'avatar.jpg', mimeType: 'image/jpeg', buffer: minimalJpeg() },
			asset_type: 'avatar',
		},
	});

	expect(res.status()).toBe(401);
});

// ---------------------------------------------------------------------------
// UC-2: Upload with missing file field → 400
// ---------------------------------------------------------------------------
test('POST /files without file field — 400 (missing_file)', async () => {
	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			asset_type: 'avatar',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(400);

	const body = await res.json();
	expect(body.code).toBe('missing_file');
});

// ---------------------------------------------------------------------------
// UC-3: Upload with invalid asset_type → 400
// ---------------------------------------------------------------------------
test('POST /files with invalid asset_type — 400 (invalid_asset_type)', async () => {
	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			file:       { name: 'avatar.jpg', mimeType: 'image/jpeg', buffer: minimalJpeg() },
			asset_type: 'nonexistent_type',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(400);

	const body = await res.json();
	expect(body.code).toBe('invalid_asset_type');
});

// ---------------------------------------------------------------------------
// UC-4: Upload empty file → 400
// ---------------------------------------------------------------------------
test('POST /files with empty file — 400 (empty_file)', async () => {
	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			file:       { name: 'empty.jpg', mimeType: 'image/jpeg', buffer: Buffer.alloc(0) },
			asset_type: 'avatar',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(400);

	const body = await res.json();
	expect(body.code).toBe('empty_file');
});

// ---------------------------------------------------------------------------
// UC-5: Successful avatar upload → 201 with correct shape
// ---------------------------------------------------------------------------
test('POST /files as avatar — 201 with file_id, url, mime_type, filesize', async () => {
	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			file:       { name: 'avatar.jpg', mimeType: 'image/jpeg', buffer: minimalJpeg() },
			asset_type: 'avatar',
			forum_id:   '0',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	expect(body).toMatchObject({
		file_id:   expect.any(String),
		url:       expect.any(String),
		mime_type: expect.any(String),
		filesize:  expect.any(Number),
	});

	// UUID hex — 32 chars, no dashes
	expect(body.file_id).toMatch(/^[0-9a-f]{32}$/i);
	expect(body.filesize).toBeGreaterThan(0);

	avatarFileId = body.file_id;
});

// ---------------------------------------------------------------------------
// UC-7: Avatar URL is a direct public path (nginx-served)
// ---------------------------------------------------------------------------
test('POST /files as avatar — URL is a public /images/avatars path', async () => {
	// avatar is AssetType::Avatar → FileVisibility::Public → direct URL
	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			file:       { name: 'avatar2.jpg', mimeType: 'image/jpeg', buffer: minimalJpeg() },
			asset_type: 'avatar',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(201);

	const { url } = await res.json();
	// Public avatar URL must be a direct server path, NOT an /api/v1 auth-gated path
	expect(url).not.toContain('/api/v1/files');
	expect(url).toBeTruthy();
});

// ---------------------------------------------------------------------------
// UC-6: Successful attachment upload → 201
// ---------------------------------------------------------------------------
test('POST /files as attachment — 201 with file_id and auth-gated URL', async () => {
	const textContent = Buffer.from('This is a test attachment file.');

	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			file:       { name: 'document.txt', mimeType: 'text/plain', buffer: textContent },
			asset_type: 'attachment',
			forum_id:   '1',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	expect(body).toMatchObject({
		file_id:   expect.any(String),
		url:       expect.any(String),
		mime_type: expect.any(String),
		filesize:  expect.any(Number),
	});

	expect(body.file_id).toMatch(/^[0-9a-f]{32}$/i);
	attachmentFileId = body.file_id;
});

// ---------------------------------------------------------------------------
// UC-8: Attachment URL is auth-gated (contains /api/v1/files)
// ---------------------------------------------------------------------------
test('POST /files as attachment — URL is auth-gated /api/v1/files path', async () => {
	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			file:       { name: 'doc.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') },
			asset_type: 'attachment',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(201);

	const { url } = await res.json();
	// Private attachment URL must go through the auth-gated PHP endpoint
	expect(url).toContain('/api/v1/files/');
});

// ---------------------------------------------------------------------------
// UC-9: Server-side MIME detection (ignore client Content-Type)
// ---------------------------------------------------------------------------
test('POST /files — MIME detected server-side, not from client header', async () => {
	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			// Send a real JPEG but tell client it's text/plain — server must detect image/jpeg
			file:       { name: 'fake.txt', mimeType: 'text/plain', buffer: minimalJpeg() },
			asset_type: 'avatar',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	// Server-side finfo_file() must detect image/jpeg regardless of client Content-Type
	expect(body.mime_type).toBe('image/jpeg');
});

// ---------------------------------------------------------------------------
// UC-10: File too large → 413
// ---------------------------------------------------------------------------
test('POST /files with oversized file — 413', async () => {
	// 11 MB exceeds the 10 MB limit
	const oversized = Buffer.alloc(11 * 1024 * 1024, 0x00);

	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			file:       { name: 'huge.bin', mimeType: 'application/octet-stream', buffer: oversized },
			asset_type: 'attachment',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(413);
});

// ---------------------------------------------------------------------------
// UC-11: export asset type is accepted
// ---------------------------------------------------------------------------
test('POST /files as export — 201', async () => {
	const res = await apiCtx.post(`${API}/files`, {
		multipart: {
			file:       { name: 'export.csv', mimeType: 'text/csv', buffer: Buffer.from('id,name\n1,test') },
			asset_type: 'export',
		},
		headers: auth(aliceToken),
	});

	expect(res.status()).toBe(201);

	const body = await res.json();
	expect(body.file_id).toMatch(/^[0-9a-f]{32}$/i);
});
