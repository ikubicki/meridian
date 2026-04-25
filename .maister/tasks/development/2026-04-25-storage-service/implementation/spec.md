# Specification: M5b Storage Service (`phpbb\storage`)

## Goal

Implement `phpbb\storage` — a unified file-storage infrastructure layer for phpBB4 Meridian — replacing the legacy separate attachment and avatar subsystems with a single, quota-enforced, event-driven, Flysystem-backed storage service exposing a REST API.

---

## User Stories

- As a forum user, I want to upload an attachment so that it is stored safely and counted against my quota before my post is submitted.
- As a consumer plugin (e.g., `phpbb\threads\AttachmentPlugin`), I want to `claim()` a previously stored file so that it is no longer treated as an orphan.
- As an admin, I want orphaned files older than 24 hours to be automatically deleted by cron so that storage is not wasted on abandoned uploads.
- As a forum user, I want my avatar served as a direct URL (fast, nginx-cached) while my private attachments require authentication before download.
- As a developer consuming the API, I want a REST endpoint `POST /api/v1/files` that stores a file and returns its UUID, URL, mime type, and size.
- As an admin, I want per-user (and optionally per-forum) storage quotas enforced atomically at upload time, with a daily cron reconciliation as a safety net.
- As an image consumer, I want image variants (thumbnails) generated automatically after upload in an error-isolated manner — failures must not affect the upload response.

---

## Scope

### In Scope

1. **Core storage**: `store()`, `retrieve()`, `delete()`, `claim()`, `getUrl()`, `exists()` via `StorageService` facade
2. **DB schema**: `phpbb_stored_files` + `phpbb_storage_quotas` tables with full indexes
3. **Quota enforcement**: atomic `UPDATE WHERE used_bytes + size <= max_bytes` + daily cron reconciliation
4. **Orphan lifecycle**: `is_orphan=1` on upload → `is_orphan=0` on claim → cron deletes unclaimed > 24 h
5. **Error-isolated synchronous variant/thumbnail generation**: `ThumbnailListener` subscribes to `FileStoredEvent` (synchronous Symfony dispatcher); GD-based; stored as child rows with `parent_id`; listener catches all exceptions so failures never propagate to the upload response
6. **Flysystem adapter layer**: local adapter now; factory pattern for future S3/GCS swap
7. **REST API**: `POST /api/v1/files` with multipart/form-data, JWT auth, returns 201 + `FileStoredResponse`
8. **Hybrid file serving**: public assets (avatar) → direct nginx URL; private assets (attachment) → PHP auth + X-Accel-Redirect
9. **DI registration**: all services declared in `src/phpbb/config/services.yaml`
10. **Breaking change**: `DomainEvent::entityId` widened from `int` to `string|int`
11. **Composer dependency**: `league/flysystem` added to `composer.json`
12. **Unit + integration tests** for all layers

### Out of Scope

- Consumer plugins (`AttachmentPlugin`, `AvatarPlugin`) — those live in `phpbb\threads` and `phpbb\user`
- Plugin-specific metadata tables (`phpbb_attachment_metadata`, `phpbb_avatar_metadata`)
- S3 / GCS adapter implementation (factory skeleton only)
- ACP quota management UI
- File deduplication / content-addressable storage
- Direct file streaming fallback (X-Accel-Redirect failure recovery)
- Video/audio transcoding variants
- Download tracking / analytics
- `GET /api/v1/files/{uuid}` (private file serving endpoint) — out of scope for this task

---

## Functional Requirements

### FR-01: UUID v7 Generation
The service must generate UUID v7 identifiers server-side using `random_bytes()` (no external UUID library). The UUID is stored as `BINARY(16)` in the database and exposed as a 32-character hex string (no dashes) in all API responses and domain events.

### FR-02: File Store
`StorageService::store(StoreFileRequest $request): DomainEventCollection` must:
- Validate that `assetType` is a known `AssetType` enum value
- Validate that `mimeType` is non-empty and `filesize > 0`
- Enforce the user's quota atomically before writing to disk
- Write the file to Flysystem using the UUID hex as the physical filename
- Compute SHA-256 checksum of the file content
- Insert a row into `phpbb_stored_files` with `is_orphan=1`
- Dispatch `FileStoredEvent` on success
- Throw `QuotaExceededException` (HTTP 400) when quota is exceeded
- Throw `UploadValidationException` (HTTP 400) on validation failures
- Throw `StorageWriteException` on Flysystem I/O failure (quota must be rolled back)

### FR-03: File Retrieve
`StorageService::retrieve(string $fileId): StoredFile` must:
- Look up `phpbb_stored_files` by UUID hex
- Throw `FileNotFoundException` if not found
- Return a fully hydrated `StoredFile` entity

