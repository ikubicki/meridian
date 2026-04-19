# Research Report: `phpbb\storage` Service Design

| Field | Value |
|-------|-------|
| **Research type** | Technical — infrastructure service design |
| **Date** | 2026-04-19 |
| **Scope** | `phpbb\storage\` namespace design for attachment/avatar/file storage |
| **Sources** | 6 gathering documents, 2 architectural context docs, legacy source code |

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Research Objectives](#2-research-objectives)
3. [Methodology](#3-methodology)
4. [Legacy System Analysis](#4-legacy-system-analysis)
5. [Domain Model — The StoredFile Entity](#5-domain-model--the-storedfile-entity)
6. [Storage Adapter Layer](#6-storage-adapter-layer)
7. [Upload Pipeline](#7-upload-pipeline)
8. [Orphan Management](#8-orphan-management)
9. [Quota System Design](#9-quota-system-design)
10. [Variant / Thumbnail System](#10-variant--thumbnail-system)
11. [File Serving](#11-file-serving)
12. [Plugin Contracts](#12-plugin-contracts)
13. [Security Architecture](#13-security-architecture)
14. [Naming and Path Strategy](#14-naming-and-path-strategy)
15. [Integration Architecture](#15-integration-architecture)
16. [Migration Strategy](#16-migration-strategy)
17. [Key Findings and Recommendations](#17-key-findings-and-recommendations)
18. [Open Design Questions](#18-open-design-questions)
19. [Appendix A: DB Schema Inventory](#appendix-a-db-schema-inventory)
20. [Appendix B: Config Key Inventory](#appendix-b-config-key-inventory)
21. [Appendix C: File Path Inventory](#appendix-c-file-path-inventory)
22. [Appendix D: Source File Inventory](#appendix-d-source-file-inventory)

---

## 1. Executive Summary

The phpBB codebase manages uploaded files through two completely separate subsystems — attachments (forum post/PM files) and avatars (user/group profile images) — that share no code or abstractions despite having nearly identical infrastructure requirements. This research analyzes both systems in depth to inform the design of a unified `phpbb\storage` service.

**Key findings:**

1. **Both subsystems follow the same Upload → Validate → Randomize → Store → Track pattern.** They share the `phpbb\files\upload` and `filespec` pipeline, apply identical security checks (extension whitelist, content sniffing, MIME validation), and produce randomized physical filenames. The divergence is only in naming convention and metadata storage.

2. **The attachment orphan pattern is a powerful decoupling mechanism** that maps naturally to a generic `store() → claim() → cleanup()` lifecycle. Avatars don't use orphans (they're immediately associated), but the pattern is applicable to any asynchronous upload workflow.

3. **Legacy quota enforcement is global-only and eventually consistent.** The `attachment_quota` check uses a counter (`upload_dir_size`) that's updated at claim time, not upload time. No per-user or per-forum quotas exist. Avatars have no quota at all (implicit limit: one file per user × max file size).

4. **File serving requires two distinct strategies:** authenticated proxy for attachments (session + ACL + per-forum auth) and public/direct for avatars (no auth, aggressive caching). These cannot be unified into a single mode.

5. **Legacy migration can be zero-copy** by registering existing directories as storage locations with their naming conventions, without physically relocating files.

The recommended architecture is a `StorageService` facade with pluggable `StorageAdapter` implementations (local filesystem first, extensible to cloud), a `QuotaService` with multi-level scope support, a generic orphan lifecycle, and a variant system for thumbnails and resized images. Consumer services (`AttachmentPlugin`, `AvatarPlugin`) interact exclusively through the storage service API.

---

## 2. Research Objectives

### Primary Research Question

How should `phpbb\storage` be designed as a shared infrastructure service consumed by plugins (AttachmentPlugin for threads, AvatarPlugin for user service)?

### Sub-Questions

1. What is the generic "stored file" entity? What attributes are common across attachments/avatars?
2. How to abstract the filesystem (local now, extensible to cloud/S3 later)?
3. How to implement multi-level quotas (global + per-user + per-forum)?
4. How to generalize the orphan upload → claim → cleanup lifecycle?
5. How to handle thumbnails/variants as a generic concept?
6. When to use proxy serving vs direct URL?
7. What interface should AttachmentPlugin/AvatarPlugin consume?
8. How to generate filenames and organize directories?
9. What security measures must be preserved from legacy code?
10. How to handle legacy files without physically moving them?

### Scope

**Included**: File storage infrastructure, upload/download pipeline, quota enforcement, orphan management, serving strategy, adapter design, plugin contracts, migration.

**Excluded**: Plupload JS integration, BBCode `[attachment=N]` parsing (threads content pipeline concern), avatar gallery management (static assets, not user uploads), extension group management UI (ACP concern).

---

## 3. Methodology

### Research Approach
Technical codebase research — systematic analysis of legacy source code, database schema, configuration schema, and existing architectural designs.

### Data Sources

| Source | Files Analyzed | Focus |
|--------|---------------|-------|
| Attachment lifecycle `gathering/attachment-lifecycle.md` | 11 source files, ~7,500 LOC | Upload/adopt/delete flow, manager API, orphan pattern, events |
| Avatar system `gathering/avatar-system.md` | 7 source files, ~1,800 LOC | Driver architecture, upload/gallery/remote/gravatar, naming |
| File infrastructure `gathering/file-infrastructure.md` | 15 source files, ~5,000 LOC | Upload class, filespec, MIME, thumbnails, filesystem |
| Storage schema `gathering/storage-schema.md` | 3 DB tables, 50+ config keys | Schema, config, constants, paths |
| Download serving `gathering/download-serving.md` | 4 source files, ~1,800 LOC | file.php serving, auth, streaming, caching |
| Quota enforcement `gathering/quota-enforcement.md` | 8 source files, ~1,200 LOC | Global/per-file quotas, avatar limits, gaps |
| Threads service HLD | 1 design document | Consumer architecture (AttachmentPlugin pattern) |
| User service spec | 1 design document | Consumer architecture (AvatarPlugin pattern) |

### Analysis Framework
Technical Research Framework — Component Analysis + Flow Analysis + Pattern Analysis.

---

## 4. Legacy System Analysis

### 4.1 Current Architecture Overview

The legacy file storage system consists of two completely separate subsystems:

**Attachment subsystem** (`src/phpbb/forums/attachment/`):
- `manager.php` (107 LOC) — Facade delegating to upload/delete/resync
- `upload.php` (339 LOC) — Upload orchestration with quota/thumbnail
- `delete.php` (480 LOC) — Deletion with reference counting and filesystem cleanup
- `resync.php` (150 LOC) — Denormalized flag repair
- DB table: `phpbb_attachments` (15 columns, 5 indexes)
- Storage: `files/` flat directory

**Avatar subsystem** (`src/phpbb/forums/avatar/`):
- `manager.php` (~400 LOC) — Driver registry with enable/disable
- `driver/upload.php` (~340 LOC) — Upload driver
- `driver/local.php` (~200 LOC) — Gallery driver
- `driver/remote.php` (~244 LOC) — Remote URL driver
- `driver/gravatar.php` (~200 LOC) — Gravatar integration
- DB: `user_avatar*` columns on `phpbb_users`, `group_avatar*` on `phpbb_groups`
- Storage: `images/avatars/upload/` flat directory

**Shared infrastructure** (`src/phpbb/forums/files/`):
- `upload.php` (~400 LOC) — Generic upload engine with validation
- `filespec.php` (~530 LOC) — File object: naming, moving, validation
- `factory.php` (~60 LOC) — DI-based service factory
- `types/form.php`, `types/remote.php`, `types/local.php` — Upload type handlers

**Serving** (`web/download/file.php` + `functions_download.php`):
- Single entry point handling both avatars and attachments
- Avatars: lightweight bootstrap, no session, public cache
- Attachments: full bootstrap, session + ACL, private cache

### 4.2 Code Metrics

| Component | Files | Lines of Code | Dependencies |
|-----------|-------|---------------|-------------|
| Attachment manager/upload/delete/resync | 4 | ~1,076 | 10 DI deps on upload alone |
| Avatar manager + 4 drivers | 6 | ~1,480 | config, imagesize, filesystem, dispatcher, files_factory |
| Files upload + filespec + types | 6 | ~1,900 | filesystem, factory, language, php_ini, request, plupload |
| Filesystem abstraction | 2 | ~600 | Symfony Filesystem |
| MIME detection | 4 | ~800 | mime_content_type, extension map |
| Download/serving | 3 | ~1,400 | session, auth, config, DB |
| Thumbnail generation | 1 (partial) | ~300 | GD extension |
| **Total** | **~26** | **~7,556** | |

### 4.3 Architectural Gaps

| Gap | Impact | Severity |
|-----|--------|----------|
| No per-user quota | Any user can fill global quota | **High** |
| No per-forum quota | No way to limit storage per-forum | **Medium** |
| No orphan TTL cleanup | Abandoned uploads persist forever (DB + disk) | **High** |
| Global counter eventually consistent | Quota bypass via concurrent uploads | **Medium** |
| Flat directory (no sharding) | Filesystem performance at scale | **Medium** |
| No avatar file size tracking | Can't audit avatar storage usage | **Low** |
| No streaming abstraction | Tight coupling to local filesystem | **Medium** |
| Split download count tracking | Images vs files counted in different code paths | **Low** |
| No file deduplication | Identical uploads create separate files | **Low** |
| Thumbnails: eager only, single size | No responsive images, no on-demand generation | **Low** |

---

## 5. Domain Model — The StoredFile Entity

### 5.1 Attribute Analysis

Cross-referencing attachment and avatar metadata reveals a common core:

| Attribute | Attachments (DB) | Avatars (DB) | StoredFile (proposed) |
|-----------|-----------------|-------------|----------------------|
| Unique ID | `attach_id` (autoincrement) | Implicit (user_id + type) | `id` (UUID or autoincrement) |
| Physical filename | `physical_filename` | `{salt}_{user_id}.{ext}` | `physical_name` |
| Original filename | `real_filename` | Not stored | `original_name` (nullable) |
| MIME type | `mimetype` | Detected at serve-time | `mime_type` |
| File size (bytes) | `filesize` | Not stored | `size` |
| Extension | `extension` | Extracted from avatar value | `extension` |
| Width (pixels) | Via `getimagesize()` at upload | `user_avatar_width` | `width` (nullable) |
| Height (pixels) | Via `getimagesize()` at upload | `user_avatar_height` | `height` (nullable) |
| Storage adapter | Implicit (local, config path) | Implicit (local, config path) | `adapter` (string key) |
| Storage path | `config['upload_path']` | `config['avatar_path']` | `storage_path` |
| Owner | `poster_id` | `user_id` (implicit) | `owner_id` |
| Owner type | `in_message` flag (post/PM) | Implicit (user/group) | `owner_type` (enum) |
| Claimed? | `is_orphan` (0/1) | Always claimed | `claimed_at` (nullable timestamp) |
| Upload time | `filetime` | Timestamp in avatar column name | `created_at` |
| Metadata | `download_count`, `attach_comment`, `thumbnail` | None | `metadata` (JSON, nullable) |

### 5.2 Proposed Entity

```php
final class StoredFile
{
    public function __construct(
        public readonly string $id,           // UUID
        public readonly string $physicalName, // On-disk filename
        public readonly ?string $originalName, // User's original filename
        public readonly string $mimeType,
        public readonly int $size,            // Bytes
        public readonly string $extension,
        public readonly ?int $width,          // Pixels, null for non-images
        public readonly ?int $height,
        public readonly string $adapter,      // e.g., 'local', 's3'
        public readonly string $storagePath,  // Directory relative to adapter root
        public readonly int $ownerId,         // Who uploaded
        public readonly ?string $ownerType,   // null = orphan, 'post', 'user_avatar', etc.
        public readonly ?string $ownerRef,    // Owner-specific reference (post_id, etc.)
        public readonly int $createdAt,       // Unix timestamp
        public readonly ?int $claimedAt,      // null = unclaimed orphan
    ) {}