### FR-04: File Delete
`StorageService::delete(string $fileId, int $actorId): DomainEventCollection` must:
- Retrieve the `StoredFile`; throw `FileNotFoundException` if absent
- Delete the physical file via Flysystem
- Decrement the uploader's quota by the file's `filesize`
- Delete the DB row (and any child variant rows via `parent_id`)
- Dispatch `FileDeletedEvent`
- Wrap all steps in a DB transaction; roll back and re-throw on failure

### FR-05: File Claim
`StorageService::claim(ClaimContext $ctx): DomainEventCollection` must:
- Retrieve the `StoredFile`; throw `FileNotFoundException` if absent
- Throw `OrphanClaimException` if the file is already claimed (`is_orphan=0`)
- Set `is_orphan=0` and `claimed_at=NOW()` in the DB
- Dispatch `FileClaimedEvent`

### FR-06: URL Generation
`StorageService::getUrl(string $fileId): string` must:
- Return a direct nginx URL for public assets (e.g., `AssetType::Avatar`)
- Return a PHP auth endpoint URL for private assets (e.g., `AssetType::Attachment`)
- The `UrlGenerator` uses file `visibility` field (not `asset_type`) as the discriminator

### FR-07: File Existence Check
`StorageService::exists(string $fileId): bool` must return `true` only if the file row exists in the DB (does not check Flysystem).

### FR-08: Quota Enforcement
`QuotaService::checkAndReserve(int $userId, int $forumId, int $bytes): void` must:
- Execute an atomic `UPDATE phpbb_storage_quotas SET used_bytes = used_bytes + :bytes WHERE user_id = :userId AND forum_id = :forumId AND used_bytes + :bytes <= max_bytes`
- If the `UPDATE` affects 0 rows, call `StorageQuotaRepositoryInterface::findByUserAndForum()` to distinguish two cases:
  - **Row missing** (new user / no quota configured): treat as unlimited quota — call `StorageQuotaRepositoryInterface::initDefault(userId, forumId)` to insert a default row with `max_bytes = PHP_INT_MAX` and retry the reservation once
  - **Row exists but quota full**: dispatch `QuotaExceededEvent` and throw `QuotaExceededException`

`StorageQuotaRepositoryInterface::initDefault(int $userId, int $forumId): void` must insert a row via `INSERT IGNORE` (no-op if it already exists due to race condition).

`QuotaService::release(int $userId, int $forumId, int $bytes): void` must atomically decrement `used_bytes` (floor at 0) using: `UPDATE ... SET used_bytes = GREATEST(0, used_bytes - :bytes) WHERE ...`.

### FR-09: Quota Reconciliation
`QuotaReconciliationJob::run(): void` must:
- For each `(user_id, forum_id)` pair: `UPDATE phpbb_storage_quotas SET used_bytes = (SELECT SUM(filesize) FROM phpbb_stored_files WHERE uploader_id = user_id AND forum_id = ...) WHERE ...`
- Dispatch `QuotaReconciledEvent` per corrected row
- This job is triggered daily via cron

### FR-10: Orphan Cleanup
`OrphanCleanupJob::run(): void` must:
- Query `phpbb_stored_files WHERE is_orphan=1 AND created_at < (NOW() - 86400)`
- For each orphan: dispatch `OrphanCleanupEvent`, delete physical file via Flysystem, decrement quota, delete DB row
- Run atomically per file (one DB transaction per file; continue on individual failure, log errors)
- This job is triggered **hourly** via cron (runs frequently, cost is cheap when there are no orphans to delete)

### FR-11: Error-Isolated Synchronous Variant Generation
`ThumbnailListener` must subscribe to `FileStoredEvent` (standard Symfony synchronous event dispatcher) and:
- Skip if `$event->assetType` is not image-compatible
- Generate a thumbnail using PHP GD (`imagecreatefromstring()` → `imagecopyresampled()`)
- Insert a child `StoredFile` row with `parent_id` = original file UUID and `variant_type = VariantType::Thumbnail`
- Store the thumbnail file via Flysystem
- Dispatch `VariantGeneratedEvent`
- Never throw — catch all exceptions and log; thumbnail failure must not affect the upload response

### FR-12: REST File Upload Endpoint
`POST /api/v1/files` must:
- Require JWT authentication (reject unauthenticated with 401)
- Accept `multipart/form-data` with fields: `file` (binary), `asset_type` (string), `forum_id` (int, optional, default 0)
- Validate `asset_type` is one of `attachment | avatar | export`
- Validate file is present and non-empty
- Enforce file size limit (respond 413 if exceeded)
- Detect MIME type server-side using `finfo_open(FILEINFO_MIME_TYPE)` + `finfo_file()` on the uploaded temp file path; the client-provided `Content-Type` header is **ignored** for MIME detection (NFR-S6)
- Call `StorageService::store()` and return 201 with `FileStoredResponse` JSON
- Return 400 with structured error body on validation failure or quota exceeded

### FR-13: DomainEvent entityId Breaking Change
`phpbb\common\Event\DomainEvent::entityId` type must be widened from `int` to `string|int`. All existing event subclasses that pass `int` remain valid (no changes needed in call sites — `int` satisfies `string|int`). Storage events pass `string` (UUID hex).

---

## Non-Functional Requirements

### Security

- **NFR-S1**: UUID v7 filenames on disk prevent enumeration of sequential IDs
- **NFR-S2**: Private files served exclusively via X-Accel-Redirect after PHP-layer auth check — never via direct nginx path
- **NFR-S3**: SHA-256 checksum stored per file for integrity verification
- **NFR-S4**: All SQL queries use DBAL prepared statements with named parameters — zero raw user input interpolation
- **NFR-S5**: `asset_type` validated against `AssetType` enum before any DB write
- **NFR-S6**: File MIME type validated server-side (not trusted from client `Content-Type`)
- **NFR-S7**: Upload size limits enforced at the controller level before Flysystem write

### Performance

- **NFR-P1**: Quota check must be a single atomic `UPDATE` — no `SELECT` + `UPDATE` race condition
- **NFR-P2**: Thumbnail generation occurs in a listener, never in the upload critical path
- **NFR-P3**: Index `idx_orphan (is_orphan, created_at)` ensures cron cleanup queries are O(index scan)
- **NFR-P4**: `idx_uploader (uploader_id)` and `idx_parent (parent_id)` support common query patterns

### Atomicity

- **NFR-A1**: `store()` must use a DB transaction: quota update + file write + DB insert. On Flysystem failure, the quota must be released and the transaction rolled back
- **NFR-A2**: `delete()` must use a DB transaction: quota decrement + Flysystem delete + DB delete
- **NFR-A3**: Each orphan deletion in `OrphanCleanupJob` is its own transaction (per-file isolation)

### Compatibility

- **NFR-C1**: All existing event subclasses (messaging, threads, user, auth, hierarchy) remain valid after the `entityId: int → string|int` widening

---

## Data Model

### Table: `phpbb_stored_files`

```sql
CREATE TABLE phpbb_stored_files (
    id             BINARY(16)    NOT NULL,
    asset_type     VARCHAR(20)   NOT NULL,          -- 'attachment' | 'avatar' | 'export'
    visibility     VARCHAR(10)   NOT NULL,           -- 'public' | 'private'
    original_name  VARCHAR(255)  NOT NULL,
    physical_name  VARCHAR(255)  NOT NULL,           -- UUID v7 hex string (32 chars)
    mime_type      VARCHAR(127)  NOT NULL,
    filesize       INT UNSIGNED  NOT NULL,
    checksum       CHAR(64)      NOT NULL,           -- SHA-256 hex
    is_orphan      TINYINT(1)    NOT NULL DEFAULT 1, -- 1 = unclaimed, 0 = claimed
    parent_id      BINARY(16)    DEFAULT NULL,       -- NULL for originals; parent UUID for variants
    variant_type   VARCHAR(20)   DEFAULT NULL,       -- NULL for originals; 'thumbnail' | 'webp' | 'medium'
    uploader_id    INT UNSIGNED  NOT NULL,
    forum_id       INT UNSIGNED  NOT NULL DEFAULT 0, -- 0 = not forum-scoped
    created_at     INT UNSIGNED  NOT NULL,           -- Unix timestamp
    claimed_at     INT UNSIGNED  DEFAULT NULL,       -- Unix timestamp; NULL until claimed
    PRIMARY KEY (id),
    INDEX idx_uploader (uploader_id),
    INDEX idx_orphan   (is_orphan, created_at),
    INDEX idx_parent   (parent_id)
);
```

**Notes**:
- `id` stored as `BINARY(16)` for space efficiency; exposed externally as 32-char hex
- `physical_name` is the UUID hex — written to Flysystem as the filename
- `is_orphan=1` on insert; set to `0` by `claim()`; cron deletes rows where `is_orphan=1 AND created_at < NOW()-86400`
- `parent_id` links variant rows to their original; cascade delete must be handled in code (no FK constraint for portability)

### Table: `phpbb_storage_quotas`

```sql
CREATE TABLE phpbb_storage_quotas (
    user_id        INT UNSIGNED  NOT NULL,
    forum_id       INT UNSIGNED  NOT NULL DEFAULT 0, -- 0 = global user quota
    used_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    max_bytes      BIGINT UNSIGNED NOT NULL,
    updated_at     INT UNSIGNED  NOT NULL,           -- Unix timestamp of last update
    PRIMARY KEY (user_id, forum_id)
);
```