    public function isOrphan(): bool
    {
        return $this->claimedAt === null;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }
}
```

### 5.3 Proposed DB Schema: `phpbb_stored_files`

```sql
CREATE TABLE phpbb_stored_files (
    id              CHAR(36) NOT NULL,          -- UUID
    physical_name   VARCHAR(255) NOT NULL,       -- On-disk filename
    original_name   VARCHAR(255) DEFAULT NULL,   -- User's original filename
    mime_type       VARCHAR(100) NOT NULL,
    size            BIGINT UNSIGNED NOT NULL,     -- Bytes
    extension       VARCHAR(20) NOT NULL,
    width           SMALLINT UNSIGNED DEFAULT NULL,
    height          SMALLINT UNSIGNED DEFAULT NULL,
    adapter         VARCHAR(50) NOT NULL DEFAULT 'local',
    storage_path    VARCHAR(255) NOT NULL,        -- Directory path
    owner_id        INT UNSIGNED NOT NULL,        -- Uploader user ID
    owner_type      VARCHAR(50) DEFAULT NULL,     -- NULL = orphan
    owner_ref       VARCHAR(100) DEFAULT NULL,    -- Consumer-specific reference
    created_at      INT UNSIGNED NOT NULL,
    claimed_at      INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_owner (owner_id, owner_type),
    KEY idx_orphan (claimed_at, created_at),      -- For cleanup: WHERE claimed_at IS NULL AND created_at < ?
    KEY idx_physical (physical_name),
    KEY idx_owner_ref (owner_type, owner_ref)      -- Find files by consumer reference
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.4 Proposed DB Schema: `phpbb_file_variants`

```sql
CREATE TABLE phpbb_file_variants (
    id              CHAR(36) NOT NULL,
    file_id         CHAR(36) NOT NULL,            -- FK to phpbb_stored_files
    variant_type    VARCHAR(50) NOT NULL,          -- 'thumbnail', 'medium', 'small'
    physical_name   VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(100) NOT NULL,
    size            BIGINT UNSIGNED NOT NULL,
    width           SMALLINT UNSIGNED DEFAULT NULL,
    height          SMALLINT UNSIGNED DEFAULT NULL,
    created_at      INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_file_variant (file_id, variant_type),
    CONSTRAINT fk_variant_file FOREIGN KEY (file_id) REFERENCES phpbb_stored_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 6. Storage Adapter Layer

### 6.1 Current State

Legacy uses the `phpbb\filesystem\filesystem` class (wrapping Symfony Filesystem) with hardcoded paths:
- `$phpbb_root_path . $config['upload_path']` for attachments
- `$phpbb_root_path . $config['avatar_path']` for avatars

No abstraction for different storage backends. All operations are local filesystem calls.

### 6.2 Adapter Interface

```php
interface StorageAdapterInterface
{
    /** Write a file from a stream or path */
    public function write(string $path, $contents): void;

    /** Read a file, returns stream resource */
    public function read(string $path); // : resource

    /** Delete a file */
    public function delete(string $path): void;

    /** Check if file exists */
    public function exists(string $path): bool;

    /** Get file size in bytes */
    public function size(string $path): int;

    /** Get a locally accessible path (for GD/imagesize operations) */
    public function getLocalPath(string $path): string;

    /** Get a direct URL if the adapter supports it (null otherwise) */
    public function getDirectUrl(string $path): ?string;

    /** Get the path suitable for X-Accel-Redirect (null if not applicable) */
    public function getAccelRedirectPath(string $path): ?string;
}
```

### 6.3 Local Adapter

```php
final class LocalAdapter implements StorageAdapterInterface
{
    public function __construct(
        private readonly string $basePath,    // Absolute path to storage root
        private readonly string $baseUrl,     // URL prefix for direct access
        private readonly ?string $accelPrefix, // X-Accel-Redirect prefix (e.g., '/protected-files/')
    ) {}
}
```

**Legacy compatibility**: Two `LocalAdapter` instances:
1. `adapter.attachments` → basePath: `{root}/files/`, accelPrefix configured
2. `adapter.avatars` → basePath: `{root}/images/avatars/upload/`, baseUrl for direct access

### 6.4 Extensibility

Future adapters (`S3Adapter`, `GcsAdapter`) implement the same interface. `getLocalPath()` returns a temp file for adapters that don't support local access (needed for image dimension detection and GD operations).

---

## 7. Upload Pipeline

### 7.1 Current Upload Flow

Both attachment and avatar uploads follow this validated sequence:

```
1. Form/Remote → PHP temp file
2. filespec::set_upload_ary() — parse filename, extract extension, detect MIME
3. upload::common_checks() — filesize, extension whitelist, filename chars, content sniffing
4. filespec::clean_filename() — generate randomized physical name
5. filespec::move_file() — move to destination, chmod, MIME re-detect, image validate
6. Consumer-specific: DB insert (attachment) or user row update (avatar)
```

### 7.2 Proposed Upload Pipeline

```php
interface UploadPipelineInterface
{
    /**
     * Process an uploaded file through validation and storage.
     *
     * @param UploadedFile $file       Raw upload (from form, remote, or local)
     * @param UploadOptions $options   Consumer-provided constraints
     * @return StoredFile              Persisted file record (unclaimed)
     * @throws UploadValidationException
     * @throws QuotaExceededException
     */
    public function process(UploadedFile $file, UploadOptions $options): StoredFile;
}
```

```php
final class UploadOptions
{
    public function __construct(
        public readonly array $allowedExtensions,     // ['jpg', 'png', 'gif']
        public readonly int $maxFileSize = 0,         // 0 = use global
        public readonly int $maxWidth = 0,            // Image max width, 0 = unlimited
        public readonly int $maxHeight = 0,           // Image max height, 0 = unlimited
        public readonly int $minWidth = 0,
        public readonly int $minHeight = 0,
        public readonly string $storagePath = '',      // Override storage directory
        public readonly int $ownerId = 0,             // Uploader user ID
        public readonly bool $generateVariants = false, // Create thumbnails?
        public readonly array $variantTypes = [],      // ['thumbnail']
    ) {}
}
```

### 7.3 Validation Chain

Preserved from legacy (all confirmed as security-critical):

| Step | Check | Source |
|------|-------|--------|
| 1 | PHP upload error codes (`UPLOAD_ERR_*`) | `upload.php:assign_internal_error()` |
| 2 | Non-zero file size | `upload.php:common_checks()` |
| 3 | Extension in allowed whitelist | `upload.php:valid_extension()` |
| 4 | Filename character validation | `filespec.php:clean_filename('real')` |
| 5 | Content sniffing — first 256 bytes for HTML tags | `filespec.php:check_content()` |
| 6 | MIME type re-detection after move | `filespec.php:move_file()` |
| 7 | Image type vs extension cross-check | `filespec.php:move_file()` |
| 8 | Image dimension validation | `upload.php:valid_dimensions()` |
| 9 | Per-file size limit (per-extension or global) | `attachment/upload.php` |
| 10 | Quota check | `attachment/upload.php:check_attach_quota()` |

### 7.4 Reuse Strategy

The existing `phpbb\files\upload` and `phpbb\files\filespec` classes contain well-tested validation logic. Rather than reimplementing, the storage service should **wrap** them:

```php
final class UploadPipeline implements UploadPipelineInterface
{
    public function __construct(
        private readonly \phpbb\files\upload $filesUpload,  // Existing class
        private readonly StorageAdapterInterface $adapter,
        private readonly QuotaServiceInterface $quotaService,
        private readonly FileRepository $fileRepository,
        private readonly NamingStrategy $namingStrategy,
    ) {}
}
```

---

## 8. Orphan Management

### 8.1 Current Orphan Pattern (Attachments)

```
Upload (during post compose) → INSERT with is_orphan=1
    ↓ User still editing post...
Form submit → submit_post() → UPDATE SET is_orphan=0, post_msg_id=X
    ↓ or
User abandons post → Orphan row persists forever (NO cleanup)
```

**Key findings:**
- Every attachment starts as `is_orphan=1` (hardcoded in `message_parser.php:1617`)
- Orphan access: session ID validation, poster_id ownership check
- Adoption: verify `is_orphan=1 AND poster_id=user_id`, then update
- Counter increment happens at adoption time, not upload time
- **No cron job cleans orphan DB rows** — only plupload temp files are cleaned (separate mechanism)

### 8.2 Current State (Avatars)

Avatars have **no orphan pattern**. `process_form()` in the avatar upload driver immediately:
1. Uploads the file
2. Returns the new avatar data
3. Caller updates the user row

If the user abandons the form after upload, the file is overwritten next time (same physical filename per user).

### 8.3 Proposed Generic Orphan Lifecycle

```
store()
    → Creates StoredFile with claimed_at=NULL
    → Reserves quota for owner_id
    → Returns file ID to caller

claim(fileId, ownerType, ownerRef)
    → Sets claimed_at=NOW(), owner_type, owner_ref
    → Validates ownership (owner_id matches)
    → Returns updated StoredFile

OrphanCleanupJob (cron, every hour)
    → SELECT WHERE claimed_at IS NULL AND created_at < (NOW - TTL)
    → For each: delete physical file + variants, release quota, DELETE row
```

### 8.4 TTL Configuration

| Config Key | Proposed Default | Rationale |
|-----------|-----------------|-----------|
| `storage_orphan_ttl` | `86400` (24 hours) | Matches plupload temp cleanup interval |
| `storage_orphan_gc_interval` | `3600` (1 hour) | Reasonable cron frequency |

### 8.5 Orphan Access Control

During the orphan phase, only the uploader should be able to:
- Preview the file (e.g., in post composition)
- Claim the file (on form submit)
- Delete the file (cancel upload)

The storage service validates `owner_id` on all orphan operations. Consumer plugins pass the user ID from their auth context.

---

## 9. Quota System Design

### 9.1 Legacy State

| Quota Type | Exists? | Implementation |
|-----------|---------|----------------|
| Global attachment quota | Yes | `attachment_quota` config + `upload_dir_size` counter |
| Per-user quota | **No** | — |
| Per-forum quota | **No** | — |
| Avatar quota | **No** | Implicit: 1 file × `avatar_filesize` per user |
| Per-file size limit | Yes | `max_filesize`, `max_filesize_pm`, per-extension-group |

### 9.2 Global Quota Issues

1. **Counter staleness**: `upload_dir_size` only updates on orphan adoption and deletion, not on initial upload. Orphan files aren't counted.
2. **Race condition**: Two concurrent uploads can both pass the quota check before either updates the counter.
3. **Reconciliation**: Only admin resync recalculates from actual DB sums.

### 9.3 Proposed Multi-Level Quota Schema

```sql
CREATE TABLE phpbb_storage_quotas (
    scope_type      VARCHAR(50) NOT NULL,     -- 'global', 'user', 'forum'
    scope_id        INT UNSIGNED NOT NULL,    -- 0 for global, user_id, forum_id
    limit_bytes     BIGINT UNSIGNED NOT NULL, -- 0 = unlimited
    used_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_count      INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      INT UNSIGNED NOT NULL,
    PRIMARY KEY (scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Sample rows:**
```
('global', 0, 52428800, 1234567, 42, 1713484800)      -- 50MB global
('user', 42, 10485760, 234567, 5, 1713484800)          -- 10MB for user 42
('forum', 7, 104857600, 0, 0, 1713484800)              -- 100MB for forum 7
```

### 9.4 Enforcement Strategy

```php
interface QuotaServiceInterface
{
    /**
     * Check and reserve quota for an upload.
     * Throws QuotaExceededException if any applicable quota would be exceeded.
     *
     * Checks all applicable scopes:
     * 1. Global quota
     * 2. Per-user quota (if configured)
     * 3. Per-scope quota (forum, etc. — consumer provides scope context)
     */
    public function reserve(int $ownerId, int $sizeBytes, array $scopes = []): QuotaReservation;

    /** Confirm a reservation (on claim). No-op if already confirmed. */
    public function confirm(QuotaReservation $reservation): void;

    /** Release a reservation (on orphan cleanup or delete). */
    public function release(int $ownerId, int $sizeBytes, array $scopes = []): void;

    /** Full recalculation from actual files (admin action). */
    public function reconcile(?string $scopeType = null, ?int $scopeId = null): void;
}
```

### 9.5 Atomic Reservation

To prevent race conditions, quota reservation uses `UPDATE ... SET used_bytes = used_bytes + ? WHERE used_bytes + ? <= limit_bytes`:

```sql
UPDATE phpbb_storage_quotas
SET used_bytes = used_bytes + :size, 
    file_count = file_count + 1,
    updated_at = :now
WHERE scope_type = :type 
  AND scope_id = :id 
  AND (limit_bytes = 0 OR used_bytes + :size <= limit_bytes);
-- Check affected_rows = 1 → success
-- affected_rows = 0 → quota exceeded
```

This is a single atomic operation — no TOCTOU race condition.

### 9.6 Per-File Size Limits

Per-file limits remain the responsibility of the upload pipeline (not the quota service). The pipeline checks:
1. Global `max_filesize` / `max_filesize_pm`
2. Per-extension-group `max_filesize` (from `phpbb_extension_groups`)
3. Avatar-specific `avatar_filesize`

These are per-upload checks, not cumulative quotas.

### 9.7 Default Quota Configuration

| Scope | Default Limit | Notes |
|-------|--------------|-------|
| Global | 50 MB (migrate from `attachment_quota`) | Includes all file types |
| Per-user | Unlimited (0) | Admin-configurable per-group default |
| Per-forum | Unlimited (0) | Admin-configurable per-forum |

---

## 10. Variant / Thumbnail System

### 10.1 Legacy Thumbnail Implementation

- Generated eagerly at upload time (if `img_create_thumbnail` enabled)
- Stored as `thumb_{physical_filename}` in the same directory
- Single size only: max width from `img_max_thumb_width` (default 400px), proportional height
- GD-based: `imagecreatetruecolor()` + `imagecopyresampled()` for GD v2
- JPEG quality: hardcoded 90
- Minimum filesize threshold: `img_min_thumb_filesize` (default 12KB — skip tiny images)
- Tracked via `thumbnail` boolean column in `phpbb_attachments`
- Served through same `file.php` endpoint with `?t=1` parameter

### 10.2 Proposed Variant System

**Variant** = a derived version of a primary StoredFile (thumbnail, medium, small, webp conversion, etc.)

```php
interface VariantGeneratorInterface
{
    /** Get the variant type identifier */
    public function getType(): string;  // e.g., 'thumbnail'
    
    /** Check if this generator supports the given file */
    public function supports(StoredFile $file): bool;
    
    /** Generate the variant, return variant metadata */
    public function generate(StoredFile $file, string $sourcePath): VariantResult;
}
```

```php
final class ThumbnailGenerator implements VariantGeneratorInterface
{
    public function __construct(
        private readonly int $maxWidth = 400,
        private readonly int $minSourceSize = 12000,  // Skip small images
        private readonly int $jpegQuality = 90,
    ) {}

    public function getType(): string { return 'thumbnail'; }

    public function supports(StoredFile $file): bool
    {
        return $file->isImage() 
            && $file->size > $this->minSourceSize
            && in_array($file->extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }
}
```

### 10.3 Generation Strategy

**Recommended: Hybrid** (eager for thumbnails, lazy for other variants)
- Thumbnails generated at upload time (same as legacy) — they're almost always needed
- Other variant sizes (medium, small) generated on first request and cached
- All variants deleted when primary file is deleted (CASCADE in DB)

### 10.4 Variant Naming Convention

```
Primary:  ab/{hash}.jpg
Thumb:    ab/{hash}_thumb.jpg
Medium:   ab/{hash}_medium.jpg
```

Same directory as primary, variant type appended before extension.

### 10.5 Variant Serving

Variants served through the same endpoint as their primary file:
- `file.php?id={file_id}&variant=thumbnail`
- Same auth checks as primary (inherits access from parent file)
- If variant doesn't exist → 404 (or generate on-demand if lazy strategy)

---

## 11. File Serving

### 11.1 Legacy Serving Architecture

Single entry point: `web/download/file.php` with two branches:

| Aspect | Avatar Branch | Attachment Branch |
|--------|--------------|-------------------|
| Bootstrap | Lightweight (no session) | Full (`common.php`) |
| Auth | None (public) | Session + ACL + forum auth |
| `Cache-Control` | `public` | `private` |
| `Expires` | +1 year | +1 year (after 304) |
| Range requests | No | Yes |
| X-Accel-Redirect | No | Yes (if configured) |
| Download tracking | No | Yes (non-images only) |
| MIME handling | Real MIME from `getimagesize()` | Forced `application/octet-stream` for non-images |
| Content-Disposition | `inline` | `inline` (images) / `attachment` (others) |

### 11.2 Proposed Serving Strategy

Two modes encapsulated in the storage service:

**Public URL** (avatars, gallery):
```php
// Returns direct URL or lightweight proxy URL
$url = $storage->getPublicUrl($file);
// → /images/avatars/upload/{file}.jpg (direct, if accessible)
// → /files/public/{file_id}.jpg (lightweight proxy, no auth)
```

**Authenticated URL** (attachments):
```php
// Always returns proxy URL through auth middleware
$url = $storage->getAuthenticatedUrl($file);
// → /download/file/{file_id} (requires session + ACL)
```

### 11.3 File Server Controller

```php
final class FileServerController
{
    public function servePublic(string $fileId): Response
    {
        $file = $this->storage->getFile($fileId);
        // No auth check — public endpoint
        return $this->streamFile($file, public: true);
    }

    public function serveAuthenticated(string $fileId): Response
    {
        $file = $this->storage->getFile($fileId);
        // Auth check delegated to middleware (per plugin's rules)
        return $this->streamFile($file, public: false);
    }

    private function streamFile(StoredFile $file, bool $public): Response
    {
        // 1. 304 Not Modified check (If-Modified-Since)
        // 2. Set headers (Content-Type, Content-Disposition, Cache-Control, X-Content-Type-Options: nosniff)
        // 3. X-Accel-Redirect if available, else PHP streaming
        // 4. Range request support for non-public files
    }
}
```

### 11.4 Security Headers (Preserved from Legacy)

| Header | Value | Purpose |
|--------|-------|---------|
| `X-Content-Type-Options` | `nosniff` | Prevent MIME sniffing |
| `Content-Type` | Force `application/octet-stream` for non-images | Prevent browser execution |
| `Content-Disposition` | `attachment` for non-images | Force download |
| `X-Download-Options` | `noopen` | Prevent IE "Open" |
| `Cache-Control` | `private` (auth) / `public` (avatars) | Scope caching correctly |

---

## 12. Plugin Contracts

### 12.1 How Consumers Use Storage

The storage service is infrastructure — it doesn't know about posts, topics, or user profiles. Consumer plugins translate between their domain and storage operations.

### 12.2 AttachmentPlugin Contract (for threads)

```php
final class AttachmentPlugin implements RequestDecoratorInterface, ResponseDecoratorInterface
{
    public function __construct(
        private readonly StorageServiceInterface $storage,
    ) {}

    // On PostCreatedEvent: claim orphan files
    public function onPostCreated(PostCreatedEvent $event): void
    {
        foreach ($event->attachmentIds as $fileId) {
            $this->storage->claim($fileId, 'post', (string) $event->postId);
        }
    }

    // On PostDeletedEvent: delete files
    public function onPostDeleted(PostHardDeletedEvent $event): void
    {
        $files = $this->storage->findByOwner('post', (string) $event->postId);
        foreach ($files as $file) {
            $this->storage->delete($file->id);
        }
    }

    // Request decorator: add upload capability to posting forms
    // Response decorator: attach file data to post responses
}
```

### 12.3 AvatarPlugin Contract (for user service)

```php
final class AvatarPlugin
{
    public function __construct(
        private readonly StorageServiceInterface $storage,
    ) {}

    public function onProfileUpdated(ProfileUpdatedEvent $event): void
    {
        if ($event->newAvatarFileId) {
            // Delete old avatar
            $oldFiles = $this->storage->findByOwner('user_avatar', (string) $event->userId);
            foreach ($oldFiles as $old) {
                $this->storage->delete($old->id);
            }
            // Claim new avatar
            $this->storage->claim($event->newAvatarFileId, 'user_avatar', (string) $event->userId);
        }
    }

    public function getAvatarUrl(int $userId): ?string
    {
        $files = $this->storage->findByOwner('user_avatar', (string) $userId);
        return $files ? $this->storage->getPublicUrl($files[0]) : null;
    }
}
```

### 12.4 Storage Service Interface

```php
interface StorageServiceInterface
{
    /** Upload and store a new file (unclaimed orphan) */
    public function store(UploadedFile $file, UploadOptions $options): StoredFile;

    /** Claim an orphan file, associating it with an owner */
    public function claim(string $fileId, string $ownerType, string $ownerRef): StoredFile;

    /** Delete a file and all its variants */
    public function delete(string $fileId): void;

    /** Get file metadata by ID */
    public function getFile(string $fileId): StoredFile;

    /** Find files by owner */
    public function findByOwner(string $ownerType, string $ownerRef): array;

    /** Get a public (cacheable, no-auth) URL */
    public function getPublicUrl(StoredFile $file): string;

    /** Get an authenticated (session-required) URL */
    public function getAuthenticatedUrl(StoredFile $file): string;

    /** Get variant of a file */
    public function getVariant(string $fileId, string $variantType): ?StoredFile;
}
```

---

## 13. Security Architecture

### 13.1 Upload Security (Preserved from Legacy)

All security measures from the legacy `filespec` and `upload` classes are preserved:

| Measure | Implementation | Why Critical |
|---------|---------------|--------------|
| Extension whitelist | Consumer provides allowed list; storage validates | Prevents upload of executable files |
| Content sniffing (HTML tags in first 256 bytes) | `filespec::check_content()` | Prevents IE MIME sniffing XSS |
| MIME type re-detection after move | `filespec::move_file()` | Catches type spoofing |
| Image type vs extension cross-check | `filespec::move_file()` | Prevents `.php` renamed to `.jpg` |
| Double extension stripping | `filespec::clean_filename('real')` | Prevents `file.php.jpg` attacks |
| Randomized physical filenames | `clean_filename('unique')` | No path traversal, no user-controlled names |
| Disallowed content strings (configurable) | `mime_triggers` config | Blocks files with HTML/script content |

### 13.2 Serving Security

| Measure | Implementation |
|---------|---------------|
| Non-image MIME forced to `application/octet-stream` | `send_file_to_browser()` |
| `X-Content-Type-Options: nosniff` | All served files |
| `X-Download-Options: noopen` | Non-image files |
| `Content-Disposition: attachment` for non-images | Force download, prevent execution |
| Auth middleware for private files | Consumer plugin provides auth check |
| Avatar path traversal prevention | Strip `../`, `./` from config paths |

### 13.3 New Security Considerations

| Risk | Mitigation |
|------|-----------|
| UUID enumeration for file IDs | UUIDs are 128-bit random — infeasible to enumerate |
| Orphan file access by non-owner | `owner_id` check on all orphan operations |
| Quota bypass via concurrent uploads | Atomic SQL `UPDATE WHERE used_bytes + size <= limit` |
| Directory traversal in adapter | Adapter validates paths contain no `..` components |
| File overwrite via naming collision | UUID-based naming; adapter rejects writes to existing paths |

---

## 14. Naming and Path Strategy

### 14.1 Legacy Naming Conventions

| System | Physical Name Format | Example |
|--------|---------------------|---------|
| Attachments | `{user_id}_{md5(unique_id())}` (no extension) | `42_a1b2c3d4e5f6...` |
| Thumbnails | `thumb_{physical_filename}` | `thumb_42_a1b2c3d4...` |
| Avatars | `{avatar_salt}_{user_id}.{ext}` | `8fe4..._{42}.jpg` |

### 14.2 Proposed Naming Strategy

**New files**: `{first2chars}/{uuid}.{ext}`

Example: `ab/ab3f7e2a-1234-5678-9abc-def012345678.jpg`

Benefits:
- 2-char directory prefix = 256 subdirectories, sharding filesystem entries
- UUID eliminates collision without external coordination
- Extension preserved for debugging, CDN content-type hints, X-Accel
- No user ID or salt in filename (security improvement)

**Variant naming**: `{first2chars}/{uuid}_{variant}.{ext}`

Example: `ab/ab3f7e2a-1234-5678-9abc-def012345678_thumb.jpg`

### 14.3 Directory Structure

```
storage/
├── files/                    ← New standard storage root
│   ├── ab/
│   │   ├── ab3f7e2a-....jpg
│   │   └── ab3f7e2a-...._thumb.jpg
│   ├── cd/
│   └── ...
│
├── legacy/                   ← Symlinks or actual legacy dirs
│   ├── files/               ← Existing attachment files
│   └── images/avatars/upload/ ← Existing avatar files
```

### 14.4 Legacy Path Compatibility

The `LocalAdapter` for legacy files resolves paths differently:
- For new files: `{storage_root}/{first2chars}/{uuid}.{ext}`
- For legacy attachments: `{phpbb_root}/files/{physical_filename}`
- For legacy avatars: `{phpbb_root}/images/avatars/upload/{salt}_{user_id}.{ext}`

The `StoredFile.adapter` and `StoredFile.storagePath` fields determine which resolution strategy to use.

---

## 15. Integration Architecture

### 15.1 With Threads Service

```
CreateTopicRequest
    ├── request contains attachment_file_ids[]
    ├── AttachmentPlugin (RequestDecorator): validates file IDs exist and are owned by user
    │
    ▼
ThreadsService::createTopic()
    ├── creates topic + first post
    ├── dispatches PostCreatedEvent{postId, topicId, userId}
    │
    ▼
AttachmentPlugin (EventListener)
    ├── $storage->claim(fileId, 'post', postId)  — for each file ID
    ├── Updates topic_attachment flag
    │
    ▼
TopicViewResponse
    ├── AttachmentPlugin (ResponseDecorator): fetches files for post IDs
    ├── Injects file URLs into response DTO
```

### 15.2 With User Service

```
UpdateProfileDTO
    ├── contains new avatar_file_id (if avatar changed)
    │
    ▼
ProfileService::updateProfile()
    ├── dispatches ProfileUpdatedEvent{userId, newAvatarFileId}
    │
    ▼
AvatarPlugin (EventListener)
    ├── Delete old avatar: $storage->findByOwner('user_avatar', userId) → delete
    ├── Claim new: $storage->claim(newAvatarFileId, 'user_avatar', userId)
    │
    ▼
User entity/API
    ├── AvatarPlugin provides getAvatarUrl(userId) → $storage->getPublicUrl(file)
```

### 15.3 Event Flow Summary

| Event | Source | Storage Action | Consumer |
|-------|--------|---------------|----------|
| `PostCreatedEvent` | threads | `storage.claim()` per file | AttachmentPlugin |
| `PostHardDeletedEvent` | threads | `storage.delete()` per file | AttachmentPlugin |
| `TopicHardDeletedEvent` | threads | `storage.delete()` for all topic files | AttachmentPlugin |
| `ProfileUpdatedEvent` | user | Delete old + `storage.claim()` | AvatarPlugin |
| `UserDeletedEvent` | user | `storage.delete()` for all user files | AvatarPlugin |
| `OrphanCleanupEvent` | storage cron | `storage.delete()` for expired orphans | Internal |

---

## 16. Migration Strategy

### 16.1 Principles

1. **Zero-copy**: No physical file relocation required
2. **Incremental**: Migrate metadata gradually, files stay in place
3. **Backward compatible**: Legacy code continues to work during transition
4. **Reversible**: Migration can be rolled back by removing new metadata

### 16.2 Phase 1: Schema Addition

Add `phpbb_stored_files` and `phpbb_file_variants` tables alongside existing tables.

### 16.3 Phase 2: Metadata Migration (Background Job)

```
For each row in phpbb_attachments:
    INSERT INTO phpbb_stored_files (
        id = generate_uuid(),
        physical_name = physical_filename,
        original_name = real_filename,
        mime_type = mimetype,
        size = filesize,
        extension = extension,
        adapter = 'legacy_attachments',
        storage_path = config['upload_path'],
        owner_id = poster_id,
        owner_type = CASE WHEN is_orphan THEN NULL WHEN in_message THEN 'pm' ELSE 'post' END,
        owner_ref = CASE WHEN is_orphan THEN NULL ELSE post_msg_id END,
        created_at = filetime,
        claimed_at = CASE WHEN is_orphan THEN NULL ELSE filetime END,
    );
    -- Add legacy_id mapping for cross-reference during transition

For each row in phpbb_users WHERE user_avatar_type = 'avatar.driver.upload':
    INSERT INTO phpbb_stored_files (
        id = generate_uuid(),
        physical_name = {avatar_salt}_{user_id}.{ext},
        adapter = 'legacy_avatars',
        storage_path = config['avatar_path'],
        owner_id = user_id,
        owner_type = 'user_avatar',
        owner_ref = user_id,
        ...
    );
```

### 16.4 Phase 3: New Code Uses Storage Service

All new code writes to storage service. Legacy code continues reading from old paths. Storage service's `LocalAdapter` resolves both legacy and new paths.

### 16.5 Phase 4: Optional Physical Migration

Admin-triggered job that copies files from legacy dirs to new `storage/files/{sharded}` structure and updates `adapter` + `storage_path` on the stored_files row. Can run incrementally in batches.

---

## 17. Key Findings and Recommendations

### Finding 1: Unified Storage Model is Feasible

**Confidence**: High

The attachment and avatar systems share 80% of their infrastructure despite zero code reuse. A unified `StoredFile` entity with adapter-based storage covers both use cases plus future ones.

**Recommendation**: Implement `phpbb\storage\` with `StoredFile` entity, `StorageService` facade, `LocalAdapter`, and `QuotaService` as minimum viable scope.

### Finding 2: Orphan Pattern Should Be Generalized

**Confidence**: High

The `store() → claim() → cleanup()` lifecycle decouples storage from business logic. Legacy lacks orphan cleanup — the new service must add TTL-based garbage collection.

**Recommendation**: All uploads create unclaimed files. Consumer plugins claim on entity save. Cron cleans up after 24h TTL.

### Finding 3: Quota System Needs Rebuild

**Confidence**: High

Legacy global-only, eventually-consistent quota is insufficient. Multi-level (global + per-user + per-forum) with atomic reservation SQL prevents races.

**Recommendation**: New `phpbb_storage_quotas` table with atomic `UPDATE WHERE used + size <= limit` pattern. Periodic reconciliation job.

### Finding 4: Upload Pipeline Should Wrap, Not Replace

**Confidence**: High

The `filespec` class's security validation chain is well-tested and comprehensive. Reimplementing risks introducing security regressions.

**Recommendation**: Storage service wraps `phpbb\files\upload` for validation, adds its own pipeline stages (quota, naming, adapter write).

### Finding 5: Two Serving Modes Are Architecturally Required

**Confidence**: High

Avatar (public, cacheable) and attachment (auth-gated, private) serving have fundamentally different requirements.

**Recommendation**: `getPublicUrl()` and `getAuthenticatedUrl()` methods on the storage service. Single file server controller with two endpoints.

### Finding 6: Legacy Migration is Zero-Copy Feasible

**Confidence**: High

Register existing directories as "legacy adapters" with their naming conventions. New files use new naming. No files move.

**Recommendation**: `legacy_attachments` and `legacy_avatars` adapter types that resolve paths using legacy conventions. Optional background migration to new sharded directories.

---

## 18. Open Design Questions

### Decision Required

| # | Question | Options | Recommendation |
|---|----------|---------|----------------|
| 1 | Single `stored_files` table vs keep existing tables + add reference? | (A) Single table replaces `phpbb_attachments`, (B) New table with FK to existing | **(B)** — less migration risk, allows gradual transition |
| 2 | UUID vs autoincrement for file IDs? | (A) UUID, (B) Autoincrement | **(A)** — no central sequence needed, safe for distributed, better for URLs |
| 3 | Eager vs lazy variant generation? | (A) Eager, (B) Lazy, (C) Hybrid | **(C)** — thumbnails eager, other sizes lazy |
| 4 | Quota reservation model? | (A) Pessimistic lock, (B) Atomic SQL update, (C) Optimistic with rollback | **(B)** — simple, race-free |
| 5 | Consumer metadata in storage? | (A) Storage stores all (download_count, comment), (B) Only generic metadata, consumers own extras | **(B)** — storage is infrastructure, consumers own domain data |
| 6 | Orphan TTL? | 1h / 6h / 24h / 48h | **24h** — matches plupload, allows long editing sessions |
| 7 | Directory sharding? | (A) Flat (legacy), (B) 2-char prefix from UUID, (C) Date-based | **(B)** — 256 dirs, good distribution, simple |
| 8 | File server endpoint structure? | (A) Single endpoint, (B) Separate per consumer type | **(A)** — single `/file/{id}` with mode parameter |

### Needs Further Research

| # | Question | Reason |
|---|----------|--------|
| 1 | How to handle existing `phpbb_attachments` columns like `download_count` and `attach_comment`? | These are attachment-domain data, not storage data. AttachmentPlugin may need its own table. |
| 2 | Should group avatars use the same storage service? | Groups have avatar columns too. Need to confirm owner_type model for groups. |
| 3 | Chunked upload integration? | Plupload creates multi-chunk temp files. Storage service needs to handle chunk assembly or delegate to middleware. |
| 4 | How does extension-group restriction per forum integrate? | This is business logic for AttachmentPlugin, not storage. But storage needs a hook for custom validation rules. |

---

## Appendix A: DB Schema Inventory

### Existing Tables (Storage-Related)

| Table | Columns | Indexes | Purpose |
|-------|---------|---------|---------|
| `phpbb_attachments` | 15 | 5 | Attachment metadata |
| `phpbb_extension_groups` | 9 | 1 (PK) | Extension group settings |
| `phpbb_extensions` | 3 | 1 (PK) | Extension → group mapping |
| `phpbb_users` (avatar cols) | 4 avatar cols | — | User avatar metadata |
| `phpbb_groups` (avatar cols) | 4 avatar cols | — | Group avatar metadata |
| `phpbb_config` | 3 | 2 | 50+ storage/upload config keys |

### Proposed New Tables

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `phpbb_stored_files` | Unified file metadata | id, physical_name, mime_type, size, adapter, storage_path, owner_id, owner_type, owner_ref, claimed_at |
| `phpbb_file_variants` | Variant metadata | id, file_id (FK), variant_type, physical_name, size, width, height |
| `phpbb_storage_quotas` | Multi-level quotas | scope_type, scope_id, limit_bytes, used_bytes |

---

## Appendix B: Config Key Inventory

### Attachment Config (50+ keys)

| Category | Keys | Count |
|----------|------|-------|
| Feature flags | `allow_attachments`, `allow_pm_attach` | 2 |
| Upload limits | `max_filesize`, `max_filesize_pm`, `max_attachments`, `max_attachments_pm`, `attachment_quota` | 5 |
| Paths | `upload_path`, `upload_icons_path` | 2 |
| Image/thumbnail | `img_create_thumbnail`, `img_display_inlined`, `img_max_height`, `img_max_width`, `img_link_height`, `img_link_width`, `img_max_thumb_width`, `img_min_thumb_filesize`, `img_quality`, `img_strip_metadata` | 10 |
| Security | `check_attachment_content`, `mime_triggers`, `remote_upload_verify`, `secure_downloads`, `secure_allow_deny`, `secure_allow_empty_referer` | 6 |
| Plupload | `plupload_salt`, `plupload_last_gc` | 2 |
| Dynamic counters | `num_files`, `upload_dir_size` | 2 |

### Avatar Config (13 keys)

| Key | Default | Purpose |
|-----|---------|---------|
| `allow_avatar` | `1` | Master switch |
| `allow_avatar_upload` | `1` | Upload driver |
| `allow_avatar_local` | `0` | Gallery driver |
| `allow_avatar_remote` | `0` | Remote URL driver |
| `allow_avatar_remote_upload` | `0` | Remote upload |
| `allow_avatar_gravatar` | `0` | Gravatar driver |
| `avatar_filesize` | `6144` | Max bytes |
| `avatar_max_width` | `90` | Max px |
| `avatar_max_height` | `90` | Max px |
| `avatar_min_width` | `20` | Min px |
| `avatar_min_height` | `20` | Min px |
| `avatar_path` | `images/avatars/upload` | Storage path |
| `avatar_gallery_path` | `images/avatars/gallery` | Gallery path |
| `avatar_salt` | `8fe4...` | Filename salt |

---

## Appendix C: File Path Inventory

| What | Config Key | Default Path | Filename Pattern |
|------|-----------|-------------|------------------|
| Attachments | `upload_path` | `files/` | `{user_id}_{md5(unique_id())}` (no ext) |
| Thumbnails | (same dir) | `files/` | `thumb_{physical_filename}` |
| Uploaded avatars | `avatar_path` | `images/avatars/upload/` | `{avatar_salt}_{user_id}.{ext}` |
| Avatar gallery | `avatar_gallery_path` | `images/avatars/gallery/` | `{category}/{filename}` |
| Upload icons | `upload_icons_path` | `images/upload_icons/` | Static icons |
| Plupload temp | `upload_path` + subdir | `files/plupload/` | `{plupload_salt}_{md5(filename)}.part` |
| Ranks images | `ranks_path` | `images/ranks/` | Static images |
| Smilies | `smilies_path` | `images/smilies/` | Static images |

---

## Appendix D: Source File Inventory

### Core Upload/Storage Files

| File | LOC | Purpose |
|------|-----|---------|
| `src/phpbb/forums/files/upload.php` | ~400 | Generic upload engine |
| `src/phpbb/forums/files/filespec.php` | ~530 | File specification: naming, moving, validation |
| `src/phpbb/forums/files/factory.php` | ~60 | DI factory for file services |
| `src/phpbb/forums/files/types/form.php` | ~155 | HTML form upload handler |
| `src/phpbb/forums/files/types/remote.php` | ~230 | Remote URL upload |
| `src/phpbb/forums/files/types/local.php` | ~130 | Server-side file move |

### Attachment Files

| File | LOC | Purpose |
|------|-----|---------|
| `src/phpbb/forums/attachment/manager.php` | 107 | Facade |
| `src/phpbb/forums/attachment/upload.php` | 339 | Upload orchestration |
| `src/phpbb/forums/attachment/delete.php` | 480 | Deletion with ref counting |
| `src/phpbb/forums/attachment/resync.php` | 150 | Flag repair |

### Avatar Files

| File | LOC | Purpose |
|------|-----|---------|
| `src/phpbb/forums/avatar/manager.php` | ~400 | Driver registry |
| `src/phpbb/forums/avatar/driver/driver.php` | ~157 | Abstract base driver |
| `src/phpbb/forums/avatar/driver/upload.php` | ~340 | Upload driver |
| `src/phpbb/forums/avatar/driver/local.php` | ~200 | Gallery driver |
| `src/phpbb/forums/avatar/driver/remote.php` | ~244 | Remote URL driver |
| `src/phpbb/forums/avatar/driver/gravatar.php` | ~200 | Gravatar integration |

### Serving Files

| File | LOC | Purpose |
|------|-----|---------|
| `web/download/file.php` | ~320 | Entry point (avatar + attachment) |
| `src/phpbb/common/functions_download.php` | ~780 | Serving functions |

### Infrastructure Files

| File | LOC | Purpose |
|------|-----|---------|
| `src/phpbb/forums/filesystem/filesystem.php` | ~400 | Symfony Filesystem wrapper |
| `src/phpbb/forums/mimetype/guesser.php` | ~150 | MIME detection chain |
| `src/phpbb/forums/mimetype/content_guesser.php` | ~50 | Content-based MIME |
| `src/phpbb/forums/mimetype/extension_guesser.php` | ~470 | Extension-based MIME map |
| `src/phpbb/forums/plupload/plupload.php` | ~470 | Chunked upload support |
| `src/phpbb/common/functions_posting.php` | ~300 (thumbnails) | Thumbnail generation (GD) |