**Notes**:
- Composite PK `(user_id, forum_id)` — one row per user per forum, plus `forum_id=0` for global quota
- `used_bytes` is modified only by atomic `UPDATE WHERE used_bytes + :size <= max_bytes`
- `updated_at` is set on every write (for auditing and reconciliation ordering)

### Enum Values

| Enum | Values |
|------|--------|
| `AssetType` | `attachment`, `avatar`, `export` |
| `FileVisibility` | `public`, `private` |
| `VariantType` | `thumbnail`, `webp`, `medium` |

### Visibility Mapping (derived, not stored separately)

| `AssetType` | `FileVisibility` |
|-------------|-----------------|
| `avatar` | `public` |
| `attachment` | `private` |
| `export` | `private` |

---

## API Contract

### `POST /api/v1/files`

**Authentication**: JWT required (middleware rejects with 401 if absent or invalid)

**Request**:
```
Content-Type: multipart/form-data

Fields:
  file        (required) binary — the file to upload
  asset_type  (required) string — one of: attachment | avatar | export
  forum_id    (optional) integer — default 0
```

**Responses**:

| Status | Condition | Body |
|--------|-----------|------|
| 201 Created | Upload successful | `FileStoredResponse` JSON |
| 400 Bad Request | Missing `file`, invalid `asset_type`, quota exceeded, validation failure | `{"error": "...", "code": "..."}` |
| 401 Unauthorized | Missing or invalid JWT | `{"error": "Unauthorized"}` |
| 413 Payload Too Large | File exceeds size limit | `{"error": "File too large"}` |
| 500 Internal Server Error | Flysystem I/O failure | `{"error": "Storage write failed"}` |

**201 Response body** (`FileStoredResponse`):
```json
{
    "file_id": "0194f3a1c8e47b2a8c3d4e5f6789abcd",
    "url": "https://example.com/images/avatars/upload/0194f3a1c8e47b2a8c3d4e5f6789abcd",
    "mime_type": "image/jpeg",
    "filesize": 204800
}
```

**Behaviour**:
- The file is stored with `is_orphan=1` — it is NOT yet permanently linked to any entity
- The caller must subsequently call `StorageService::claim()` to mark the file as owned
- Quota is reserved at upload time; released if the file is eventually deleted as an orphan

---

## Service Interfaces

### `StorageServiceInterface`

```php
interface StorageServiceInterface
{
    public function store(StoreFileRequest $request): DomainEventCollection;
    public function retrieve(string $fileId): StoredFile;
    public function delete(string $fileId, int $actorId): DomainEventCollection;
    public function claim(ClaimContext $ctx): DomainEventCollection;
    public function getUrl(string $fileId): string;
    public function exists(string $fileId): bool;
}
```

### `StoredFileRepositoryInterface`

```php
interface StoredFileRepositoryInterface
{
    public function findById(string $fileId): ?StoredFile;
    public function save(StoredFile $file): void;
    public function delete(string $fileId): void;
    public function findOrphansBefore(int $timestamp): array; // StoredFile[]
    public function markClaimed(string $fileId, int $claimedAt): void;
    public function findVariants(string $parentId): array;   // StoredFile[]
}
```

### `StorageQuotaRepositoryInterface`

```php
interface StorageQuotaRepositoryInterface
{
    public function findByUserAndForum(int $userId, int $forumId): ?StorageQuota;
    public function incrementUsage(int $userId, int $forumId, int $bytes): bool; // false = quota exceeded
    public function decrementUsage(int $userId, int $forumId, int $bytes): void;
    public function reconcile(int $userId, int $forumId, int $actualBytes): void;
    public function findAllUserForumPairs(): array; // [['user_id' => ..., 'forum_id' => ...], ...]
    public function initDefault(int $userId, int $forumId): void; // INSERT IGNORE with max_bytes=PHP_INT_MAX
}
```

### `QuotaServiceInterface`

```php
interface QuotaServiceInterface
{
    public function checkAndReserve(int $userId, int $forumId, int $bytes): void; // throws QuotaExceededException
    public function release(int $userId, int $forumId, int $bytes): void;
    public function reconcileAll(): DomainEventCollection;
}
```

### `OrphanServiceInterface`

```php
interface OrphanServiceInterface
{
    public function cleanupExpired(int $olderThanTimestamp): DomainEventCollection;
}
```

### `UrlGeneratorInterface`

```php
interface UrlGeneratorInterface
{
    public function generateUrl(StoredFile $file): string;
    // Public URL: {baseUrl}/images/avatars/upload/{physicalName} for avatars;
    //             {baseUrl}/files/{physicalName} for exports
    public function generatePublicUrl(string $physicalName, AssetType $assetType): string;
    // Private auth URL: {baseUrl}/api/v1/files/{fileId}/download (future GET endpoint, out of scope to implement, but URL format must be stable)
    public function generatePrivateUrl(string $fileId): string;
}
```

---

## Architecture Decisions

### ADR-001: Single `phpbb_stored_files` Table
All asset types (`attachment`, `avatar`, `export`) in one table with an `asset_type` discriminator column. Plugin-specific metadata (e.g., `phpbb_attachment_metadata`) lives in plugin-owned tables referencing `stored_files.id`. Rationale: eliminates code duplication across subsystems; simplifies quota, orphan, and variant tracking.

### ADR-002: UUID v7 as `BINARY(16)`
UUIDs generated server-side via `random_bytes()` with embedded millisecond timestamp (no external library). Stored as `BINARY(16)` for space efficiency. Exposed as 32-char hex strings. Rationale: time-sortable, non-enumerable, no DB roundtrip for ID generation, safe from sequential ID enumeration.

### ADR-003: Flysystem (`league/flysystem`)
All file I/O goes through `League\Flysystem\FilesystemOperator`. Local adapter in production; `StorageAdapterFactory` returns the configured adapter from DI. Future swap to S3/GCS requires only a DI config change. Rationale: decouples storage logic from filesystem implementation.

### ADR-004: Async Variant Generation
`ThumbnailListener` subscribes to `FileStoredEvent` and generates variants after the upload response is sent. Variants are child rows in `phpbb_stored_files` with `parent_id` set and `variant_type` filled. Rationale: upload latency is unaffected by image processing; failures are isolated.

### ADR-005: Counter + Reconciliation Quotas
Quota is enforced via a single atomic `UPDATE WHERE used_bytes + :bytes <= max_bytes`. A daily `QuotaReconciliationJob` recomputes `used_bytes` from `SUM(filesize)` as a safety net for drift. Rationale: single-query atomicity avoids SELECT+UPDATE race; reconciliation corrects counter drift without real-time overhead.

### ADR-006: Hybrid File Serving
Public assets (`visibility='public'`, e.g., avatars): direct nginx URL generated by `UrlGenerator`. Private assets (`visibility='private'`, e.g., attachments): PHP auth endpoint URL; controller validates JWT/session, then responds with `X-Accel-Redirect` header. Rationale: nginx-native serving for public content (fast, zero PHP); controlled auth for private content.

### ADR-007: Flat Directory Layout + `is_orphan` Flag
Files under flat directories: `files/` for attachments, `images/avatars/upload/` for avatars. UUID filenames eliminate collisions. `is_orphan=1` set on insert; `OrphanCleanupJob` deletes rows with `is_orphan=1 AND created_at < NOW()-86400`. Rationale: legacy-compatible paths; DB-tracked flag avoids filesystem scanning.

---

## Reusable Components

### Existing Code to Leverage

| Component | File | Usage |
|-----------|------|-------|
| `DomainEvent` base class | [src/phpbb/common/Event/DomainEvent.php](../../../../../../src/phpbb/common/Event/DomainEvent.php) | All 7 storage events extend this (after `entityId` widening) |
| `DomainEventCollection` | [src/phpbb/common/Event/DomainEventCollection.php](../../../../../../src/phpbb/common/Event/DomainEventCollection.php) | Return type for all mutating service methods |
| `RepositoryException` | `src/phpbb/db/Exception/RepositoryException.php` | Thrown by both DBAL repositories on query failure |
| `PaginationContext` + `PaginatedResult` | `src/phpbb/common/` | Available for `findOrphansBefore()` if paginated cleanup is needed |
| `IntegrationTestCase` | `tests/phpbb/` | Base class for SQLite-backed repository tests |
| DBAL `Connection` | Doctrine DBAL 4 (already in `composer.json`) | Used by both repositories |
| Symfony EventDispatcher | Already in `composer.json` | Event dispatch in `StorageService` |
| Messaging service pattern | `src/phpbb/messaging/MessagingService.php` | Template for `StorageService` facade (constructor injection, transaction wrapping, event collection building) |
| Repository pattern | `src/phpbb/messaging/Repository/` | Template for `DbalStoredFileRepository` and `DbalStorageQuotaRepository` (`const TABLE`, named params, private `hydrate()`) |
| Controller pattern | `src/phpbb/api/Controller/` | Template for `FilesController` (route attributes, `_api_user`, JSON response) |
| `services.yaml` structure | `src/phpbb/config/services.yaml` | DI registration pattern identical to messaging module (lines 197–259) |

### New Components Required

| Component | Justification |
|-----------|---------------|
| `StorageAdapterFactory` | No existing Flysystem factory in codebase; new dependency |
| UUID v7 generator function | No UUID library in project; must be pure PHP |
| `QuotaService` + `QuotaReconciliationJob` | No quota system exists anywhere in codebase |
| `ThumbnailListener` + `ThumbnailGenerator` | No image variant system exists |
| `OrphanService` + `OrphanCleanupJob` | No orphan lifecycle management exists |
| `UrlGenerator` with X-Accel-Redirect support | No hybrid URL generation in current codebase |
| `FilesController` (upload endpoint) | New route; no file upload controller exists |
| All enums (`AssetType`, `FileVisibility`, `VariantType`) | Storage-domain-specific; no analogues elsewhere |
| All 5 storage exception classes | Storage-domain-specific |

---

## File Structure

```
src/phpbb/storage/
├── StorageService.php                          # Facade: store/retrieve/delete/claim/getUrl/exists
├── Contract/
│   ├── StorageServiceInterface.php
│   ├── QuotaServiceInterface.php
│   ├── OrphanServiceInterface.php
│   ├── UrlGeneratorInterface.php
│   ├── StoredFileRepositoryInterface.php
│   └── StorageQuotaRepositoryInterface.php
├── DTO/
│   ├── StoreFileRequest.php                    # assetType, uploaderId, forumId, tmpPath, originalName, mimeType, filesize
│   ├── FileStoredResponse.php                  # fileId (UUID hex), url, mimeType, filesize
│   ├── ClaimContext.php                        # fileId, actorId, entityType, entityId (string|int)
│   ├── FileClaimedResponse.php
│   ├── FileDeletedResponse.php
│   └── FileInfo.php                            # Full metadata for API read endpoints
├── Entity/
│   ├── StoredFile.php                          # Immutable; final readonly class
│   └── StorageQuota.php                        # userId, forumId, usedBytes, maxBytes
├── Enum/
│   ├── AssetType.php                           # Backed: Attachment='attachment', Avatar='avatar', Export='export'
│   ├── FileVisibility.php                      # Backed: Public='public', Private='private'
│   └── VariantType.php                         # Backed: Thumbnail='thumbnail', Webp='webp', Medium='medium'
├── Event/
│   ├── FileStoredEvent.php                     # entityId=fileId(string), actorId, + fileId, assetType
│   ├── FileClaimedEvent.php                    # entityId=fileId(string), actorId
│   ├── FileDeletedEvent.php                    # entityId=fileId(string), actorId
│   ├── VariantGeneratedEvent.php               # entityId=variantId(string), parentId
│   ├── QuotaExceededEvent.php                  # entityId=userId(int), forumId, requestedBytes, maxBytes
│   ├── QuotaReconciledEvent.php                # entityId=userId(int), forumId, oldBytes, newBytes
│   └── OrphanCleanupEvent.php                  # entityId=fileId(string), actorId=0 (system)
├── Exception/
│   ├── FileNotFoundException.php
│   ├── QuotaExceededException.php
│   ├── UploadValidationException.php
│   ├── OrphanClaimException.php
│   └── StorageWriteException.php
├── Adapter/
│   └── StorageAdapterFactory.php               # Returns League\Flysystem\Filesystem (local adapter, configurable)
├── Repository/
│   ├── DbalStoredFileRepository.php
│   └── DbalStorageQuotaRepository.php
├── Service/
│   ├── OrphanService.php
│   └── UrlGenerator.php
├── Quota/
│   ├── QuotaService.php
│   └── QuotaReconciliationJob.php
├── Variant/
│   ├── VariantGeneratorInterface.php
│   ├── ThumbnailGenerator.php                  # GD-based; imagecreatefromstring + imagecopyresampled
│   └── ThumbnailListener.php                   # Subscribes to FileStoredEvent; never throws
└── Orphan/
    └── OrphanCleanupJob.php                    # Cron-triggered; per-file transactions

src/phpbb/api/Controller/
└── FilesController.php                         # POST /api/v1/files

migrations/
├── phpbb_stored_files.sql
└── phpbb_storage_quotas.sql

tests/phpbb/storage/
├── Service/
│   ├── StorageServiceTest.php                  # Unit (mocked)
│   └── OrphanServiceTest.php                   # Unit (mocked)
├── Quota/
│   └── QuotaServiceTest.php                    # Unit (mocked)
├── Repository/
│   ├── DbalStoredFileRepositoryTest.php        # Integration (SQLite)
│   └── DbalStorageQuotaRepositoryTest.php      # Integration (SQLite)
└── Variant/
    └── ThumbnailListenerTest.php               # Unit (mocked GD)

tests/phpbb/api/Controller/
└── FilesControllerTest.php                     # Unit (mocked StorageService)
```

---

## Technical Approach

### UUID v7 Generation (pure PHP)

```
function generateUuidV7(): string
1. Get current time in milliseconds: (int)(microtime(true) * 1000)
2. Generate 10 random bytes via random_bytes(10)
3. Embed 48-bit timestamp in first 6 bytes of a 16-byte buffer
4. Set version nibble = 0x7 at byte 6 (bits 4-7)
5. Set variant bits = 0b10 at byte 8 (bits 6-7)
6. Fill remaining bytes with random data
7. Return bin2hex() of the 16-byte buffer (32-char hex, no dashes)
```

### Flysystem Integration

- `StorageAdapterFactory` is injected with a `$storagePath` string from DI config
- It creates `new LocalFilesystemAdapter($storagePath)` wrapped in `new Filesystem($adapter)`
- All file I/O in `StorageService`, `ThumbnailGenerator`, `OrphanCleanupJob` goes through `FilesystemOperator`
- Tests use a real temporary directory (not VFS, to avoid ext dependency)

### File Serving Architecture

```
Avatar request:
  Client → nginx → /images/avatars/upload/{uuid}
  nginx serves statically; no PHP involved

Attachment request (future GET endpoint, out of scope):
  Client → nginx → /api/v1/files/{uuid} → PHP FilesController
  PHP validates JWT → X-Accel-Redirect: /internal/files/{path}
  nginx serves file from protected internal path
```

### Transaction Pattern

Quota reservation is a standalone atomic `UPDATE` — it commits immediately and is NOT part of the outer DB transaction. On failure, it must be explicitly released as compensation (not via rollback).

```
store():
  // Step 1: reserve quota BEFORE opening transaction
  quotaService->checkAndReserve()    // atomic UPDATE; throws QuotaExceededException on failure

  // Step 2: DB transaction for file record + Flysystem write
  $connection->beginTransaction()
  try {
    filesystem->write()              // Flysystem
    fileRepo->save()                 // INSERT
    $connection->commit()
    return DomainEventCollection([new FileStoredEvent(...)])
  } catch {
    $connection->rollBack()
    quotaService->release()          // explicit compensation for the reserved quota
    throw StorageWriteException
  }
```

### DI Registration Pattern (services.yaml additions)

```yaml
# Storage module (M5b)
phpbb\storage\Repository\DbalStoredFileRepository:
    arguments:
        $connection: '@Doctrine\DBAL\Connection'

phpbb\storage\Contract\StoredFileRepositoryInterface:
    alias: phpbb\storage\Repository\DbalStoredFileRepository

phpbb\storage\Repository\DbalStorageQuotaRepository:
    arguments:
        $connection: '@Doctrine\DBAL\Connection'

phpbb\storage\Contract\StorageQuotaRepositoryInterface:
    alias: phpbb\storage\Repository\DbalStorageQuotaRepository

phpbb\storage\Adapter\StorageAdapterFactory:
    arguments:
        $storagePath: '%kernel.project_dir%'
    # Factory resolves sub-path per AssetType: files/ for attachment/export, images/avatars/upload/ for avatar

phpbb\storage\Service\UrlGenerator:
    arguments:
        $baseUrl: '%app.base_url%'

phpbb\storage\Contract\UrlGeneratorInterface:
    alias: phpbb\storage\Service\UrlGenerator

phpbb\storage\Quota\QuotaService:
    arguments:
        $quotaRepo: '@phpbb\storage\Contract\StorageQuotaRepositoryInterface'
        $connection: '@Doctrine\DBAL\Connection'

phpbb\storage\Contract\QuotaServiceInterface:
    alias: phpbb\storage\Quota\QuotaService

phpbb\storage\Service\OrphanService:
    arguments:
        $fileRepo: '@phpbb\storage\Contract\StoredFileRepositoryInterface'
        $adapterFactory: '@phpbb\storage\Adapter\StorageAdapterFactory'
        $quotaService: '@phpbb\storage\Contract\QuotaServiceInterface'
        $connection: '@Doctrine\DBAL\Connection'

phpbb\storage\Contract\OrphanServiceInterface:
    alias: phpbb\storage\Service\OrphanService

phpbb\storage\StorageService:
    arguments:
        $fileRepo: '@phpbb\storage\Contract\StoredFileRepositoryInterface'
        $quotaService: '@phpbb\storage\Contract\QuotaServiceInterface'
        $orphanService: '@phpbb\storage\Contract\OrphanServiceInterface'
        $urlGenerator: '@phpbb\storage\Contract\UrlGeneratorInterface'
        $adapterFactory: '@phpbb\storage\Adapter\StorageAdapterFactory'
        $connection: '@Doctrine\DBAL\Connection'

phpbb\storage\Contract\StorageServiceInterface:
    alias: phpbb\storage\StorageService
    public: true

phpbb\storage\Variant\ThumbnailListener:
    arguments:
        $generator: '@phpbb\storage\Variant\ThumbnailGenerator'
        $fileRepo: '@phpbb\storage\Contract\StoredFileRepositoryInterface'
        $adapterFactory: '@phpbb\storage\Adapter\StorageAdapterFactory'
    tags:
        - { name: kernel.event_listener, event: phpbb.storage.file_stored }
```

---

## Breaking Changes

### 1. `DomainEvent::entityId` Type Widening

**File**: `src/phpbb/common/Event/DomainEvent.php`

```php
// BEFORE:
public readonly int $entityId,

// AFTER:
public readonly string|int $entityId,
```

**Impact assessment**: All existing event subclasses pass `int` to `entityId`. The `int` type satisfies `string|int` — no call site changes required. Existing tests remain valid.

**Risk**: Low. PHP type widening is backward-compatible for callers.

**Audit required**: Search all `extends DomainEvent` implementations and verify constructor call sites never relied on `entityId` being strictly typed as `int` in consuming code.

### 2. `composer.json` Addition

Add `league/flysystem` as a runtime dependency:

```json
"require": {
    "league/flysystem": "^3.0"
}
```

Run `composer require league/flysystem:^3.0` in the Docker container after implementation.

---

## Implementation Guidance

### Task Group Sequence

Implement in the following order to respect dependency chains:

1. **TG-1 — Breaking change + composer**: Widen `DomainEvent::entityId`; add `league/flysystem` to `composer.json`
2. **TG-2 — Enums + Exceptions**: `AssetType`, `FileVisibility`, `VariantType`; all 5 exception classes
3. **TG-3 — Entities + DTOs**: `StoredFile`, `StorageQuota`; all 6 DTO classes
4. **TG-4 — Interfaces**: All 6 `Contract/` interfaces
5. **TG-5 — Domain Events**: All 7 event classes (extend `DomainEvent` with string `entityId`)
6. **TG-6 — Repositories**: `DbalStoredFileRepository` + `DbalStorageQuotaRepository` + `StorageAdapterFactory`
7. **TG-7 — Services**: `QuotaService`, `OrphanService`, `UrlGenerator`
8. **TG-8 — StorageService Facade**: Core orchestration; transactions; event dispatch
9. **TG-9 — Variant**: `VariantGeneratorInterface`, `ThumbnailGenerator`, `ThumbnailListener`
10. **TG-10 — Cron Jobs**: `QuotaReconciliationJob`, `OrphanCleanupJob`
11. **TG-11 — REST Controller**: `FilesController` (`POST /api/v1/files`)
12. **TG-12 — DI + Migrations**: services.yaml additions; SQL migration files

### Testing Approach

- **2–8 focused tests per task group** (do not run the full suite between groups)
- **Unit tests** (`#[Test]`, mocked dependencies, `extends TestCase`): StorageService, QuotaService, OrphanService, ThumbnailListener, FilesController, UrlGenerator
- **Integration tests** (SQLite in-memory, `IntegrationTestCase`, `setUpSchema()`): DbalStoredFileRepository, DbalStorageQuotaRepository
- **Test naming**: `tests/phpbb/storage/{Layer}/{ClassName}Test.php`
- **Coverage targets**:
  - Repositories: `findById`, `save`, `delete`, `findOrphansBefore`, `markClaimed`, `incrementUsage` (quota exceeded + success paths), `decrementUsage`, `reconcile`
  - StorageService: `store()` (happy path, quota exceeded, write failure + rollback), `delete()`, `claim()` (happy path and already-claimed), `getUrl()`, `exists()`
  - ThumbnailListener: non-image skip, GD failure isolation

### Standards Compliance

- [.maister/docs/standards/backend/STANDARDS.md](.maister/docs/standards/backend/STANDARDS.md): `final readonly class` entities, DBAL4 prepared statements, `RepositoryException`, `DomainEventCollection` return type, no `global` in OOP code
- [.maister/docs/standards/backend/REST_API.md](.maister/docs/standards/backend/REST_API.md): `#[Route]` attributes, `_api_user` from `$request->attributes`, HTTP 201 / 400 / 401 / 413, structured error bodies
- [.maister/docs/standards/testing/STANDARDS.md](.maister/docs/standards/testing/STANDARDS.md): `#[Test]` attribute, `IntegrationTestCase` for DB tests, isolated mocks, descriptive test names

---

## Success Criteria

1. `POST /api/v1/files` returns 201 + UUID for a valid multipart upload
2. Re-uploading the same file when quota is full returns 400 with quota error
3. A stored file with `is_orphan=1` older than 24 h is deleted by `OrphanCleanupJob::run()` and quota is updated
4. `claim()` on an already-claimed file throws `OrphanClaimException`
5. A stored image file has a thumbnail child row in `phpbb_stored_files` after `ThumbnailListener` fires
6. `getUrl()` returns a direct URL for `AssetType::Avatar` and a PHP-auth URL for `AssetType::Attachment`
7. `DomainEvent::entityId` accepts both `int` and `string` values; all existing tests pass
8. `composer test` passes after all task groups are complete
9. `composer cs:fix` produces no changes in new files
